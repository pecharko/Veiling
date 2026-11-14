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
 'artist publication guard'=>'s.artist_published=1',
 'public field selection'=>'SELECT s.artist_name, s.artist_slogan, s.artist_intro, s.artist_url',
 'public photo slots'=>"image_kind='portrait' AND slot=1",
 'three public works'=>"image_kind='work' AND slot BETWEEN 1 AND 3",
 'slogan presented'=>'artist-profile-slogan',
 'biography escaped'=>'nl2br(e($intro))',
 'artist portrait'=>'artist-profile-portrait',
 'brochure shell'=>'artist-brochure-sheet',
 'brochure title'=>'artist-brochure-heading',
 'brochure two columns'=>'artist-brochure-columns',
 'brochure artwork'=>'artist-brochure-artwork',
 'isolated preview guard'=>"getenv('LOCAL_PREVIEW_ISOLATED') === '1'",
 'preview setup key'=>"cfg('setup_key') === 'local-preview-only-nonproduction'",
 'strict preview image namespace'=>'uploads/preview-fixtures/artist-[1-3]-(?:portrait-1|work-[1-3])',
 'normal image path'=>'uploads/[0-9]{4}/[0-9]{2}/[a-f0-9]{32}',
 'canonical URL'=>'https://41lokeren.be/kunstenaar.php?id=',
 'validated optional website'=>'lot_artist_validate($name, (string)($artist[\'artist_url\'] ?? \'\'))',
 'conditional website'=>'if ($artistWebsite !== \'\')',
 'website anchored after art columns'=>'artist-brochure-website',
 'external link safety'=>'rel="noopener noreferrer"',
] as $label=>$needle) {
 if (!str_contains($page,$needle)) throw new RuntimeException('Artist public page missing '.$label);
}
if (preg_match('/SELECT\\s+s\\.\\*/i',$page)) throw new RuntimeException('Public artist query selects sensitive seller fields');
if (preg_match('/\\$artist\\[(?:\\x27|\\x22)(?:email|mobile_number|bank_account_iban|street|house_number|created_by_name)/i',$page)) throw new RuntimeException('Private seller field rendered publicly');
if (!str_contains($detail,'lot_artist_markup($lot)'))
 throw new RuntimeException('Detail must use public-status-aware artist link');
if (!str_contains($css,'.artist-brochure-website')) throw new RuntimeException('Artist website footer styling missing');
if (strpos($page,'class="artist-brochure-website"') <= strpos($page,'class="artist-brochure-columns"'))
 throw new RuntimeException('Artist website link must appear below the artwork columns');
if (!str_contains($css,'.artist-brochure-columns') || !str_contains($css,'@media(max-width:700px)'))
 throw new RuntimeException('Artist layout is not responsive');
echo "PASS internal artist page, published-only access, public-field allowlist and brochure layout.\\n";
