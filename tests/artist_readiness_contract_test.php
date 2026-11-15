<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require_once $root.'/private/lot_sellers.php';

function assert_readiness(string $want,array $seller,bool $portrait,bool $work): void {
  $actual=seller_artist_publication_readiness($seller,$portrait,$work);
  if ($actual!==$want) throw new RuntimeException("Readiness expected $want; got $actual");
}
$complete=['artist_slogan'=>'Een korte slogan','artist_intro'=>'Een voorstelling'];
assert_readiness('no_portrait',$complete,false,true);
assert_readiness('no_portrait',[],false,false);
assert_readiness('missing_text',[],true,false);
assert_readiness('missing_text',['artist_slogan'=>'Aanwezig','artist_intro'=>'  '],true,true);
assert_readiness('missing_text',['artist_slogan'=>' ','artist_intro'=>'Aanwezig'],true,true);
assert_readiness('texts_ready',$complete,true,false);
assert_readiness('complete',$complete,true,true);

$list=file_get_contents($root.'/catalogus-public/admin/verkopers.php');
$css=file_get_contents($root.'/catalogus-public/admin/admin.css');
if (!is_string($list) || !is_string($css)) throw new RuntimeException('Artist overview source missing');
$required=[
  'name="readiness" aria-label="Klaar om te publiceren"',
  '<th scope="col">Klaar om te publiceren</th>',
  "if (\$sellerScreen==='kunstenaar' && \$filterReadiness!==''",
  "seller_artist_publication_readiness(",
  "seller_artist_has_portrait_path((string)\$workPhoto['path'])",
  "'readiness'=>\$filterReadiness",
  "'q','status','readiness','per_page'",
  "\$groupType==='kunstenaar'?6:3",
];
foreach($required as $needle) {
  if (!str_contains($list,$needle)) throw new RuntimeException('Artist readiness list contract missing: '.$needle);
}
foreach(['no_portrait','missing_text','texts_ready','complete'] as $state) {
  if (!str_contains($css,'.seller-readiness.is-'.$state)) throw new RuntimeException('Readiness color missing: '.$state);
}
echo "PASS artist readiness hierarchy, valid photos, filtering, pagination, colors.\n";
