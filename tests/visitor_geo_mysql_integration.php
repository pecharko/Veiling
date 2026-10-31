<?php
declare(strict_types=1);
require dirname(__DIR__) . '/private/visitor_geo_record.php';
require dirname(__DIR__) . '/private/visitor_geo_archive.php';
require dirname(__DIR__) . '/private/visitor_geo_report.php';

// No application config is loaded. Refuse every database except this local CI schema.
$dsn = (string)getenv('ANALYTICS_TEST_DSN');
if ($dsn !== 'mysql:host=127.0.0.1;port=3306;dbname=visitor_stats_ci;charset=utf8mb4') {
    fwrite(STDERR, "STOP: only disposable localhost visitor_stats_ci is permitted\n"); exit(2);
}
function geo_connection(): PDO {
    global $dsn;
    return new PDO($dsn, (string)getenv('ANALYTICS_TEST_USER'), (string)getenv('ANALYTICS_TEST_PASSWORD'), [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    ]);
}
$checks = 0;
function geo_check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function geo_refuses(callable $action, string $message, string $class = Throwable::class): void {
    try { $action(); } catch (Throwable $e) {
        if (!$e instanceof $class) throw $e;
        geo_check(true, $message); return;
    }
    throw new RuntimeException($message);
}
function geo_normal(PDO $pdo, string $day, string $id, int $lot = 0): void {
    $pdo->prepare('INSERT INTO visitor_stats_daily VALUES (?,?,?,?,1) ON DUPLICATE KEY UPDATE views=views+1')
        ->execute([$day, $id, $lot ? 'lot' : 'home', $lot]);
}
function geo_view(PDO $pdo, string $day, string $id, array $geo, int $lot = 0): void {
    $pdo->beginTransaction();
    try {
        geo_normal($pdo, $day, $id, $lot);
        visitor_geo_record_view($pdo, $day, $id, $geo);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
$pdo = geo_connection();
$other = geo_connection();
geo_check(str_starts_with((string)$pdo->query('SELECT VERSION()')->fetchColumn(), '8.4.'), 'Requires MySQL 8.4');
foreach (['visitor_geo_day_totals','visitor_geo_daily','visitor_stats_daily'] as $table) {
    $pdo->exec('DROP TABLE IF EXISTS ' . $table);
}
foreach (['database/visitor_stats.sql','database/migrations/20261029_visitor_geo_analytics.sql'] as $path) {
    $sql = preg_replace('/^\s*--[^\n]*$/m', '', (string)file_get_contents(dirname(__DIR__) . '/' . $path));
    foreach (explode(';', (string)$sql) as $statement) if (trim($statement) !== '') $pdo->exec($statement);
}
$today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
$day = $today->modify('-5 days')->format('Y-m-d');
$a = str_repeat('a', 32); $b = str_repeat('b', 32);
$be = ['country_code'=>'BE','region_code'=>'VOV','city_name'=>'Lokeren'];
$unknown = ['country_code'=>'ZZ','region_code'=>'','city_name'=>''];
geo_view($pdo,$day,$a,$be);
geo_view($pdo,$day,$a,$unknown,7);
geo_view($pdo,$day,$a,$be,7);
geo_view($pdo,$day,$b,$unknown);
$row = $pdo->query("SELECT * FROM visitor_geo_daily WHERE country_code='BE'")->fetch();
geo_check((int)$row['views']===3 && $row['city_name']==='Lokeren', 'Repeat views preserve first daily bucket');
geo_check((int)$pdo->query('SELECT COUNT(*) FROM visitor_geo_daily')->fetchColumn()===2, 'One row per visitor-day across home/lot');
geo_check((int)$pdo->query('SELECT SUM(views) FROM visitor_stats_daily')->fetchColumn()===4, 'Existing counters preserved');

// Roll back the normal insert as well when the geo write fails.
$before = $pdo->query('SELECT SUM(views) FROM visitor_stats_daily')->fetchColumn();
$pdo->exec("CREATE TRIGGER geo_test_reject BEFORE INSERT ON visitor_geo_daily FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected geo failure'");
try { geo_refuses(fn()=>geo_view($pdo,$day,$a,$be), 'SQL failure must propagate', PDOException::class); }
finally { $pdo->exec('DROP TRIGGER geo_test_reject'); }
geo_check($pdo->query('SELECT SUM(views) FROM visitor_stats_daily')->fetchColumn()===$before, 'Normal write rolled back with failed geo write');
geo_check((int)$pdo->query('SELECT SUM(views) FROM visitor_geo_daily')->fetchColumn()===4, 'Failed geo write left no partial count');

// Two independent connections contend on one visitor, then safely retry as an ordinary new view.
$other->exec('SET SESSION innodb_lock_wait_timeout=1');
$pdo->beginTransaction();
geo_normal($pdo,$day,$a); visitor_geo_record_view($pdo,$day,$a,$be);
geo_refuses(fn()=>geo_view($other,$day,$a,$be), 'Concurrent write must wait rather than lose increments', PDOException::class);
$pdo->commit();
geo_view($other,$day,$a,$be);
geo_check((int)$pdo->query('SELECT SUM(views) FROM visitor_geo_daily')->fetchColumn()===6, 'Concurrent successful views counted exactly once each');

// A transaction without the shared parent lock cannot archive.
$pdo->beginTransaction();
geo_refuses(fn()=>visitor_geo_archive_day($pdo,$day), 'Archive without named lock rejected', LogicException::class);
$pdo->rollBack();
geo_check((int)$pdo->query("SELECT GET_LOCK('visitor_stats_one_day_archive',0)")->fetchColumn()===1, 'Acquire shared archive lock');
try {
    // Establish an old consistent snapshot, then commit another complete view.
    $pdo->beginTransaction();
    $pdo->query('SELECT COUNT(*) FROM visitor_geo_daily')->fetchColumn();
    geo_view($other,$day,$a,$be);
    visitor_geo_assert_day_consistent($pdo,$day);
    $result = visitor_geo_archive_day($pdo,$day);
    geo_check($result===['visitors'=>2,'views'=>7], 'Archive uses current locked rows rather than stale snapshot');
    geo_check((int)$other->query('SELECT COUNT(*) FROM visitor_geo_daily')->fetchColumn()===2, 'Other connection still sees source before commit');
    // Geo range lock must also block a new visitor, not only existing IDs.
    geo_refuses(fn()=>geo_view($other,$day,str_repeat('c',32),$be), 'Archive range lock blocks concurrent insertion', PDOException::class);
    $pdo->rollBack();
    geo_check((int)$pdo->query('SELECT COUNT(*) FROM visitor_geo_daily')->fetchColumn()===2, 'Archive rollback preserves source');
    geo_check((int)$pdo->query('SELECT COUNT(*) FROM visitor_geo_day_totals')->fetchColumn()===0, 'Archive rollback removes partial totals');

    // A different ID with matching totals must still be rejected.
    $other->prepare('UPDATE visitor_geo_daily SET visitor_id=? WHERE visitor_id=?')->execute([str_repeat('z',32),$b]);
    $pdo->beginTransaction();
    geo_refuses(fn()=>visitor_geo_archive_day($pdo,$day), 'Mismatched IDs block archive', RuntimeException::class);
    $pdo->rollBack();
    $other->prepare('UPDATE visitor_geo_daily SET visitor_id=? WHERE visitor_id=?')->execute([$b,str_repeat('z',32)]);
    $other->prepare('UPDATE visitor_geo_daily SET views=views+1 WHERE visitor_id=?')->execute([$b]);
    $pdo->beginTransaction();
    geo_refuses(fn()=>visitor_geo_archive_day($pdo,$day), 'Mismatched visitor views block archive', RuntimeException::class);
    $pdo->rollBack();
    $other->prepare('UPDATE visitor_geo_daily SET views=views-1 WHERE visitor_id=?')->execute([$b]);

    // Existing aggregate prevents overwriting historical records.
    $pdo->prepare('INSERT INTO visitor_geo_day_totals VALUES (?,?,?,?,?,?)')->execute([$day,'BE','VOV','Lokeren',1,99]);
    $pdo->beginTransaction();
    geo_refuses(fn()=>visitor_geo_archive_day($pdo,$day), 'Existing aggregate is never overwritten', RuntimeException::class);
    $pdo->rollBack();
    geo_check((int)$pdo->query('SELECT SUM(views) FROM visitor_geo_day_totals')->fetchColumn()===99, 'Conflicting aggregate preserved');
    $pdo->exec('DELETE FROM visitor_geo_day_totals');

    // A trigger corrupting aggregate counts must prevent deletion of detail.
    $pdo->exec('CREATE TRIGGER geo_test_corrupt BEFORE INSERT ON visitor_geo_day_totals FOR EACH ROW SET NEW.views=NEW.views+1');
    $pdo->beginTransaction();
    try { geo_refuses(fn()=>visitor_geo_archive_day($pdo,$day), 'Aggregate verification precedes deletion', RuntimeException::class); }
    finally { $pdo->rollBack(); $pdo->exec('DROP TRIGGER geo_test_corrupt'); }
    geo_check((int)$pdo->query('SELECT COUNT(*) FROM visitor_geo_daily')->fetchColumn()===2, 'Bad aggregation preserves all detail');

    $beforeReport = visitor_geo_report($pdo);
    $pdo->beginTransaction();
    $result = visitor_geo_archive_day($pdo,$day);
    // Simulate parent archive deleting its own detail within this same transaction.
    $pdo->prepare('DELETE FROM visitor_stats_daily WHERE visit_date=?')->execute([$day]);
    $pdo->commit();
    geo_check(visitor_geo_report($pdo)===$beforeReport, 'Distribution identical before and after archive');
    $pdo->beginTransaction();
    geo_check(visitor_geo_archive_day($pdo,$day)===['visitors'=>0,'views'=>0], 'Repeated completed archive does not double count');
    $pdo->commit();
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $pdo->query("SELECT RELEASE_LOCK('visitor_stats_one_day_archive')")->fetchColumn();
}

// UTC date bounds must not depend on PHP server timezone, or include future days.
geo_view($pdo,$today->format('Y-m-d'),$a,$be);
geo_view($pdo,$today->modify('+1 day')->format('Y-m-d'),$a,$be);
geo_view($pdo,$today->modify('-30 days')->format('Y-m-d'),$a,$be);
date_default_timezone_set('Pacific/Kiritimati');
$report = visitor_geo_report($pdo,1);
geo_check(count($report)===1 && (int)$report[0]['visitor_days']===1 && (int)$report[0]['views']===1, 'Report includes exactly current UTC day');
$countries = array_column(visitor_geo_percentages(visitor_geo_group(visitor_geo_report($pdo,30),'country')), 'percentage','label');
geo_check($countries===['BE'=>66.7,'ZZ'=>33.3], 'Country denominator includes unknown, excludes out-of-range days');
geo_check(visitor_geo_belgian_percentages(visitor_geo_report($pdo,30),'region')[0]['percentage']===100.0, 'Belgian denominator excludes foreign and unknown country');
geo_refuses(fn()=>visitor_geo_report($pdo,0), 'Invalid report range rejected', InvalidArgumentException::class);
echo "PASS $checks geographic MySQL 8.4 integration checks\n";
