<?php
declare(strict_types=1);
// The lot detail must reuse the public catalogue's top-five ranking.
$root = dirname(__DIR__);
$detail = file_get_contents($root . '/catalogus-public/kavel.php');
$catalog = file_get_contents($root . '/catalogus-public/index.php');
$css = file_get_contents($root . '/catalogus-public/assets/catalogus.css');
$shared = file_get_contents($root . '/private/catalog_popular_lots.php');
if (!is_string($detail) || !is_string($catalog) || !is_string($css) || !is_string($shared)) {
    throw new RuntimeException('Missing detail popularity files');
}
function detail_popular_assert(bool $valid, string $message): void {
    if (!$valid) throw new RuntimeException($message);
}
detail_popular_assert(
    str_contains($detail, "require_once dirname(__DIR__) . '/private/catalog_popular_lots.php';") &&
    str_contains($detail, 'catalog_popular_lots(db(), $splitPreview)') &&
    str_contains($catalog, 'catalog_popular_lots($pdo, $splitPreview)'),
    'Detail and catalog must use identical top-five groups'
);
detail_popular_assert(
    str_contains($detail, '=== (int)$lot[\'id\']') &&
    str_contains($detail, 'foreach ($popularGroup as $popularLot)') &&
    str_contains($detail, 'if ($isPopular)') &&
    str_contains($detail, 'class="lot-popular-tag detail-popular-tag"'),
    'Detail badge must appear only for ranked lot ID'
);
detail_popular_assert(
    str_contains($detail, 'catch (PDOException $exception)') &&
    str_contains($detail, "error_log('Popular lot statistics unavailable on detail.')"),
    'Missing stats must never prevent detail from rendering'
);
detail_popular_assert(
    str_contains($css, '.main-photo .detail-popular-tag{position:absolute;') &&
    str_contains($css, '@media(max-width:800px){.main-photo .detail-popular-tag{'),
    'Badge overlays main photo at desktop and mobile widths'
);
detail_popular_assert(
    str_contains($shared, 'FROM visitor_stats_daily') &&
    str_contains($shared, 'FROM visitor_stats_lot_totals') &&
    str_contains($shared, 'count($groups[$group]) >= 5'),
    'Reuse archived/raw seven-day ranking, not a second popularity rule'
);
echo "PASS: detail Populair badge follows catalog top-five and responsive photo placement.\n";
