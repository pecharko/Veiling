<?php
declare(strict_types=1);
// Offline privacy and layout contract. Never expose a seller's private fields.
$root=dirname(__DIR__);
$page=file_get_contents($root.'/catalogus-public/kunstenaar.php');
$detail=file_get_contents($root.'/catalogus-public/kavel.php');
$css=file_get_contents($root.'/catalogus-public/assets/catalogus.css');
if (!is_string($page) || !is_string($detail) || !is_string($css)) throw new RuntimeException('Missing artist page source');
foreach ([
 'public site guard'=>'require_public_site_online()',
 'published-only guard'=>"l.status IN ('published','sold')",
 'only artist profiles'=>"s.seller_type='kunstenaar'",
 'public field selection'=>'SELECT s.artist_name, s.artist_slogan, s.artist_intro',
 'public photo slots'=>"image_kind='portrait' AND slot=1",
 'three public works'=>"image_kind='work' AND slot BETWEEN 1 AND 3",
 'slogan presented'=>'artist-profile-slogan',
 'biography escaped'=>'nl2br(e($intro))',
 'artist portrait'=>'artist-profile-portrait',
 'responsive works'=>'artist-profile-works-grid',
 'canonical URL'=>'https://41lokeren.be/kunstenaar.php?id=',
] as $label=>$needle) {
 if (!str_contains($page,$needle)) throw new RuntimeException('Artist public page missing '.$label);
}
if (preg_match('/SELECT\\s+s\\.\\*/i',$page)) throw new RuntimeException('Public artist query selects sensitive seller fields');
if (preg_match('/\\$artist\\[(?:\\x27|\\x22)(?:email|mobile_number|bank_account_iban|street|house_number|created_by_name)/i',$page)) throw new RuntimeException('Private seller field rendered publicly');
if (!str_contains($detail,"url('kunstenaar.php',['id'=>(int)\$lot['seller_id']])"))
 throw new RuntimeException('Detail artist link does not point internally');
if (str_contains($detail,'lot_artist_markup($lot)')) throw new RuntimeException('Detail still uses external artist link');
if (!str_contains($css,'.artist-profile-hero') || !str_contains($css,'@media(max-width:800px)'))
 throw new RuntimeException('Artist layout is not responsive');
echo "PASS internal artist page, published-only access, public-field allowlist and galleries.\\n";
