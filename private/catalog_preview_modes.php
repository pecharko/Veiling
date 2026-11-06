<?php
declare(strict_types=1);
function catalog_split_active(array $settings): bool {
    if (admin_preview_active() && array_key_exists('catalog_split_preview', $_SESSION)) {
        return $_SESSION['catalog_split_preview'] === true;
    }
    return (int)($settings['catalog_split_active'] ?? 0) === 1;
}
function catalog_preview_price(array $lot, bool $splitActive): ?array {
    if (!$splitActive) return null;
    $isAuction = ($lot['sale_location'] ?? '') === 'veiling';
    $amount = $isAuction ? ($lot['starting_bid'] ?? null) : ($lot['market_price'] ?? null);
    return $amount === null || $amount === '' ? null : ['label'=>$isAuction?'Startbod':'Marktprijs','amount'=>money($amount)];
}
