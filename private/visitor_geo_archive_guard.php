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
    if ((int)$expected['visitor_days'] !== (int)$actual['visitor_days']
        || (int)$expected['views'] !== (int)$actual['views']) {
        throw new RuntimeException('Geo and visitor-statistics day totals disagree; archive blocked');
    }
}
