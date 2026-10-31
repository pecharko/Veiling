<?php
declare(strict_types=1);

require_once __DIR__ . '/visitor_geo_archive_guard.php';

/**
 * Aggregate a single UTC day of geo analytics inside the caller's transaction.
 * Caller MUST hold the existing visitor-statistics archive lock.
 * Both parent archive entry points call this on/after the approved coverage
 * start, in the same transaction as their normal aggregate/delete operations.
 */
function visitor_geo_archive_day(PDO $pdo, string $day): array
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day, new DateTimeZone('UTC'));
    if (!$date || $date->format('Y-m-d') !== $day) {
        throw new InvalidArgumentException('Invalid geo archive date');
    }
    if (!$pdo->inTransaction()) {
        throw new LogicException('Geo archive requires an existing transaction');
    }
    visitor_geo_assert_day_consistent($pdo, $day);
    $rows = visitor_geo_lock_day($pdo, 'visitor_geo_daily', $day);
    $visitors = count($rows);
    $views = array_sum(array_column($rows, 'views'));
    if ($visitors === 0) {
        return ['visitors'=>0, 'views'=>0];
    }

    // No overwrite: repeated invocation for an already archived date must abort
    // rather than double-count or silently replace partial historical totals.
    if (visitor_geo_lock_day($pdo, 'visitor_geo_day_totals', $day) !== []) {
        throw new RuntimeException('Geo day already has archived totals');
    }

    $insert = $pdo->prepare('INSERT INTO visitor_geo_day_totals (visit_date,country_code,region_code,city_name,visitor_days,views)
        SELECT visit_date,country_code,region_code,city_name,COUNT(*),SUM(views)
        FROM visitor_geo_daily WHERE visit_date=?
        GROUP BY visit_date,country_code,region_code,city_name');
    $insert->execute([$day]);

    $actual = visitor_geo_lock_day($pdo, 'visitor_geo_day_totals', $day);
    if ((int)array_sum(array_column($actual, 'visitor_days')) !== $visitors
        || (int)array_sum(array_column($actual, 'views')) !== $views) {
        throw new RuntimeException('Geo aggregate verification failed');
    }

    $delete = $pdo->prepare('DELETE FROM visitor_geo_daily WHERE visit_date=?');
    $delete->execute([$day]);
    if ($delete->rowCount() !== $visitors) {
        throw new RuntimeException('Geo archive source changed');
    }
    return ['visitors'=>$visitors, 'views'=>$views];
}
