<?php
declare(strict_types=1);

/**
 * Read-only geographical report. Detailed current UTC days and archived days
 * are disjoint after successful archive. This function does not activate
 * tracking and must be used only after the geo schema is provisioned.
 */
function visitor_geo_report(PDO $pdo, int $days = 30): array
{
    if ($days < 1 || $days > 3660) {
        throw new InvalidArgumentException('Invalid report range');
    }
    $start = gmdate('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    $sql = "SELECT country_code,region_code,city_name,
                   SUM(visitor_days) AS visitor_days,SUM(views) AS views
            FROM (
              SELECT country_code,region_code,city_name,
                     COUNT(*) AS visitor_days,SUM(views) AS views
              FROM visitor_geo_daily WHERE visit_date >= ?
              GROUP BY country_code,region_code,city_name
              UNION ALL
              SELECT country_code,region_code,city_name,
                     SUM(visitor_days) AS visitor_days,SUM(views) AS views
              FROM visitor_geo_day_totals WHERE visit_date >= ?
              GROUP BY country_code,region_code,city_name
            ) geo GROUP BY country_code,region_code,city_name
            ORDER BY visitor_days DESC,views DESC,country_code,region_code,city_name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$start, $start]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Re-group rows without treating a visitor-day as a unique person. */
function visitor_geo_group(array $rows, string $level): array
{
    if (!in_array($level, ['country', 'region', 'city'], true)) {
        throw new InvalidArgumentException('Invalid geo grouping');
    }
    $groups = [];
    foreach ($rows as $row) {
        $country = (string)($row['country_code'] ?? 'ZZ');
        $region = (string)($row['region_code'] ?? '');
        $city = (string)($row['city_name'] ?? '');
        $key = $country;
        if ($level !== 'country') $key .= '/' . ($region !== '' ? $region : '?');
        if ($level === 'city') $key .= '/' . ($city !== '' ? $city : '?');
        if (!isset($groups[$key])) $groups[$key] = ['label' => $key, 'visitor_days' => 0, 'views' => 0];
        $groups[$key]['visitor_days'] += (int)$row['visitor_days'];
        $groups[$key]['views'] += (int)$row['views'];
    }
    uasort($groups, static fn(array $a, array $b): int =>
        ($b['visitor_days'] <=> $a['visitor_days']) ?: strcmp($a['label'], $b['label']));
    return array_values($groups);
}

/**
 * Presentation-only geographic distribution. The denominator is the total
 * number of visitor-days across ALL buckets for the selected grouping,
 * including unknown ('ZZ'). This is not a percentage of unique people.
 * Internal counts remain available for consistency checking, but UI must
 * render only the 'percentage' and 'label' fields.
 */
function visitor_geo_percentages(array $groupedRows): array
{
    $total = 0;
    foreach ($groupedRows as $row) {
        $count = $row['visitor_days'] ?? null;
        if (!is_int($count) || $count < 0) {
            throw new InvalidArgumentException('Invalid geographic visitor-days count');
        }
        $total += $count;
    }
    if ($total === 0) return [];
    $percentages = [];
    foreach ($groupedRows as $row) {
        $percentages[] = [
            'label' => (string)$row['label'],
            'percentage' => round(100 * $row['visitor_days'] / $total, 1),
        ];
    }
    return $percentages;
}

/**
 * Belgian province/city distribution is conditional on Belgian visitor-days.
 * Unknown Belgian region or city stays in the Belgian denominator; foreign
 * visitors must not dilute provincial percentages.
 */
function visitor_geo_belgian_percentages(array $rows, string $level): array
{
    if (!in_array($level, ['region', 'city'], true)) {
        throw new InvalidArgumentException('Belgian geographic level must be region or city');
    }
    $belgian = array_values(array_filter($rows, static fn(array $row): bool =>
        ($row['country_code'] ?? '') === 'BE'));
    return visitor_geo_percentages(visitor_geo_group($belgian, $level));
}
