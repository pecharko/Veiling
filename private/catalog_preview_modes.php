<?php
declare(strict_types=1);
// The administrator can compare both designs without changing public database state.
function catalog_split_preview_active(): bool {
    return admin_preview_active() && ($_SESSION['catalog_split_preview'] ?? false) === true;
}
function catalog_preview_price(array $lot): ?array {
    if (!catalog_split_preview_active()) return null;
    $isAuction = ($lot['sale_location'] ?? '') === 'veiling';
    $amount = $isAuction ? ($lot['starting_bid'] ?? null) : ($lot['market_price'] ?? null);
    return $amount === null ? null : ['label'=>$isAuction?'Startbod':'Marktprijs','amount'=>money($amount)];
}
