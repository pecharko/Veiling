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
    // Repeatable-read next-key locks protect historical date ranges.
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->beginTransaction();
    try {
        // Lock the ENTIRE historical prefix before enumerating days. Under
        // InnoDB REPEATABLE READ this range scan also next-key-locks gaps,
        // including dates with no existing records. Stream instead of buffering
        // visitor rows; retain only distinct UTC dates in PHP.
        // Fail closed before any aggregates/deletes if a manual operation would
        // process more rows than the tested operational envelope. The range
        // scan still locks the full historic prefix: this is a safety guard,
        // NOT a short-lock batch implementation.
        $maxSourceRows = 20000;
        $scannedRows = 0;
        $dates = [];
        $previousDay = null;
        $days = $pdo->prepare('SELECT visit_date FROM visitor_stats_daily FORCE INDEX(PRIMARY) WHERE visit_date < ? ORDER BY visit_date FOR UPDATE');
        $originalBuffering = (bool)$pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        try {
            $days->execute([$cutoff]);
            while (($day = $days->fetchColumn()) !== false) {
                if (++$scannedRows > $maxSourceRows) {
                    throw new RuntimeException('Archive exceeds manual source-row safety limit (20000)');
                }
                if ($day !== $previousDay) {
                    $dates[] = (string)$day;
                    $previousDay = $day;
                }
            }
        } finally {
            $days->closeCursor();
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $originalBuffering);
        }
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
        // Use an unbuffered locking range scan: the PRIMARY key begins with visit_date.
        // This locks existing rows and the corresponding index gap in InnoDB RR,
        // but never buffers all visitor identifiers in PHP.
        $validate = $pdo->prepare('SELECT page_kind, views FROM visitor_stats_daily FORCE INDEX(PRIMARY) WHERE visit_date=? FOR UPDATE');
        $delete = $pdo->prepare('DELETE FROM visitor_stats_daily WHERE visit_date=?');
        foreach ($dates as $day) {
            $n = 0;
            $views = 0;
            // Preserve the caller's PDO buffering preference across the scan.
            $originalBuffering = (bool)$pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            try {
                $validate->execute([$day]);
                while (($record = $validate->fetch(PDO::FETCH_ASSOC)) !== false) {
                    if (!in_array($record['page_kind'], ['home', 'lot'], true)
                        || (int)$record['views'] < 1) {
                        throw new RuntimeException('Invalid analytics source record');
                    }
                    $n++;
                    $views += (int)$record['views'];
                }
            } finally {
                $validate->closeCursor();
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $originalBuffering);
            }
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
