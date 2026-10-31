<?php
declare(strict_types=1);

/** Read-only feature state. A missing migration leaves geography OFF. */
function visitor_geo_state(PDO $pdo): ?array
{
    $metadata = $pdo->query("SELECT TABLE_NAME name,ENGINE engine FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN
        ('visitor_geo_daily','visitor_geo_day_totals','visitor_geo_state',
         'visitor_stats_daily','visitor_stats_day_totals','visitor_stats_lot_totals')")->fetchAll(PDO::FETCH_ASSOC);
    $tables=array_column($metadata,'engine','name');
    $count=count(array_intersect(array_keys($tables),['visitor_geo_daily','visitor_geo_day_totals','visitor_geo_state']));
    if ($count === 0) return null;
    if ($count !== 3) throw new RuntimeException('Incomplete geographic schema');
    if(count($tables)!==6 || array_filter($tables,static fn($engine)=>$engine!=='InnoDB'))throw new RuntimeException('Geographic analytics require six InnoDB tables');
    $rows = $pdo->query('SELECT id,start_date FROM visitor_geo_state')->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) !== 1 || (int)$rows[0]['id'] !== 1) throw new RuntimeException('Invalid geographic state');
    $start = $rows[0]['start_date'];
    if ($start !== null) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$start, new DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d') !== $start) throw new RuntimeException('Invalid geographic start date');
    }
    return ['start_date'=>$start];
}

function visitor_geo_covers_day(?array $state, string $day): bool
{
    return $state !== null && $state['start_date'] !== null && $day >= $state['start_date'];
}

/** Before the first complete geo day, no location may be invented for history. */
function visitor_geo_require_empty_legacy_day(PDO $pdo, string $day): void
{
    foreach (['visitor_geo_daily','visitor_geo_day_totals'] as $table) {
        $stmt = $pdo->prepare('SELECT 1 FROM ' . $table . ' WHERE visit_date=? LIMIT 1 FOR UPDATE');
        $stmt->execute([$day]);
        $exists = $stmt->fetchColumn() !== false;
        $stmt->closeCursor();
        if ($exists) throw new RuntimeException('Geo data outside approved coverage; archive blocked');
    }
}
