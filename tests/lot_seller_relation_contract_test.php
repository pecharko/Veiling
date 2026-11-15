<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$editor=file_get_contents($root.'/catalogus-public/admin/kavel.php');
$catalog=file_get_contents($root.'/catalogus-public/index.php');
$detail=file_get_contents($root.'/catalogus-public/kavel.php');
$snap=file_get_contents($root.'/private/snapshots.php');
$migration=file_get_contents($root.'/database/migrations/20261111_lot_seller_dedup.sql');
if (!is_string($editor)||!is_string($catalog)||!is_string($detail)
  ||!is_string($snap)||!is_string($migration)) throw new RuntimeException('Missing source under review.');
foreach (['name="seller_id"','+ Nieuwe particulier','seller_valid_id($pdo','seller_id=? WHERE id=?','INSERT INTO lots'] as $key)
  if (!str_contains($editor,$key)) throw new RuntimeException('Missing lot seller relation: '.$key);
foreach (['artist_name','artist_url','bank_account_name','bank_account_iban'] as $field) {
  if (str_contains($editor,'name="'.$field.'"') || str_contains($editor,'lots SET '.$field))
    throw new RuntimeException('Obsolete lot input remains: '.$field);
  if (!str_contains($migration,'DROP COLUMN '.$field))
    throw new RuntimeException('Scoped column removal missing: '.$field);
}
foreach ([$catalog,$detail] as $source) {
  if (!str_contains($source,'LEFT JOIN lot_sellers seller ON seller.id=l.seller_id')
    || !str_contains($source,"seller.seller_type='kunstenaar'"))
    throw new RuntimeException('Public attribution must originate from artist seller.');
  foreach (['bank_account_iban','bank_account_name','seller.email','seller.street'] as $private)
    if (str_contains($source,$private)) throw new RuntimeException('Private seller data in public view.');
}
foreach (["'lot_sellers'","'seller_id'","41lokeren-v7-lot-audit"] as $key)
  if (!str_contains($snap,$key)) throw new RuntimeException('Seller-aware snapshots missing '.$key);
$picker=file_get_contents($root.'/catalogus-public/admin/lot-seller-picker.js');
if (!is_string($picker) || !str_contains($picker,'lot-seller-created')
    || !str_contains($picker,'new Option(')
    || !str_contains($picker,'fetchSellers({id})')
    || !str_contains($editor,'lot-seller-picker.js'))
  throw new RuntimeException('New seller must be selectable without losing unsaved lot input.');
echo "PASS central seller relation, public privacy, snapshot format and approved column scope.\n";
