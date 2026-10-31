<?php
declare(strict_types=1);

/** Current reads avoid a stale InnoDB snapshot during archival verification. */
function visitor_geo_lock_day(PDO $pdo, string $table, string $day): array
{
    if (!in_array($table, ['visitor_stats_daily', 'visitor_geo_daily', 'visitor_geo_day_totals'], true)) {
        throw new InvalidArgumentException('Invalid geo archive table');
    }
    if (!$pdo->inTransaction()) {
        throw new LogicException('Geo archive verification requires a transaction');
    }
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    if ($mysql) {
        $owner = $pdo->query("SELECT IS_USED_LOCK('visitor_stats_one_day_archive') = CONNECTION_ID()")->fetchColumn();
        if ((int)$owner !== 1) throw new LogicException('Geo archive requires the parent archive lock');
        // Equality ranges must also block new inserts, including on empty days.
        $isolation = $pdo->query('SELECT @@transaction_isolation')->fetchColumn();
        if ($isolation !== 'REPEATABLE-READ') throw new LogicException('Geo archive requires REPEATABLE READ');
    }
    $stmt = $pdo->prepare('SELECT * FROM ' . $table . ' WHERE visit_date=? LIMIT 20001' . ($mysql ? ' FOR UPDATE' : ''));
    $stmt->execute([$day]);
    try {
        $rows = [];
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (count($rows) >= 20000) throw new RuntimeException('Geo archive day exceeds safety limit (20000)');
            $rows[] = $row;
        }
        return $rows;
    } finally {
        $stmt->closeCursor();
    }
}

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
    $expected = [];
    foreach (visitor_geo_lock_day($pdo, 'visitor_stats_daily', $day) as $row) {
        $key = bin2hex($row['visitor_id']);
        if ((int)$row['views'] < 1) throw new RuntimeException('Invalid geo archive source views');
        $expected[$key] = ($expected[$key] ?? 0) + (int)$row['views'];
    }
    $actual = [];
    foreach (visitor_geo_lock_day($pdo, 'visitor_geo_daily', $day) as $row) {
        if ((int)$row['views'] < 1) throw new RuntimeException('Invalid geo archive source views');
        $actual[bin2hex($row['visitor_id'])] = (int)$row['views'];
    }
    if (array_diff_key($expected, $actual) || array_diff_key($actual, $expected)) {
        throw new RuntimeException('Geo visitor identifiers disagree; archive blocked');
    }
    foreach ($expected as $id => $views) {
        if ($actual[$id] !== $views) {
            throw new RuntimeException('Geo per-visitor views disagree; archive blocked');
        }
    }
}
