<?php
declare(strict_types=1);

/**
 * Manual, admin-only archive. Database aggregates identifiers per UTC day,
 * avoiding PHP arrays of visitor identifiers. One database transaction covers
 * every selected date, so any failure rolls back all days.
 * No cron or automatic cleanup.
 */
function archive_visitor_stats_before(PDO $pdo, string $cutoff): array
{
    $limit = gmdate('Y-m-d', strtotime('-30 days'));
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $cutoff, new DateTimeZone('UTC'));
    if (!$parsed || $parsed->format('Y-m-d') !== $cutoff || $cutoff > $limit) {
        throw new InvalidArgumentException('Grensdatum moet ten minste 30 UTC-dagen geleden zijn.');
    }
    $pdo->beginTransaction();
    try {
        // Distinct dates are small and bounded by the number of calendar days,
        // rather than by the number of individual visitors.
        $days = $pdo->prepare('SELECT DISTINCT visit_date FROM visitor_stats_daily WHERE visit_date < ? ORDER BY visit_date');
        $days->execute([$cutoff]);
        $dates = $days->fetchAll(PDO::FETCH_COLUMN);
        $days->closeCursor();
        $rowCount = 0;
        $sourceViews = 0;
        $insertDay = $pdo->prepare("INSERT INTO visitor_stats_day_totals (visit_date, visitor_days, views, lot_views)
            SELECT visit_date, COUNT(DISTINCT visitor_id), SUM(views),
                   SUM(CASE WHEN page_kind='lot' THEN views ELSE 0 END)
              FROM visitor_stats_daily WHERE visit_date=? GROUP BY visit_date");
        $insertLots = $pdo->prepare("INSERT INTO visitor_stats_lot_totals (visit_date, lot_id, visitor_days, views)
            SELECT visit_date, lot_id, COUNT(DISTINCT visitor_id), SUM(views)
              FROM visitor_stats_daily WHERE visit_date=? AND page_kind='lot'
              GROUP BY visit_date, lot_id");
        $validate = $pdo->prepare("SELECT COUNT(*) rows_total, COALESCE(SUM(views),0) views_total,
              COALESCE(SUM(CASE WHEN page_kind NOT IN ('home','lot') OR views < 1 THEN 1 ELSE 0 END),0) bad_rows
              FROM visitor_stats_daily WHERE visit_date=? FOR UPDATE");
        $delete = $pdo->prepare('DELETE FROM visitor_stats_daily WHERE visit_date=?');
        foreach ($dates as $day) {
            $validate->execute([$day]);
            $counts = $validate->fetch(PDO::FETCH_ASSOC);
            $validate->closeCursor();
            if ((int)$counts['bad_rows'] !== 0) throw new RuntimeException('Invalid analytics source record');
            $n = (int)$counts['rows_total'];
            $views = (int)$counts['views_total'];
            if ($n === 0) continue;
            // SQL-side aggregation; no visitor ID values materialized in PHP.
            $insertDay->execute([$day]);
            if ($insertDay->rowCount() !== 1) throw new RuntimeException('Archive daily aggregate missing');
            $insertLots->execute([$day]);
            $delete->execute([$day]);
            if ($delete->rowCount() !== $n) throw new RuntimeException('Archive source row count changed');
            $rowCount += $n;
            $sourceViews += $views;
        }
        $pdo->commit();
        return ['rows'=>$rowCount, 'days'=>count($dates), 'views'=>$sourceViews];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
