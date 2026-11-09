<?php
declare(strict_types=1);

/**
 * The exact "Populair" ranking shared by the public catalogue and admin stats.
 * Seven UTC dates including today; raw and archived dates are disjoint.
 * No visitor identifiers are selected, and this function never writes data.
 *
 * @return array<string,list<array<string,mixed>>> Key 'alle', or 'veiling'/'markt'.
 */
function catalog_popular_lots(PDO $pdo, bool $splitActive): array
{
    $groups = $splitActive ? ['veiling' => [], 'markt' => []] : ['alle' => []];
    $sql = "SELECT l.id AS lot_id, l.lot_number, l.title, l.sale_location, SUM(v.views) AS total_views
      FROM (
        SELECT lot_id, SUM(views) AS views FROM visitor_stats_daily
        WHERE page_kind='lot' AND visit_date BETWEEN UTC_DATE() - INTERVAL 6 DAY AND UTC_DATE()
        GROUP BY lot_id
        UNION ALL
        SELECT lot_id, SUM(views) AS views FROM visitor_stats_lot_totals
        WHERE visit_date BETWEEN UTC_DATE() - INTERVAL 6 DAY AND UTC_DATE()
        GROUP BY lot_id
      ) v INNER JOIN lots l ON l.id=v.lot_id
      WHERE l.status IN ('published','sold')
      GROUP BY l.id, l.lot_number, l.title, l.sale_location
      HAVING SUM(v.views) > 0
      ORDER BY total_views DESC, l.id ASC";
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $lot) {
        $group = $splitActive ? (string)$lot['sale_location'] : 'alle';
        if (!isset($groups[$group]) || count($groups[$group]) >= 5) continue;
        $groups[$group][] = $lot;
        if (!$splitActive && count($groups['alle']) === 5) break;
        if ($splitActive && count($groups['veiling']) === 5 && count($groups['markt']) === 5) break;
    }
    return $groups;
}
