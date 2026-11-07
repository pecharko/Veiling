<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function cfg(string $key): mixed { return $GLOBALS['social_test_config'][$key] ?? null; }
function e(mixed $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
require $root.'/private/public_social_footer.php';
$home=file_get_contents($root.'/catalogus-public/index.php');
$detail=file_get_contents($root.'/catalogus-public/kavel.php');
$previous=file_get_contents($root.'/catalogus-public/vorige-editie.php');
$privacy=file_get_contents($root.'/catalogus-public/privacy.php');
foreach ([$home,$detail,$previous,$privacy] as $page) {
    if (!is_string($page) || !str_contains($page, 'public_social_footer();')) {
        throw new RuntimeException('Social footer missing on public page');
    }
}
$pecharkoFooter='<a href="https://pecharko-website.pages.dev/">Designed by Pecharko</a>';
foreach ([$home, $detail] as $page) {
    if (substr_count($page, $pecharkoFooter) !== 1
        || str_contains($page, '<a href="<?=e(url(\'pecharko.php\'))?>">Designed by Pecharko</a>')) {
        throw new RuntimeException('Pecharko attribution link must point to the requested external site');
    }
}
$catalogEnd=strpos($home, '<?php if ($published): ?>');
$footerAt=strpos($home, 'public_social_footer();');
if ($catalogEnd===false || $footerAt===false || $footerAt<$catalogEnd) {
    throw new RuntimeException('Social footer must be outside lot visibility guard');
}
ob_start();public_social_footer();$html=ob_get_clean();
if (!str_contains($html,'Volg ons') || substr_count($html,'footer-social-icon--pending')!==0
    || substr_count($html,'<a class="footer-social-icon"')!==2
    || !str_contains($html,'href="https://www.facebook.com/41Lokeren"')
    || !str_contains($html,'href="https://www.instagram.com/41lokeren/"')
    || substr_count($html,'target="_blank" rel="noopener noreferrer"')!==2) {
    throw new RuntimeException('Both confirmed social profiles must open safely in new tabs');
}
$GLOBALS['social_test_config']=[
    'facebook_url'=>'https://www.facebook.com/example',
    'instagram_url'=>'https://www.instagram.com/example/',
];
ob_start();public_social_footer();$html=ob_get_clean();
if (substr_count($html,'<a class="footer-social-icon"')!==2
    || substr_count($html,'rel="noopener noreferrer"')!==2) {
    throw new RuntimeException('Configured links must be safe');
}
foreach (['http://www.facebook.com/example','https://facebook.com.evil.test/a','https://user@instagram.com/a'] as $bad) {
    if (public_social_profile_url(str_contains($bad,'instagram')?'instagram':'facebook',$bad)!==null)
        throw new RuntimeException('Unsafe social URL accepted');
}
$release=json_decode(file_get_contents($root.'/releases/pecharko-footer-link-20261107.json'), true, 512, JSON_THROW_ON_ERROR);
$expectedPaths=['catalogus-public/index.php', 'catalogus-public/kavel.php'];
if (($release['schema'] ?? null)!==1 || array_column($release['files'] ?? [], 'path')!==$expectedPaths) {
    throw new RuntimeException('Footer release manifest must contain only two public pages');
}
foreach ($release['files'] as $entry) {
    if (hash_file('sha256', $root.'/'.$entry['path'])!==$entry['source_sha256']) {
        throw new RuntimeException('Footer release source hash must match committed code');
    }
}
echo "PASS public social footer independent of catalog visibility and valid links only\n";
