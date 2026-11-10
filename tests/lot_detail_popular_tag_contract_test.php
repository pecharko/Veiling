<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$detail = file_get_contents($root . '/catalogus-public/kavel.php');
$catalog = file_get_contents($root . '/catalogus-public/index.php');
$css = file_get_contents($root . '/catalogus-public/assets/catalogus.css');
$helper = file_get_contents($root . '/private/catalog_popular_lots.php');
if (!is_string($detail) || !is_string($catalog) || !is_string($css) || !is_string($helper)) {
    throw new RuntimeException('Required popular-lot source unavailable');
}
function popular_detail_assert(bool $ok, string $error): void {
    if (!$ok) throw new RuntimeException($error);
}
popular_detail_assert(
    str_contains($detail, "require_once dirname(__DIR__) . '/private/catalog_popular_lots.php';")
    && str_contains($detail, 'catalog_popular_lots(db(), $splitPreview)')
    && str_contains($catalog, 'catalog_popular_lots($pdo, $splitPreview)'),
    'Detail and catalogue must use same popularity ranking and split mode'
);
popular_detail_assert(
    str_contains($detail, "if ((int)$" . "popularLot['lot_id'] === (int)$" . "lot['id'])")
    && str_contains($detail, 'if ($isPopular)')
    && str_contains($detail, '<span class="lot-popular-tag">Populair</span>'),
    'Badge must be visible only on qualifying lot detail photos'
);
popular_detail_assert(
    str_contains($detail, 'catch (PDOException $exception)')
    && str_contains($detail, "error_log('Popular lot statistics unavailable on detail.')"),
    'Unavailable statistics must not block lot details'
);
popular_detail_assert(
    str_contains($css, '.main-photo{position:relative;')
    && str_contains($css, '.lot-popular-tag{position:absolute;'),
    'Existing popular tag must be positioned inside main image on desktop/mobile'
);
popular_detail_assert(
    str_contains($helper, 'FROM visitor_stats_daily')
    && str_contains($helper, 'FROM visitor_stats_lot_totals')
    && str_contains($helper, 'count($groups[$group]) >= 5'),
    'Ranking must remain the shared top-five of seven days'
);
echo "PASS: detail uses shared popularity logic and existing image tag CSS.\n";
