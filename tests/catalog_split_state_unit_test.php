<?php
declare(strict_types=1);
// Isolated unit test: no database, no production configuration and no admin credentials.
$GLOBALS['admin_preview_unit'] = false;
function admin_preview_active(): bool { return $GLOBALS['admin_preview_unit']; }
function money(mixed $amount): string { return '€ '.number_format((float)$amount,0,',','.'); }
require dirname(__DIR__).'/private/catalog_preview_modes.php';
$_SESSION = [];
$off=['catalog_split_active'=>0];
$on=['catalog_split_active'=>1];
if (catalog_split_active([]) || catalog_split_active($off) || !catalog_split_active($on))
    throw new RuntimeException('Persisted state must default OFF and reflect the stored switch');
$GLOBALS['admin_preview_unit'] = true;
$_SESSION['catalog_split_preview'] = false;
if (catalog_split_active($on)) throw new RuntimeException('Admin preview must override saved ON to OFF');
$_SESSION['catalog_split_preview'] = true;
if (!catalog_split_active($off)) throw new RuntimeException('Admin preview must override saved OFF to ON');
$GLOBALS['admin_preview_unit'] = false;
if (catalog_split_active($off)) throw new RuntimeException('Preview choice must never leak to visitors');
$auction=['sale_location'=>'veiling','starting_bid'=>250,'market_price'=>950];
$market=['sale_location'=>'markt','starting_bid'=>20,'market_price'=>175];
if (catalog_preview_price($auction,false)!==null || catalog_preview_price($market,false)!==null)
    throw new RuntimeException('Prices leaked before split');
if (catalog_preview_price($auction,true)!==['label'=>'Startbod','amount'=>'€ 250'])
    throw new RuntimeException('Auction lot shows wrong price');
if (catalog_preview_price($market,true)!==['label'=>'Marktprijs','amount'=>'€ 175'])
    throw new RuntimeException('Marketplace lot shows wrong price');
echo "PASS persisted split default, admin preview override and price visibility\n";
