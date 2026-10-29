<?php
declare(strict_types=1);

/**
 * Fail-closed pre-archive consistency check for a single UTC day.
 *
 * Run only while the parent archive owns its connection-scoped archive lock,
 * has locked the relevant visitor_stats_daily rows, and is inside the same
 * transaction. This helper does not delete or archive data on its own.
 *
 * Geo collection must be active for every eligible public view before this
 * can be enabled. Legacy pre-geo days must be handled in a separately
 * reviewed migration/activation plan.
 */
function visitor_geo_assert_day_consistent(PDO $pdo, string $day): void
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day, new DateTimeZone('UTC'));
    if (!$date || $date->format('Y-m-d') !== $day) {
        throw new InvalidArgumentException('Invalid UTC archive day');
    }
    if (!$pdo->inTransaction()) {
        throw new LogicException('Geo archive verification requires a transaction');
    }
    $stats = $pdo->prepare('SELECT COUNT(DISTINCT visitor_id) AS visitor_days, COALESCE(SUM(views),0) AS views FROM visitor_stats_daily WHERE visit_date=?');
    $stats->execute([$day]);
    $expected = $stats->fetch(PDO::FETCH_ASSOC);
    $geo = $pdo->prepare('SELECT COUNT(*) AS visitor_days, COALESCE(SUM(views),0) AS views FROM visitor_geo_daily WHERE visit_date=?');
    $geo->execute([$day]);
    $actual = $geo->fetch(PDO::FETCH_ASSOC);
    // Aggregate equality alone is insufficient: two different visitor IDs can
    // produce the same counts and views. Check both directions before deletion.
    $missingGeo = $pdo->prepare('SELECT COUNT(*) FROM visitor_stats_daily s
        LEFT JOIN visitor_geo_daily g ON g.visit_date=s.visit_date AND g.visitor_id=s.visitor_id
        WHERE s.visit_date=? AND g.visitor_id IS NULL');
    $missingGeo->execute([$day]);
    $missingStats = $pdo->prepare('SELECT COUNT(*) FROM visitor_geo_daily g
        LEFT JOIN visitor_stats_daily s ON s.visit_date=g.visit_date AND s.visitor_id=g.visitor_id
        WHERE g.visit_date=? AND s.visitor_id IS NULL');
    $missingStats->execute([$day]);
    if ((int)$missingGeo->fetchColumn() !== 0 || (int)$missingStats->fetchColumn() !== 0) {
        throw new RuntimeException('Geo visitor identifiers disagree; archive blocked');
    }
    // Matching overall views can still conceal a swap between two visitors.
    // Compare each visitor's summed normal page views against its geo count.
    $mismatch = $pdo->prepare('SELECT COUNT(*) FROM (
        SELECT visitor_id, SUM(views) AS views
        FROM visitor_stats_daily WHERE visit_date=? GROUP BY visitor_id
    ) s INNER JOIN visitor_geo_daily g
       ON g.visitor_id=s.visitor_id AND g.visit_date=?
       WHERE s.views <> g.views');
    $mismatch->execute([$day, $day]);
    if ((int)$mismatch->fetchColumn() !== 0) {
        throw new RuntimeException('Geo per-visitor views disagree; archive blocked');
    }
    if ((int)$expected['visitor_days'] !== (int)$actual['visitor_days']
        || (int)$expected['views'] !== (int)$actual['views']) {
        throw new RuntimeException('Geo and visitor-statistics day totals disagree; archive blocked');
    }
}
