<?php
declare(strict_types=1);
require dirname(__DIR__) . '/private/visitor_geo_archive_guard.php';

// No database mutation or production access: SQLite fixtures for read-only
// counts and transaction enforcement.
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP: PDO SQLite unavailable; MySQL integration remains mandatory\n";
    exit(0);
}
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE visitor_stats_daily (visit_date TEXT, visitor_id BLOB, views INTEGER)');
$db->exec('CREATE TABLE visitor_geo_daily (visit_date TEXT, visitor_id BLOB, views INTEGER)');
$day = '2026-10-29';
$visitor = str_repeat('x', 32);
$db->prepare('INSERT INTO visitor_stats_daily VALUES (?,?,?)')->execute([$day,$visitor,2]);
try {
    visitor_geo_assert_day_consistent($db,$day);
    throw new RuntimeException('Missing transaction accepted');
} catch (LogicException $expected) {}
$db->beginTransaction();
try {
    visitor_geo_assert_day_consistent($db,$day);
    throw new RuntimeException('Missing geo detail accepted');
} catch (RuntimeException $expected) {
    if ($expected->getMessage() !== 'Geo visitor identifiers disagree; archive blocked') throw $expected;
}
$db->prepare('INSERT INTO visitor_geo_daily VALUES (?,?,?)')->execute([$day,$visitor,2]);
visitor_geo_assert_day_consistent($db,$day);
// Equal totals with a different visitor identifier must still block archival.
$db->prepare('UPDATE visitor_geo_daily SET visitor_id=? WHERE visit_date=?')->execute([str_repeat('y', 32), $day]);
try {
    visitor_geo_assert_day_consistent($db,$day);
    throw new RuntimeException('Mismatched visitor IDs accepted');
} catch (RuntimeException $expected) {
    if ($expected->getMessage() !== 'Geo visitor identifiers disagree; archive blocked') throw $expected;
}
$db->prepare('UPDATE visitor_geo_daily SET visitor_id=? WHERE visit_date=?')->execute([$visitor, $day]);
$db->prepare('UPDATE visitor_geo_daily SET views=1 WHERE visit_date=?')->execute([$day]);
try {
    visitor_geo_assert_day_consistent($db,$day);
    throw new RuntimeException('Mismatched page views accepted');
} catch (RuntimeException $expected) {
    if ($expected->getMessage() !== 'Geo and visitor-statistics day totals disagree; archive blocked') throw $expected;
}
// Same daily total, but views swapped between two visitors: must fail.
$db->prepare('INSERT INTO visitor_stats_daily VALUES (?,?,?)')->execute([$day, str_repeat('z',32), 1]);
$db->prepare('INSERT INTO visitor_geo_daily VALUES (?,?,?)')->execute([$day, str_repeat('z',32), 2]);
$db->prepare('UPDATE visitor_geo_daily SET views=1 WHERE visitor_id=?')->execute([$visitor]);
try {
    visitor_geo_assert_day_consistent($db,$day);
    throw new RuntimeException('Swapped per-visitor views accepted');
} catch (RuntimeException $expected) {
    if ($expected->getMessage() !== 'Geo per-visitor views disagree; archive blocked') throw $expected;
}
$db->rollBack();
echo "Geo archive consistency guard OK\n";
