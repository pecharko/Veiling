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
    || substr_count($html,'target="_blank"')!==1
    || !str_contains($html,'aria-label="Facebook van 41 Lokeren" rel="noreferrer"')
    || !str_contains($html,'aria-label="Instagram van 41 Lokeren (opent in nieuw tabblad)" target="_blank" rel="noopener noreferrer"')) {
    throw new RuntimeException('Facebook must use the same tab and Instagram retains its existing target');
}
$GLOBALS['social_test_config']=[
    'facebook_url'=>'https://www.facebook.com/example',
    'instagram_url'=>'https://www.instagram.com/example/',
];
ob_start();public_social_footer();$html=ob_get_clean();
if (substr_count($html,'<a class="footer-social-icon"')!==2
    || substr_count($html,'rel="noopener noreferrer"')!==1
    || substr_count($html,'rel="noreferrer"')!==1
    || substr_count($html,'target="_blank"')!==1) {
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
// Historical receipt from an already completed release: current source files
// evolve in later releases. Validate archive integrity, not stale source equality.
foreach ($release['files'] as $entry) {
    if (!preg_match('/^[0-9a-f]{64}$/', $entry['source_sha256'])
        || !preg_match('/^[0-9a-f]{64}$/', $entry['remote_sha256'])) {
        throw new RuntimeException('Historic release evidence has invalid hashes');
    }
}
echo "PASS public social footer independent of catalog visibility and valid links only\n";

// All public pages share one visual footer, with the explanatory sentence hidden on phones.
require __DIR__ . '/public_footer_layout_contract_test.php';

// The new artist invitation shares the public navigation and footer.
// Run its source-and-assets contract inside the existing public CI workflow.
require __DIR__ . '/artist_interest_page_contract_test.php';

// Validate the newly enabled second participation button and fictitious lot example.
require __DIR__ . '/artwork_interest_page_contract_test.php';

// Reuse existing public-page CI to guard mobile header behavior and cache busting.
require __DIR__ . '/mobile_header_contract_test.php';

// The complete bundled public UI must be exercised by the existing safe PR contract job.
require __DIR__ . '/public_branding_contract_test.php';
require __DIR__ . '/public_mobile_buttons_navigation_contract_test.php';
require __DIR__ . '/homepage_artists_cta_contract_test.php';
require __DIR__ . '/artists_overview_header_navigation_contract_test.php';
require __DIR__ . '/good_cause_header_navigation_contract_test.php';
require __DIR__ . '/previous_edition_header_navigation_contract_test.php';

// Verify the two recruitment panels and approved assets in the existing public CI.
require __DIR__ . '/homepage_recruitment_contract_test.php';
require __DIR__ . '/artist_directory_profile_navigation_contract_test.php';
require __DIR__ . '/catalog_split_reference_layout_test.php';
