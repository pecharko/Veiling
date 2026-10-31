<?php
declare(strict_types=1);
$dsn=(string)getenv('ANALYTICS_TEST_DSN');
if ($dsn!=='mysql:host=127.0.0.1;port=3306;dbname=visitor_stats_ci;charset=utf8mb4') { fwrite(STDERR,"Only disposable localhost CI is permitted\n");exit(2); }
function lifecycle_db(): PDO {
    global $dsn;
    return new PDO($dsn,(string)getenv('ANALYTICS_TEST_USER'),(string)getenv('ANALYTICS_TEST_PASSWORD'),[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
}
$pdo=lifecycle_db();$peer=lifecycle_db();$secret=str_repeat('a',64);
function cfg(string $key): mixed { global $secret;return $key==='analytics_secret'?$secret:null; }
function db(): PDO { global $pdo;return $pdo; }
require dirname(__DIR__).'/private/visitor_stats.php';
require dirname(__DIR__).'/private/visitor_stats_archive_one_day_experimental.php';
require dirname(__DIR__).'/private/visitor_stats_archive.php';
require dirname(__DIR__).'/private/visitor_geo_dashboard.php';
require dirname(__DIR__).'/private/visitor_geo_delete.php';
require dirname(__DIR__).'/private/visitor_geo_snapshot.php';
$checks=0;
function lc_check(bool $yes,string $message): void { global $checks;if (!$yes) throw new RuntimeException($message);$checks++; }
function lc_throws(callable $fn,string $message): void { try{$fn();}catch(Throwable $e){lc_check(true,$message);return;}throw new RuntimeException($message); }
function lc_sum(PDO $pdo,string $table): int { return (int)$pdo->query('SELECT COALESCE(SUM(views),0) FROM '.$table)->fetchColumn(); }
foreach (array_keys(GEO_SNAPSHOT_COLUMNS) as $table) $pdo->exec('DROP TABLE IF EXISTS '.$table);
function lc_install(PDO $pdo,string $path): void {
    $sql=preg_replace('/^\s*--[^\n]*$/m','',(string)file_get_contents(dirname(__DIR__).'/'.$path));
    foreach(explode(';',(string)$sql) as $statement) if(trim($statement)!=='')$pdo->exec($statement);
}
lc_install($pdo,'database/visitor_stats.sql');lc_install($pdo,'database/visitor_stats_aggregates.sql');
$_SERVER=['REQUEST_METHOD'=>'GET','REMOTE_ADDR'=>'192.0.2.10','HTTP_USER_AGENT'=>'Geo lifecycle browser'];
lc_check(visitor_geo_state($pdo)===null,'No migration means disabled');
track_catalog_view();lc_check(lc_sum($pdo,'visitor_stats_daily')===1,'Legacy tracking without geo schema still works');
lc_install($pdo,'database/migrations/20261029_visitor_geo_analytics.sql');
lc_check(visitor_geo_state($pdo)===['start_date'=>null],'Migration is OFF by default');
track_catalog_view();lc_check(lc_sum($pdo,'visitor_stats_daily')===2 && lc_sum($pdo,'visitor_geo_daily')===0,'Disabled migration leaves normal tracking intact');
$today=gmdate('Y-m-d');$tomorrow=gmdate('Y-m-d',time()+86400);
$pdo->prepare('UPDATE visitor_geo_state SET start_date=?')->execute([$tomorrow]);
track_catalog_view();lc_check(lc_sum($pdo,'visitor_geo_daily')===0,'Future start cannot collect early');
$pdo->prepare('UPDATE visitor_geo_state SET start_date=?')->execute([$today]);
track_catalog_view();
lc_check(lc_sum($pdo,'visitor_geo_daily')===1,'Active tracker writes unknown location without an MMDB');
lc_check(visitor_geo_dashboard($pdo)['status']==='partial','Partial activation-day coverage is not presented as complete');
$pdo->exec('DELETE FROM visitor_stats_daily');$pdo->exec('DELETE FROM visitor_geo_daily');
track_catalog_view();track_catalog_view(7);
lc_check((int)$pdo->query('SELECT COUNT(*) FROM visitor_geo_daily')->fetchColumn()===1 && lc_sum($pdo,'visitor_geo_daily')===2,'Actual tracker deduplicates visitor across home and lot');
$_SERVER['HTTP_X_FORWARDED_FOR']='8.8.8.8';track_catalog_view();
lc_check((int)$pdo->query('SELECT COUNT(*) FROM visitor_geo_daily')->fetchColumn()===1,'Untrusted forwarded IP does not change identifier');
$before=lc_sum($pdo,'visitor_stats_daily');
foreach (['dnt','bot','post','empty','secret'] as $skip) {
    $saved=$_SERVER;
    if($skip==='dnt')$_SERVER['HTTP_DNT']='1';
    if($skip==='bot')$_SERVER['HTTP_USER_AGENT']='somebot';
    if($skip==='post')$_SERVER['REQUEST_METHOD']='POST';
    if($skip==='empty')$_SERVER['HTTP_USER_AGENT']='';
    if($skip==='secret')$secret='';
    track_catalog_view();$_SERVER=$saved;$secret=str_repeat('a',64);
    lc_check(lc_sum($pdo,'visitor_stats_daily')===$before && lc_sum($pdo,'visitor_geo_daily')===$before,'Existing suppression covers both counters: '.$skip);
}
if(is_file(analytics_disabled_marker()))throw new RuntimeException('Refusing to alter an existing analytics-disabled marker');
set_analytics_collection_enabled(false);
try{track_catalog_view();lc_check(lc_sum($pdo,'visitor_geo_daily')===$before,'Global off switch covers geo');}finally{set_analytics_collection_enabled(true);}
$pdo->exec('ALTER TABLE visitor_geo_daily ADD CONSTRAINT lc_reject CHECK (views<=3)');
try{track_catalog_view();lc_check(lc_sum($pdo,'visitor_stats_daily')===$before,'Failed geo write rolls back actual normal tracker write');}finally{$pdo->exec('ALTER TABLE visitor_geo_daily DROP CHECK lc_reject');}
$pdo->beginTransaction();track_catalog_view();lc_check($pdo->inTransaction() && lc_sum($pdo,'visitor_stats_daily')===$before,'Tracker does not take ownership of caller transaction');$pdo->rollBack();
catalog_data_lock($peer,0);
try{track_catalog_view();lc_check(lc_sum($pdo,'visitor_geo_daily')===$before,'Snapshot mutex prevents concurrent tracker mutation');}finally{catalog_data_unlock($peer);}
lc_check(visitor_geo_dashboard($pdo)['status']==='ready','Consistent current data has complete coverage');

// Add historical fixtures in a caller-owned transaction, as the real tracker does.
function lc_historical(PDO $pdo,string $day,string $id,bool $geo=true): void {
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO visitor_stats_daily VALUES (?,?,'home',0,1)")->execute([$day,$id]);
        if($geo)visitor_geo_record_view($pdo,$day,$id,['country_code'=>'BE','region_code'=>'VOV','city_name'=>'Lokeren']);
        $pdo->commit();
    }catch(Throwable $e){$pdo->rollBack();throw $e;}
}
$old=gmdate('Y-m-d',strtotime('-45 days'));$geoDay=gmdate('Y-m-d',strtotime('-5 days'));$cutoff=gmdate('Y-m-d',strtotime('-3 days'));
$start=gmdate('Y-m-d',strtotime('-10 days'));$pdo->prepare('UPDATE visitor_geo_state SET start_date=?')->execute([$start]);
lc_historical($pdo,$old,str_repeat('o',32),false);lc_historical($pdo,$geoDay,str_repeat('g',32));
lc_check(archive_visitor_stats_one_day($pdo,$cutoff)['rows']===1,'Manual/cron parent archives pre-geo day normally');
lc_check((int)$pdo->query('SELECT COUNT(*) FROM visitor_geo_day_totals')->fetchColumn()===0,'Historical locations are never invented');
$report=visitor_geo_report($pdo);
lc_check(archive_visitor_stats_one_day($pdo,$cutoff)['rows']===1,'Manual/cron parent archives geo and normal detail together');
lc_check(visitor_geo_report($pdo)===$report,'Integrated archive retains distribution');
$day=gmdate('Y-m-d',strtotime('-6 days'));lc_historical($pdo,$day,str_repeat('m',32));
$pdo->prepare('UPDATE visitor_geo_daily SET views=views+1 WHERE visit_date=?')->execute([$day]);
lc_throws(fn()=>archive_visitor_stats_one_day($pdo,$cutoff),'Mismatch blocks parent deletion');
$stmt=$pdo->prepare('SELECT COUNT(*) FROM visitor_stats_daily WHERE visit_date=?');$stmt->execute([$day]);
lc_check((int)$stmt->fetchColumn()===1,'Mismatch leaves normal source intact');
$pdo->prepare('UPDATE visitor_geo_daily SET views=views-1 WHERE visit_date=?')->execute([$day]);
archive_visitor_stats_one_day($pdo,$cutoff);
// The older legacy bulk entry point must use the same geo safeguards.
$pdo->prepare('UPDATE visitor_geo_state SET start_date=?')->execute([gmdate('Y-m-d',strtotime('-65 days'))]);
$bulk=gmdate('Y-m-d',strtotime('-40 days'));lc_historical($pdo,$bulk,str_repeat('k',32));
lc_check(archive_visitor_stats_before($pdo,gmdate('Y-m-d',strtotime('-30 days')))['rows']===1,'Legacy bulk entry point also archives geo safely');

// Snapshot payload is binary-safe, and delete participates in its caller rollback.
$pdo->beginTransaction();$snapshot=visitor_geo_snapshot_export($pdo);$pdo->commit();
lc_check(strlen($snapshot['tables']['visitor_geo_daily'][0]['visitor_id'])===64,'Snapshot encodes binary IDs losslessly');
$digest=visitor_geo_snapshot_digest($snapshot);
$pdo->beginTransaction();visitor_geo_delete_rows($pdo,$today);$pdo->rollBack();
$pdo->beginTransaction();lc_check(visitor_geo_snapshot_digest(visitor_geo_snapshot_export($pdo))===$digest,'Deletion rollback restores all geo rows');$pdo->commit();
$pdo->beginTransaction();$deleted=visitor_geo_delete_rows($pdo,$today);
$pdo->prepare('DELETE FROM visitor_stats_daily WHERE visit_date < ?')->execute([$today]);
$pdo->prepare('DELETE FROM visitor_stats_day_totals WHERE visit_date < ?')->execute([$today]);
$pdo->prepare('DELETE FROM visitor_stats_lot_totals WHERE visit_date < ?')->execute([$today]);$pdo->commit();
lc_check($deleted>0 && lc_sum($pdo,'visitor_geo_daily')===$before,'Cutoff deletion retains current day');
$pdo->beginTransaction();visitor_geo_delete_rows($pdo,null);$pdo->exec('DELETE FROM visitor_stats_daily');$pdo->exec('DELETE FROM visitor_stats_day_totals');$pdo->exec('DELETE FROM visitor_stats_lot_totals');$pdo->commit();
lc_check(visitor_geo_dashboard($pdo)['rows']===[],'Delete all leaves honest empty report');
lc_check(visitor_geo_state($pdo)['start_date']!==null,'Deletion preserves approved coverage configuration');
// New activation cannot be inferred from missing or partial migration state.
$pdo->exec('DROP TABLE visitor_geo_state');
lc_throws(fn()=>visitor_geo_state($pdo),'Partial migration is fail-closed');
echo "PASS $checks integrated geographic lifecycle checks\n";
