<?php
declare(strict_types=1);

/**
 * Explicit admin-only archival of closed UTC visitor days.
 * No cron, no automatic cleanup on visits. Never call from public code.
 * Requires separately approved database/visitor_stats_aggregates.sql migration.
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
        // Lock old source rows; active days and currently incoming visits are excluded.
        $select = $pdo->prepare('SELECT visit_date, visitor_id, page_kind, lot_id, views FROM visitor_stats_daily WHERE visit_date < ? ORDER BY visit_date, visitor_id, page_kind, lot_id FOR UPDATE');
        $select->execute([$cutoff]);
        $dates = [];
        $lots = [];
        $rowCount = 0;
        $sourceViews = 0;
        while (($r = $select->fetch(PDO::FETCH_ASSOC)) !== false) {
            $day = (string)$r['visit_date'];
            $id = bin2hex((string)$r['visitor_id']);
            $views = (int)$r['views'];
            if ($views < 1 || $id === '') throw new RuntimeException('Invalid analytics source record');
            if (!isset($dates[$day])) $dates[$day] = ['visitors' => [], 'views' => 0, 'lot_views' => 0];
            $dates[$day]['visitors'][$id] = true;
            $dates[$day]['views'] += $views;
            if ($r['page_kind'] === 'lot') {
                $dates[$day]['lot_views'] += $views;
                $lotId = (int)$r['lot_id'];
                $key = $day . ':' . $lotId;
                if (!isset($lots[$key])) $lots[$key] = ['date' => $day, 'lot' => $lotId, 'visitors' => [], 'views' => 0];
                $lots[$key]['visitors'][$id] = true;
                $lots[$key]['views'] += $views;
            } elseif ($r['page_kind'] !== 'home') {
                throw new RuntimeException('Unexpected analytics page type');
            }
            $rowCount++;
            $sourceViews += $views;
        }
        $select->closeCursor();
        if ($rowCount === 0) {
            $pdo->commit();
            return ['rows' => 0, 'days' => 0, 'views' => 0];
        }
        // No overwrite or additive upserts: fail closed if any date was archived before.
        $insertDay = $pdo->prepare('INSERT INTO visitor_stats_day_totals (visit_date, visitor_days, views, lot_views) VALUES (?, ?, ?, ?)');
        $insertLot = $pdo->prepare('INSERT INTO visitor_stats_lot_totals (visit_date, lot_id, visitor_days, views) VALUES (?, ?, ?, ?)');
        $archivedViews = 0;
        foreach ($dates as $day => $data) {
            $insertDay->execute([$day, count($data['visitors']), $data['views'], $data['lot_views']]);
            $archivedViews += $data['views'];
        }
        foreach ($lots as $data) {
            $insertLot->execute([$data['date'], $data['lot'], count($data['visitors']), $data['views']]);
        }
        if ($archivedViews !== $sourceViews) throw new RuntimeException('Archive checksum mismatch');
        // Only now, in the same transaction, remove source identifiers.
        $delete = $pdo->prepare('DELETE FROM visitor_stats_daily WHERE visit_date < ?');
        $delete->execute([$cutoff]);
        if ($delete->rowCount() !== $rowCount) throw new RuntimeException('Archive source row count changed');
        $pdo->commit();
        return ['rows' => $rowCount, 'days' => count($dates), 'views' => $sourceViews];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
