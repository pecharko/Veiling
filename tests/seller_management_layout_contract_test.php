<?php
declare(strict_types=1);
$source=file_get_contents(dirname(__DIR__).'/catalogus-public/admin/verkopers.php');
$css=file_get_contents(dirname(__DIR__).'/catalogus-public/admin/admin.css');
if (!is_string($source) || !is_string($css)) throw new RuntimeException('Seller layout source missing.');
foreach ([
  'class="dashboard-modern seller-manager"',
  'class="dashboard-stats seller-stats"',
  'class="dashboard-panel"',
  'class="dashboard-panel-heading"',
  'class="dashboard-filters seller-filters"',
  'class="dashboard-table seller-table"',
  'class="dashboard-edit"',
  '>+ Nieuwe verkoper</a>',
  'id="seller-form"',
  'class="seller-fields-grid"',
  'class="seller-form-actions"',
  'id="seller-artist-fields"',
  'name="bank_account_iban"',
] as $required) {
  if (!str_contains($source,$required)) throw new RuntimeException('Missing aligned seller layout: '.$required);
}
foreach (['.seller-stats','.seller-fields-grid','.seller-artist-fields[hidden]','@media(max-width:650px)'] as $required) {
  if (!str_contains($css,$required)) throw new RuntimeException('Missing responsive seller styling: '.$required);
}
if (!str_contains($source,'require_administrator()')) throw new RuntimeException('Seller administration must be admin-only');
echo "PASS seller list and editor share the kavels dashboard design.\n";
