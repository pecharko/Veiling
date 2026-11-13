<?php
declare(strict_types=1);
// Read-only source contract for the public portrait-led artists directory.
$root = dirname(__DIR__);
$page = file_get_contents($root.'/catalogus-public/kunstenaars.php');
$profile = file_get_contents($root.'/catalogus-public/kunstenaar.php');
$home = file_get_contents($root.'/catalogus-public/index.php');
$css = file_get_contents($root.'/catalogus-public/assets/catalogus.css');
if (!is_string($page) || !is_string($profile) || !is_string($home) || !is_string($css)) {
    throw new RuntimeException('Artist directory source missing');
}
$required = [
    'site online gate' => 'require_public_site_online()',
    'publication gate' => "($settings['is_published'] ?? 0)",
    'artist type' => "s.seller_type='kunstenaar'",
    'published lots' => "l.status IN ('published','sold')",
    'eligibility via exists' => 'AND EXISTS (',
    'one portrait per artist' => "si.image_kind='portrait' AND si.slot=1",
    'portrait link to profile' => 'url(\'kunstenaar.php\',[\'id\'=>(int)$artist[\'id\']])',
    'image URL escaped' => 'src="<?=e(url($portrait))?>"',
    'artist name escaped' => '<?=e($name)?>',
    'safe live upload path' => 'uploads/[0-9]{4}/[0-9]{2}/[a-f0-9]{32}',
    'isolated fixture guard' => "getenv('LOCAL_PREVIEW_ISOLATED') === '1'",
    'preview configuration guard' => "cfg('setup_key') === 'local-preview-only-nonproduction'",
    'empty overview' => 'Binnenkort ontdek je hier',
    'responsive cards' => 'artists-overview-grid',
    'canonical' => 'https://41lokeren.be/kunstenaars.php',
];
foreach ($required as $label=>$needle) {
    if (!str_contains($page,$needle)) throw new RuntimeException('Artist directory missing '.$label);
}
if (preg_match('/SELECT\\s+s\\.\\*/i',$page)) throw new RuntimeException('Artist directory may not select seller private fields');
if (preg_match('/\\$artist\\[(?:\\x27|\\x22)(?:email|bank_account_iban|mobile_number|street|created_by_name)/i',$page)) {
    throw new RuntimeException('Artist directory must not expose seller private fields');
}
if (!str_contains($home,"url('kunstenaars.php')")) throw new RuntimeException('Artist directory missing from home navigation');
if (!str_contains($profile,'Kunstenaar niet gevonden.')) throw new RuntimeException('Artist profile gate missing');
if (!str_contains($css,'.artists-overview-card') || !str_contains($css,'@media(max-width:580px)')) {
    throw new RuntimeException('Artist directory layout missing mobile breakpoints');
}
echo "PASS public artist directory, portrait links, publication privacy and responsive layout.\n";
