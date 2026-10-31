<?php
declare(strict_types=1);
if(getenv('GITHUB_ACTIONS')!=='true'||getenv('CI_SNAPSHOT_TEST')!=='yes'
    ||getenv('CI_DB_HOST')!=='127.0.0.1'||getenv('CI_DB_NAME')!=='auction_test') {
    throw new RuntimeException('Only the disposable auction_test fixture is permitted');
}
require dirname(__DIR__).'/private/bootstrap.php';
require dirname(__DIR__).'/private/snapshots.php';
require dirname(__DIR__).'/private/restore_mixed_diagnosis.php';
require dirname(__DIR__).'/private/catalog_reset.php';
if(cfg('setup_key')!=='ci-test-only-nonproduction'||(cfg('database')['host']??'')!=='127.0.0.1')throw new RuntimeException('Refusing non-fixture configuration');
$pdo=db();
if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='auction_test')throw new RuntimeException('Unexpected database');
$checks=0;$names=[];
function sg_check(bool $yes,string $message): void {global $checks;if(!$yes)throw new RuntimeException($message);$checks++;}
function sg_throws(callable $fn,string $message): void {try{$fn();}catch(Throwable $e){sg_check(true,$message);return;}throw new RuntimeException($message);}
function sg_payload(PDO $pdo): array {$pdo->beginTransaction();try{$data=visitor_geo_snapshot_export($pdo);$pdo->commit();return $data;}catch(Throwable $e){$pdo->rollBack();throw $e;}}
try {
    sg_check(visitor_geo_state($pdo)===null,'Initial pre-upgrade fixture has no geo schema');
    $legacy=snap_create($pdo);$names[]=$legacy;
    foreach(['database/visitor_stats.sql','database/visitor_stats_aggregates.sql','database/migrations/20261029_visitor_geo_analytics.sql'] as $path) {
        $sql=preg_replace('/^\s*--[^\n]*$/m','',(string)file_get_contents(dirname(__DIR__).'/'.$path));
        foreach(explode(';',(string)$sql) as $statement)if(trim($statement)!=='')$pdo->exec($statement);
    }
    $day=gmdate('Y-m-d');$binary=hex2bin(str_repeat('ff',32));
    $pdo->prepare('UPDATE visitor_geo_state SET start_date=?')->execute([$day]);
    $pdo->prepare("INSERT INTO visitor_stats_daily VALUES (?,?,'home',0,2)")->execute([$day,$binary]);
    $pdo->prepare("INSERT INTO visitor_geo_daily VALUES (?,?,'BE','VOV','Lokeren',2)")->execute([$day,$binary]);
    $expected=sg_payload($pdo);$digest=visitor_geo_snapshot_digest($expected);
    $name=snap_create($pdo);$names[]=$name;
    [$snapshot]=snap_verified($name);
    sg_check($snapshot['format']==='41lokeren-v2-geo','Migrated snapshots use explicit geo format');
    sg_check($snapshot['analytics']['tables']['visitor_stats_daily'][0]['visitor_id']===str_repeat('ff',32),'Binary non-UTF8 identifier survives JSON as hex');
    sg_check(visitor_geo_snapshot_digest($snapshot['analytics'])===$digest,'All analytics and state appear in snapshot');
    sg_check(recovery_compare_database($pdo,$snapshot)['matches_target'],'Recovery comparison includes complete initial state');

    $pdo->exec('UPDATE visitor_geo_daily SET views=3');
    $result=recovery_compare_database($pdo,$snapshot);
    sg_check(!$result['matches_target']&&in_array('analytics',$result['different_tables'],true),'Recovery refuses changed geo state even when core tables match');
    $mixed=recovery_mixed_db_view($pdo,$snapshot,$snapshot);
    sg_check($mixed['table_states']['analytics']==='neither'&&!$mixed['all_target'],'Mixed diagnosis includes analytics fingerprint');
    $pdo->exec('DELETE FROM visitor_geo_daily');$pdo->exec('DELETE FROM visitor_stats_daily');$pdo->exec('UPDATE visitor_geo_state SET start_date=NULL');
    snap_restore($pdo,$name);
    sg_check(visitor_geo_snapshot_digest(sg_payload($pdo))===$digest,'Full restore round-trips stats, geo, binary IDs and start date');
    sg_check(!restore_journal_pending(),'Successful geo restore clears journal through existing gates');
    sg_throws(fn()=>snap_restore($pdo,$legacy),'Old pre-geo snapshot is clearly rejected after migration');
    sg_check(!restore_journal_pending()&&visitor_geo_snapshot_digest(sg_payload($pdo))===$digest,'Legacy rejection occurs before any mutation');

    // Recompute the checksums to prove schema validation, not only SHA checking.
    $bad='snapshot-20990102-000000-'.bin2hex(random_bytes(6)).'.zip';$names[]=$bad;
    copy(snap_path($name),snap_path($bad));$zip=new ZipArchive();$zip->open(snap_path($bad));
    $data=json_decode($zip->getFromName('database.json'),true,512,JSON_THROW_ON_ERROR);
    $data['analytics']['tables']['visitor_geo_daily'][0]['visitor_id']='not-a-binary-id';
    $json=json_encode($data,JSON_THROW_ON_ERROR);
    $manifest=json_decode($zip->getFromName('manifest.json'),true,512,JSON_THROW_ON_ERROR);
    $manifest['sha256']['database.json']=hash('sha256',$json);
    $zip->addFromString('database.json',$json);$zip->addFromString('manifest.json',json_encode($manifest,JSON_THROW_ON_ERROR));$zip->close();
    sg_throws(fn()=>snap_verified($bad),'Malformed analytics are rejected even with recomputed checksums');
    sg_throws(fn()=>snap_restore($pdo,$bad),'Malformed analytics cannot enter restore');
    sg_check(!restore_journal_pending(),'Invalid analytics do not swap photos or publish a journal');

    // A normal catalog reset currently preserves campaign stats; keep BOTH sets.
    catalog_data_lock($pdo,0);
    try{reset_catalog_lots($pdo);}finally{catalog_data_unlock($pdo);}
    sg_check(visitor_geo_snapshot_digest(sg_payload($pdo))===$digest,'Catalog lot reset preserves normal and geo campaign stats consistently');
    snap_restore($pdo,$name);
} finally {
    foreach($names as $name)if(snap_name($name)&&is_file(snap_path($name)))unlink(snap_path($name));
    // Leave no geo schema behind for unrelated snapshot regressions.
    if(!$pdo->inTransaction()&&!restore_journal_pending())foreach(array_keys(GEO_SNAPSHOT_COLUMNS) as $table)$pdo->exec('DROP TABLE IF EXISTS '.$table);
}
echo "PASS $checks geographic snapshot/restore and recovery checks\n";
