<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$read=static function(string $p) use($root):string {
  $s=file_get_contents($root.'/'.$p);
  if ($s===false) throw new RuntimeException('Missing '.$p);
  return $s;
};
$checks=[
  'additive migration defaults to unpublished'=>str_contains($read('database/migrations/20261114_artist_publication.sql'), 'artist_published TINYINT(1) NOT NULL DEFAULT 0'),
  'publisher role whitelist'=>str_contains($read('private/lot_sellers.php'), "in_array($role,['administrator','veilingmeester'],true)"),
  'publication service checks role and artist type'=>str_contains($read('private/lot_sellers.php'), 'seller_can_publish_artist($role)')
    && str_contains($read('private/lot_sellers.php'), "$seller['seller_type']!=='kunstenaar'"),
  'admin status action validates input'=>str_contains($read('catalogus-public/admin/verkopers.php'), "['published','unpublished']")
    && str_contains($read('catalogus-public/admin/verkopers.php'), 'seller_set_artist_publication('),
  'overview filters unpublished artists'=>str_contains($read('catalogus-public/kunstenaars.php'), 's.artist_published=1'),
  'direct artist page filters unpublished artists'=>str_contains($read('catalogus-public/kunstenaar.php'), 's.artist_published=1'),
  'catalogue and detail use published artist id'=>str_contains($read('catalogus-public/index.php'), 'AS published_artist_id')
    && str_contains($read('catalogus-public/kavel.php'), 'AS published_artist_id'),
  'artist name is plain text without publication'=>str_contains($read('private/lot_artist.php'), 'if ($publishedId < 1) return $safeName;'),
  'role matrix contains publication control'=>str_contains($read('catalogus-public/admin/rollen.php'), 'Publicatiestatus van kunstenaars wijzigen'),
];
foreach($checks as $label=>$ok) {
  echo ($ok?'PASS ':'FAIL ').$label.PHP_EOL;
  if (!$ok) exit(1);
}
