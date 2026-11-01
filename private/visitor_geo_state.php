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


/** Persistent geo switch; default ON only after the separately initialized start_date. */
function visitor_geo_disabled_marker(): string
{
    return __DIR__ . '/visitor_geo.disabled';
}

function visitor_geo_collection_enabled(?array $state): bool
{
    return $state !== null
        && $state['start_date'] !== null
        && !is_file(visitor_geo_disabled_marker())
        && analytics_collection_enabled();
}

/** Fail closed when turning collection off. Does not delete any statistics. */
function visitor_geo_set_disabled(): void
{
    $path = visitor_geo_disabled_marker();
    if (!is_file($path)) {
        $file = @fopen($path, 'x');
        if ($file === false) throw new RuntimeException('Kan geografische registratie niet uitschakelen.');
        fclose($file);
    }
    if (!is_file($path)) throw new RuntimeException('Geografische uitschakeling niet bevestigd.');
}

/** Can only switch ON after schema validation and general collection is ON. */
function visitor_geo_set_enabled(PDO $pdo): void
{
    if (!analytics_collection_enabled()) throw new RuntimeException('Schakel eerst bezoekersregistratie in.');
    $state = visitor_geo_state($pdo);
    if ($state === null) throw new RuntimeException('Geografische databasetabellen ontbreken.');
    // Starting today may be a partial day; historical days are never backfilled.
    if ($state['start_date'] === null) {
        $stmt = $pdo->prepare('UPDATE visitor_geo_state SET start_date = UTC_DATE() WHERE id = 1 AND start_date IS NULL');
        $stmt->execute();
        if (visitor_geo_state($pdo)['start_date'] === null) {
            throw new RuntimeException('Startdatum geografische registratie kon niet worden ingesteld.');
        }
    }
    $marker = visitor_geo_disabled_marker();
    if (is_file($marker) && !unlink($marker)) {
        throw new RuntimeException('Geografische registratie kon niet worden ingeschakeld.');
    }
    if (is_file($marker)) throw new RuntimeException('Geografische registratie blijft uitgeschakeld.');
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
