<?php
declare(strict_types=1);

/**
 * Aggregate a single UTC day of geo analytics inside the caller's transaction.
 * Caller MUST hold the existing visitor-statistics archive lock.
 * This helper is intentionally not wired to the active cron until collection,
 * migration and snapshot/restore have been reviewed together.
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
    $totals = $pdo->prepare('SELECT COUNT(*) AS visitors, COALESCE(SUM(views),0) AS views FROM visitor_geo_daily WHERE visit_date=?');
    $totals->execute([$day]);
    $expected = $totals->fetch(PDO::FETCH_ASSOC);
    $visitors = (int)$expected['visitors'];
    $views = (int)$expected['views'];
    if ($visitors === 0) {
        return ['visitors'=>0, 'views'=>0];
    }

    // No overwrite: repeated invocation for an already archived date must abort
    // rather than double-count or silently replace partial historical totals.
    $existing = $pdo->prepare('SELECT COUNT(*) FROM visitor_geo_day_totals WHERE visit_date=?');
    $existing->execute([$day]);
    if ((int)$existing->fetchColumn() !== 0) {
        throw new RuntimeException('Geo day already has archived totals');
    }

    $insert = $pdo->prepare('INSERT INTO visitor_geo_day_totals (visit_date,country_code,region_code,city_name,visitor_days,views)
        SELECT visit_date,country_code,region_code,city_name,COUNT(*),SUM(views)
        FROM visitor_geo_daily WHERE visit_date=?
        GROUP BY visit_date,country_code,region_code,city_name');
    $insert->execute([$day]);

    $verify = $pdo->prepare('SELECT COALESCE(SUM(visitor_days),0) AS visitors, COALESCE(SUM(views),0) AS views
        FROM visitor_geo_day_totals WHERE visit_date=?');
    $verify->execute([$day]);
    $actual = $verify->fetch(PDO::FETCH_ASSOC);
    if ((int)$actual['visitors'] !== $visitors || (int)$actual['views'] !== $views) {
        throw new RuntimeException('Geo aggregate verification failed');
    }

    $delete = $pdo->prepare('DELETE FROM visitor_geo_daily WHERE visit_date=?');
    $delete->execute([$day]);
    if ($delete->rowCount() !== $visitors) {
        throw new RuntimeException('Geo archive source changed');
    }
    return ['visitors'=>$visitors, 'views'=>$views];
}
