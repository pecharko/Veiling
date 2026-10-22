<?php
declare(strict_types=1);

/**
 * EXPERIMENTAL: one UTC-day per explicit manual call. Not connected to admin UI.
 * Prior successfully committed days are not rolled back by a later invocation.
 * No scheduler, retry loop or automatic cleanup.
 */
function archive_visitor_stats_one_day(PDO $pdo, string $cutoff, ?Closure $afterDiscoveryForTest = null): array
{
    $limit = gmdate('Y-m-d', strtotime('-30 days'));
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $cutoff, new DateTimeZone('UTC'));
    if (!$date || $date->format('Y-m-d') !== $cutoff || $cutoff > $limit) {
        throw new InvalidArgumentException('Invalid historical UTC cutoff');
    }
    // Advisory discovery only: the chosen exact day is re-read under a lock.
    $first = $pdo->prepare('SELECT MIN(visit_date) FROM visitor_stats_daily WHERE visit_date < ?');
    $first->execute([$cutoff]);
    $day = $first->fetchColumn();
    $first->closeCursor();
    // Test-only seam to prove an insertion between discovery and the day lock.
    if ($afterDiscoveryForTest !== null) $afterDiscoveryForTest();
    if ($day === false || $day === null) {
        return ['rows'=>0, 'days'=>0, 'views'=>0];
    }
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->beginTransaction();
    try {
        $count = 0;
        $views = 0;
        $lock = $pdo->prepare('SELECT page_kind, views FROM visitor_stats_daily FORCE INDEX(PRIMARY) WHERE visit_date=? FOR UPDATE');
        $originalBuffering = (bool)$pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        try {
            $lock->execute([$day]);
            while (($row = $lock->fetch(PDO::FETCH_ASSOC)) !== false) {
                if (!in_array($row['page_kind'], ['home', 'lot'], true) || (int)$row['views'] < 1) {
                    throw new RuntimeException('Invalid source record');
                }
                if (++$count > 20000) {
                    throw new RuntimeException('Day exceeds safety limit (20000)');
                }
                $views += (int)$row['views'];
            }
        } finally {
            $lock->closeCursor();
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $originalBuffering);
        }
        if ($count === 0) {
            $pdo->commit();
            return ['rows'=>0, 'days'=>0, 'views'=>0];
        }
        $daily = $pdo->prepare("INSERT INTO visitor_stats_day_totals (visit_date, visitor_days, views, lot_views)
            SELECT visit_date, COUNT(DISTINCT visitor_id), SUM(views),
                   SUM(CASE WHEN page_kind='lot' THEN views ELSE 0 END)
            FROM visitor_stats_daily WHERE visit_date=? GROUP BY visit_date");
        $daily->execute([$day]);
        if ($daily->rowCount() !== 1) throw new RuntimeException('Daily aggregation missing');
        $lots = $pdo->prepare("INSERT INTO visitor_stats_lot_totals (visit_date, lot_id, visitor_days, views)
            SELECT visit_date, lot_id, COUNT(DISTINCT visitor_id), SUM(views)
            FROM visitor_stats_daily WHERE visit_date=? AND page_kind='lot'
            GROUP BY visit_date, lot_id");
        $lots->execute([$day]);
        $delete = $pdo->prepare('DELETE FROM visitor_stats_daily WHERE visit_date=?');
        $delete->execute([$day]);
        if ($delete->rowCount() !== $count) throw new RuntimeException('Source rows changed');
        $pdo->commit();
        return ['rows'=>$count, 'days'=>1, 'views'=>$views];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
