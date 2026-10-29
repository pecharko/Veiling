<?php
declare(strict_types=1);

/**
 * Read-only HTML rendering for geographic visitor-day percentages.
 * Call only with visitor_geo_percentages() output. Never print raw counts.
 */
function visitor_geo_render_percentage_table(array $rows, string $title): string
{
    $html = '<h3>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h3>';
    if ($rows === []) return $html . '<p>Nog geen geografische gegevens beschikbaar.</p>';
    $html .= '<div class="dashboard-table-scroll"><table class="dashboard-table">'
        . '<thead><tr><th>Herkomst</th><th>Aandeel</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        if (!is_array($row) || !is_string($row['label'] ?? null)
            || !is_numeric($row['percentage'] ?? null)
            || (float)$row['percentage'] < 0 || (float)$row['percentage'] > 100
            || array_key_exists('visitor_days', $row) || array_key_exists('views', $row)) {
            throw new InvalidArgumentException('Expected percentage-only geographic report');
        }
        $name = htmlspecialchars($row['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $percentage = number_format((float)$row['percentage'], 1, ',', '.') . '%';
        $html .= '<tr><td>' . $name . '</td><td>' . $percentage . '</td></tr>';
    }
    return $html . '</tbody></table></div>';
}
