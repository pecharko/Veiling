<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$read=static function(string $path) use($root):string {
  $s=file_get_contents($root.'/'.$path);
  if (!is_string($s)) throw new RuntimeException('Missing source: '.$path);
  return $s;
};
$editor=$read('catalogus-public/admin/kavel.php');
$js=$read('catalogus-public/admin/lot-seller-picker.js');
$endpoint=$read('catalogus-public/admin/seller-search.php');
$bootstrap=$read('private/bootstrap.php');
$css=$read('catalogus-public/admin/admin.css');

$checks=[
  'no giant seller dropdown'=>!str_contains($editor,'seller_choices($pdo)')
    && str_contains($editor,'SELECT id,name,artist_name,seller_type FROM lot_sellers WHERE id=?')
    && str_contains($editor,'select name="seller_id" id="lot-seller-id"'),
  'searchable modal accessible from lot editor'=>str_contains($editor,'id="lot-seller-dialog"')
    && str_contains($editor,'aria-labelledby="lot-seller-dialog-title"')
    && str_contains($editor,'data-seller-search')
    && str_contains($editor,'data-seller-type="kunstenaar"')
    && str_contains($editor,'data-seller-type="particulier"'),
  'server search private, bounded and prepared'=>str_contains($endpoint,'require_auth();')
    && str_contains($bootstrap,"'seller-search.php'")
    && str_contains($endpoint,'LIMIT 25')
    && str_contains($endpoint,'$pdo->prepare($sql)')
    && str_contains($endpoint,'json_encode(')
    && !str_contains($endpoint,'email,'),
  'safe public portrait paths'=>str_contains($endpoint,'seller_artist_has_portrait_path($portrait)'),
  'selection is submitted as seller_id'=>str_contains($js,'new Option(')
    && str_contains($js,"select.dispatchEvent(new Event('change'"),
  'debounced search rejects stale requests'=>str_contains($js,'setTimeout(')
    && str_contains($js,'AbortController()')
    && str_contains($js,'number!==requestNumber'),
  'create flow restores selected seller without clearing lot'=>str_contains($js,'lot-seller-created')
    && str_contains($js,'fetchSellers({id})')
    && str_contains($editor,'data-create-seller'),
  'donation confirmation preserved'=>str_contains($editor,'name="donation_confirmed"')
    && str_contains($js,'window.confirm(warning)')
    && str_contains($js,"donation.value='1';"),
  'mobile modal and hidden dropdown styles'=>str_contains($css,'.lot-seller-dialog')
    && str_contains($css,'select#lot-seller-id[hidden]')
    && str_contains($css,'@media(max-width:650px){.lot-seller-selection'),
];
foreach ($checks as $name=>$ok) {
  echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;
  if(!$ok) exit(1);
}
