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
  'publisher role whitelist'=>str_contains($read('private/lot_sellers.php'), "in_array(\$role,['administrator','veilingmeester'],true)"),
  'published artist edit disabled for assistants'=>str_contains($read('private/lot_sellers.php'), "if ((\$seller['seller_type']??'')==='kunstenaar' && (int)(\$seller['artist_published']??0)===1) return false;"),
  'published artist deletion denied to assistant'=>str_contains($read('private/lot_sellers.php'), 'Enkel veilingmeester of administrator kan een gepubliceerde kunstenaar verwijderen.'),
  'published artist type conversion denied to assistant'=>str_contains($read('private/lot_sellers.php'), 'Enkel veilingmeester of administrator kan een gepubliceerde kunstenaar omzetten.'),
  'artist publication requires saved portrait'=>str_contains($read('private/lot_sellers.php'), 'Publiceren kan alleen met een opgeslagen portretfoto')
    && str_contains($read('private/lot_sellers.php'), "image_kind='portrait'")
    && str_contains($read('private/lot_sellers.php'), 'is_file('),
  'published artist portrait cannot be removed'=>str_contains($read('private/seller_images.php'), "if (\$kind==='portrait')")
    && str_contains($read('private/seller_images.php'), "artist_published")
    && str_contains($read('private/seller_images.php'), 'Trek eerst de publicatie'),
  'editor status restricted to manager'=>str_contains($read('catalogus-public/admin/verkoper.php'), 'seller_can_publish_artist($actorRole)')
    && str_contains($read('catalogus-public/admin/verkoper.php'), "seller_set_artist_publication(\$pdo,\$editId,")
    && str_contains($read('catalogus-public/admin/verkoper.php'), 'name="artist_status"'),
  'listing autosaves status change without confirmation button'=>str_contains($read('catalogus-public/admin/verkopers.php'), 'onchange="this.form.requestSubmit()"')
    && !str_contains($read('catalogus-public/admin/verkopers.php'), '>Wijzig status</button>'),
  'status update uses async request without page reload'=>str_contains($read('catalogus-public/admin/verkopers.php'), "form.addEventListener('submit',async event =>")
    && str_contains($read('catalogus-public/admin/verkopers.php'), 'event.preventDefault();')
    && str_contains($read('catalogus-public/admin/verkopers.php'), "method:'POST'")
    && str_contains($read('catalogus-public/admin/verkopers.php'), 'body:payload,')
    && str_contains($read('catalogus-public/admin/verkopers.php'), 'select.value=previous;')
    && str_contains($read('catalogus-public/admin/verkopers.php'), "badge.classList.add(statusClasses[data.status]);"),
  'async endpoint reports saved artist status'=>str_contains($read('catalogus-public/admin/verkopers.php'), "'ok'=>true,'status'=>\$status")
    && str_contains($read('catalogus-public/admin/verkopers.php'), "HTTP_X_REQUESTED_WITH"),
  'listing excludes published selection without valid portrait'=>str_contains($read('catalogus-public/admin/verkopers.php'), 'seller_artist_has_portrait_path($portrait)')
    && str_contains($read('catalogus-public/admin/verkopers.php'), 'if($safePortrait)')
    && str_contains($read('catalogus-public/admin/verkopers.php'), 'Gepubliceerd (portret ontbreekt)'),
  'portrait eligibility shared by UI and server'=>str_contains($read('private/lot_sellers.php'), 'function seller_artist_has_portrait_path(')
    && str_contains($read('private/lot_sellers.php'), '!seller_artist_has_portrait_path($portrait)'),
  'publication service checks role and artist type'=>str_contains($read('private/lot_sellers.php'), 'seller_can_publish_artist($role)')
    && str_contains($read('private/lot_sellers.php'), 'seller_set_artist_publication(PDO $pdo'),
  'admin status action validates input'=>str_contains($read('catalogus-public/admin/verkopers.php'), "['new','published','unpublished']")
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
