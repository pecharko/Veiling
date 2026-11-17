<?php
declare(strict_types=1);

// Static, read-only public footer contract. No DB or network calls.
$root = dirname(__DIR__);
$pages = [
    'index.php', 'kavel.php', 'kunstenaars.php', 'kunstenaar.php',
    'kunstenaar-interesse.php', 'kunstwerk-interesse.php',
    'goede-doel.php', 'vorige-editie.php', 'privacy.php',
];
$expected = '<footer class="footer"><div class="shell footer-inner public-footer-inner">'
    . '<span class="footer-brand"><strong>41 LOKEREN · VEILING 2027</strong></span>'
    . '<p class="footer-secondary-note">Geen online biedingen. Geen registratie vereist.</p>'
    . '<a href="<?=e(url(\'privacy.php\'))?>">Privacy</a>'
    . '<a href="https://pecharko-website.pages.dev/">Designed by Pecharko</a>'
    . '<?php public_social_footer(); ?></div></footer>';
foreach ($pages as $file) {
    $source = file_get_contents($root . '/catalogus-public/' . $file);
    if (!is_string($source)) {
        throw new RuntimeException('Public page unavailable: ' . $file);
    }
    if (substr_count($source, $expected) !== 1) {
        throw new RuntimeException('Public page must reuse the complete shared footer: ' . $file);
    }
    if (substr_count($source, "assets/public-footer.css'))?>?v=1") !== 1) {
        throw new RuntimeException('Public page must load footer stylesheet once: ' . $file);
    }
    if (substr_count($source, 'public_social_footer();') !== 1) {
        throw new RuntimeException('Public footer must use the existing social component once: ' . $file);
    }
}
$css = file_get_contents($root . '/catalogus-public/assets/public-footer.css');
if (!is_string($css)) {
    throw new RuntimeException('Missing shared footer stylesheet.');
}
foreach ([
    '.footer .public-footer-inner{',
    'display:grid;',
    'grid-template-columns:minmax(0,1.1fr) minmax(0,1.35fr) auto auto auto;',
    '@media screen and (max-width:1100px)',
    '@media screen and (max-width:767px)',
    '.footer .public-footer-inner .footer-secondary-note{',
    'display:none!important;',
] as $rule) {
    if (!str_contains($css, $rule)) {
        throw new RuntimeException('Shared responsive footer style missing: ' . $rule);
    }
}
echo "PASS: consistent public footers on nine pages; mobile informational note hidden.\n";
