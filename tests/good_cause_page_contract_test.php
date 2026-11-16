<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$home = file_get_contents($root . '/catalogus-public/index.php');
$page = file_get_contents($root . '/catalogus-public/goede-doel.php');
$css = file_get_contents($root . '/catalogus-public/assets/goede-doel.css');
if (!is_string($home) || !is_string($page) || !is_string($css)) {
    throw new RuntimeException('Good cause page source missing.');
}
if (substr_count($home, "url('goede-doel.php')") !== 2) {
    throw new RuntimeException('Home hero and navigation must link to the dedicated page.');
}
if (str_contains($home, 'href="#goede-doel"')) {
    throw new RuntimeException('Old in-page good-cause link still present.');
}
foreach (['require_public_site_online()', 'admin_preview_banner()', 'public_social_footer()', 'href="<?=e(url(\'index.php\'))?>"', 'id="inhoud"', 'assets/goede-doel.css'] as $expected) {
    if (!str_contains($page, $expected)) {
        throw new RuntimeException('Dedicated page missing: ' . $expected);
    }
}
if (!str_contains($page, 'Binnenkort stellen we het goede doel voor.')) {
    throw new RuntimeException('Unannounced beneficiary must not be invented.');
}
if (!str_contains($css, '@media(max-width:650px)')) {
    throw new RuntimeException('Missing mobile styles.');
}
echo "PASS: dedicated good cause page, links, guards and mobile stylesheet.\n";
