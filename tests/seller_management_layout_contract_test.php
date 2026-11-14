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
  'seller_save($pdo,$editId,$_POST,$actorId,$actorRole)',
  'Terug naar verkopers',
  'require_auth()',
] as $required) {
  if (!str_contains($editor,$required)) throw new RuntimeException('Missing seller editor element: '.$required);
}
foreach (['email','street','house_number','postal_code','municipality','artist_name','artist_url'] as $field) {
  if (!preg_match('/<input(?=[^>]*name="'.preg_quote($field,'/').'")(?=[^>]*\\brequired\\b)[^>]*>/s', $editor))
    throw new RuntimeException('Required seller input missing: '.$field);
}
if (!str_contains($editor,"input.required=artist && (input.name==='artist_name' || input.name==='artist_url')") || !str_contains($editor,'input.disabled=!artist'))
  throw new RuntimeException('Artist required fields must toggle with seller type');
if (str_contains($overview,'id="seller-form"') || str_contains($overview,'name="bank_account_iban"')
    || str_contains($overview,'seller_save(')) throw new RuntimeException('Seller overview must not embed new/edit form.');
if (strpos($overview, "'kunstenaar'=>'Kunstenaars'") >= strpos($overview, "'particulier'=>'Particulieren'")) throw new RuntimeException('Artists must precede private sellers');
foreach (['Kunstenaars','Particulieren','foreach($groupRows as $r)','seller-list-<?=e($groupType)?>-heading'] as $required) {
  if (!str_contains($overview,$required)) throw new RuntimeException('Missing separate seller group: '.$required);
}
if (str_contains($overview, '<th scope="col">Type</th>'))
  throw new RuntimeException('Redundant seller type column must be absent from both grouped tables.');
if (!str_contains($overview, "<th scope=\"col\">Portret</th><th scope=\"col\">Kunstenaar</th>"))
  throw new RuntimeException('Artist table must show portrait and artist name instead of private name.');
if (!str_contains($overview, "<th scope=\"col\">Naam</th><th scope=\"col\">Kavels</th><th scope=\"col\">Acties</th>"))
  throw new RuntimeException('Private-seller table must show only name, lots and actions.');
foreach (['seller_images', "image_kind='portrait' AND slot=1", "preg_match('~^uploads/", 'seller-portrait-thumb', 'seller-portrait-placeholder', '<?=e(url($portrait))?>', '$groupType===\'kunstenaar\'?5:3'] as $required) {
  if (!str_contains($overview,$required)) throw new RuntimeException('Artist portrait cell missing safety or rendering contract: '.$required);
}
if (!str_contains($css, '.seller-portrait-thumb') || !str_contains($css, '.seller-portrait-placeholder'))
  throw new RuntimeException('Missing compact portrait styles.');
if (!str_contains($overview,'require_auth()')) throw new RuntimeException('Seller overview must require an authenticated role.');
foreach (['.seller-stats','.seller-fields-grid','.seller-artist-fields[hidden]','@media(max-width:650px)'] as $required) {
  if (!str_contains($css,$required)) throw new RuntimeException('Missing responsive seller styling: '.$required);
}
// Publishing must return to the same artist row without losing active filters.
foreach ([
  "if (\$filterType!=='') \$returnFilters['type']=\$filterType;",
  "if (\$search!=='') \$returnFilters['q']=\$search;",
  "url('admin/verkopers.php',\$returnFilters).'#seller-row-'",
  'id="seller-row-<?=(int)$r[\'id\']?>"',
  'style="scroll-margin-top:110px"',
] as $needle) {
  if (!str_contains($overview,$needle)) throw new RuntimeException('Artist publication must retain filters and row position: '.$needle);
}
if (substr_count($overview,"header('Location: '.url('admin/verkopers.php',\$returnFilters)") !== 1)
  throw new RuntimeException('Artist publication redirect should use a single anchored PRG response.');
echo "PASS separate seller list and new/edit form share the kavels design.\n";
