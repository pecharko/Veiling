<?php
declare(strict_types=1);
$overview=file_get_contents(dirname(__DIR__).'/catalogus-public/admin/verkopers.php');
$editor=file_get_contents(dirname(__DIR__).'/catalogus-public/admin/verkoper.php');
$css=file_get_contents(dirname(__DIR__).'/catalogus-public/admin/admin.css');
if (!is_string($overview) || !is_string($editor) || !is_string($css)) throw new RuntimeException('Seller layout source missing.');
foreach ([
  'class="dashboard-modern seller-manager"',
  'class="dashboard-stats seller-stats"',
  'class="dashboard-panel"',
  'class="dashboard-panel-heading"',
  'class="dashboard-filters seller-filters"',
  'class="dashboard-table seller-table"',
  'class="dashboard-edit"',
  '>+ Nieuwe verkoper</a>',
  "url('admin/verkoper.php'))",
  "url('admin/verkoper.php',['id'=>(int)\$r['id']])",
] as $required) {
  if (!str_contains($overview,$required)) throw new RuntimeException('Missing seller overview element: '.$required);
}
foreach ([
  'id="seller-form"',
  'class="seller-fields-grid"',
  'class="seller-form-actions"',
  'id="seller-artist-fields"',
  'name="bank_account_iban"',
  'seller_save($pdo,$editId,$_POST)',
  'Terug naar verkopers',
  'require_administrator()',
] as $required) {
  if (!str_contains($editor,$required)) throw new RuntimeException('Missing seller editor element: '.$required);
}
foreach (['email','street','house_number','postal_code','municipality','bank_account_name','bank_account_iban','artist_name','artist_url'] as $field) {
  if (!preg_match('/<input(?=[^>]*name="'.preg_quote($field,'/').'")(?=[^>]*\\brequired\\b)[^>]*>/s', $editor))
    throw new RuntimeException('Required seller input missing: '.$field);
}
if (!str_contains($editor,'input.required=artist') || !str_contains($editor,'input.disabled=!artist'))
  throw new RuntimeException('Artist required fields must toggle with seller type');
if (str_contains($overview,'id="seller-form"') || str_contains($overview,'name="bank_account_iban"')
    || str_contains($overview,'seller_save(')) throw new RuntimeException('Seller overview must not embed new/edit form.');
if (!str_contains($overview,'require_administrator()')) throw new RuntimeException('Seller overview must remain admin-only.');
foreach (['.seller-stats','.seller-fields-grid','.seller-artist-fields[hidden]','@media(max-width:650px)'] as $required) {
  if (!str_contains($css,$required)) throw new RuntimeException('Missing responsive seller styling: '.$required);
}
echo "PASS separate seller list and new/edit form share the kavels design.\n";
