<?php
declare(strict_types=1);

// Offline-only public header contract; does not connect to a DB or a website.
$root = dirname(__DIR__);
$css = file_get_contents($root . '/catalogus-public/assets/catalogus.css');
if (!is_string($css)) throw new RuntimeException('Missing shared public CSS');

$desktopSticky = strpos($css, "position: sticky !important;");
$mobileRule = strpos($css, "@media screen and (max-width: 800px)");
if ($desktopSticky === false || $mobileRule === false || $mobileRule <= $desktopSticky) {
    throw new RuntimeException('Mobile-only header override must follow desktop sticky rule');
}
$mobileBlock = substr($css, $mobileRule, 500);
if (!preg_match('/@media screen and \\(max-width: 800px\\)\\s*\\{\\s*\\.site-header\\s*\\{\\s*position: relative !important;\\s*top: auto !important;/s', $mobileBlock)) {
    throw new RuntimeException('Public header must stay in document flow at 800px and below');
}
if (!str_contains($mobileBlock, '.admin-preview-banner ~ header.site-header') ||
    !str_contains($mobileBlock, 'top: auto !important;')) {
    throw new RuntimeException('Admin preview banner may not add a second mobile header offset');
}
if (!preg_match('/@media screen\\s*\\{\\s*\\.site-header\\s*\\{\\s*position: -webkit-sticky !important;\\s*position: sticky !important;/s', $css)) {
    throw new RuntimeException('Desktop sticky header contract must be preserved');
}
$pages = [
    'index.php',
    'kavel.php',
    'kunstenaars.php',
    'kunstenaar.php',
    'kunstenaar-interesse.php',
    'goede-doel.php',
    'vorige-editie.php',
];
foreach ($pages as $pageName) {
    $page = file_get_contents($root . '/catalogus-public/' . $pageName);
    if (!is_string($page) || !str_contains($page, 'class="site-header')
        || !str_contains($page, "assets/catalogus.css'))?>?v=196")) {
        throw new RuntimeException('Shared mobile stylesheet must be cache-busted on public page: ' . $pageName);
    }
}
echo "PASS: non-sticky public header at <=800px, sticky desktop header, seven cache-busted public pages\n";
