<?php
declare(strict_types=1);
require_once __DIR__ . '/visitor_geo_state.php';
function visitor_geo_delete_rows(PDO $pdo, ?string $cutoff): int
{
    if (!$pdo->inTransaction()) throw new LogicException('Geo deletion requires parent transaction');
    if ($cutoff!==null) {
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$cutoff,new DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d')!==$cutoff || $cutoff>gmdate('Y-m-d')) throw new InvalidArgumentException('Invalid deletion cutoff');
    }
    if (visitor_geo_state($pdo)===null) return 0;
    $deleted=0;
    foreach (['visitor_geo_daily','visitor_geo_day_totals'] as $table) {
        $stmt=$pdo->prepare('DELETE FROM '.$table.($cutoff===null?'':' WHERE visit_date < ?'));
        $stmt->execute($cutoff===null?[]:[$cutoff]);$deleted+=$stmt->rowCount();
    }
    return $deleted;
}
