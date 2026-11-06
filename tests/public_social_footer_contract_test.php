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
$catalogEnd=strpos($home, '<?php if ($published): ?>');
$footerAt=strpos($home, 'public_social_footer();');
if ($catalogEnd===false || $footerAt===false || $footerAt<$catalogEnd) {
    throw new RuntimeException('Social footer must be outside lot visibility guard');
}
ob_start();public_social_footer();$html=ob_get_clean();
if (!str_contains($html,'Volg ons') || substr_count($html,'footer-social-icon--pending')!==1
    || substr_count($html,'<a class="footer-social-icon"')!==1
    || !str_contains($html,'href="https://www.facebook.com/41Lokeren"')
    || !str_contains($html,'target="_blank" rel="noopener noreferrer"')) {
    throw new RuntimeException('Facebook must link to the confirmed page; Instagram stays unconfigured');
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
echo "PASS public social footer independent of catalog visibility and valid links only\n";
