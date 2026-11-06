<?php
declare(strict_types=1);
$home = file_get_contents(dirname(__DIR__) . '/catalogus-public/index.php');
if (!is_string($home)) throw new RuntimeException('Homepage missing');
foreach (['<form class="toolbar"', 'Filter toepassen', 'Wis filters', "l.title LIKE ?", "l.category = ?", "str_replace('l.market_price'"] as $removed) {
    if (str_contains($home, $removed)) throw new RuntimeException('Old lot filter remains: '.$removed);
}
foreach (['catalog-preview-tabs', "l.auction_number ASC", "l.lot_number ASC", "id=\"favoritesOnly\"", 'class="pagination"'] as $retained) {
    if (!str_contains($home, $retained)) throw new RuntimeException('Required catalog functionality missing: '.$retained);
}
echo "PASS catalog filters removed; split, favorites and pagination retained\n";
