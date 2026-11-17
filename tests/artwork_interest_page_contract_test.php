<?php
declare(strict_types=1);

// Offline public-preview contract: no DB, no user data, no network writes.
$root = dirname(__DIR__);
$home = file_get_contents($root . '/catalogus-public/index.php');
$page = file_get_contents($root . '/catalogus-public/kunstwerk-interesse.php');
$css = file_get_contents($root . '/catalogus-public/assets/kunstwerk-interesse.css');
$sharedCss = file_get_contents($root . '/catalogus-public/assets/kunstenaar-interesse.css');
if (!is_string($home) || !is_string($page) || !is_string($css) || !is_string($sharedCss)) {
    throw new RuntimeException('Artwork-interest page, styles or homepage missing.');
}
$cta = '<a class="front-btn front-btn-outline front-impact-action" href="<?=e(url(\'kunstwerk-interesse.php\'))?>">Ik heb een kunstwerk voor de veiling</a>';
if (!str_contains($home, $cta) ||
    str_contains($home, 'type="button" disabled title="Binnenkort beschikbaar">Ik heb een kunstwerk voor de veiling')) {
    throw new RuntimeException('Homepage artwork button must be enabled without changing its classes.');
}
$artistCta = '<a class="front-btn front-btn-sage front-impact-action" href="<?=e(url(\'kunstenaar-interesse.php\'))?>">Ik ben kunstenaar en heb interesse</a>';
if (!str_contains($home, $artistCta)) {
    throw new RuntimeException('The established artist interest CTA must remain intact.');
}
foreach ([
    'require_public_site_online();',
    'admin_preview_banner();',
    'public_social_footer();',
    '<nav class="header-nav front-nav"',
    '<main id="inhoud" class="artist-invite lot-invite">',
    '<h1 id="lot-invite-title">Ik heb een kunstwerk voor de veiling</h1>',
    '<small>KUNSTVEILING</small>',
    'assets/kunstwerk-interesse.css',
    'assets/kunstenaar-interesse.css',
    'href="<?=e(url(\'index.php\'))?>"',
    'mailto:veiling@41lokeren.be',
    'Jij beslist zelf welk deel van de verkoopprijs',
    'Mail ons jouw kunstwerk',
    'Fictief voorbeeld',
    'Zo kan jouw kavel',
    'Kavel 037',
    'Veilingnr. 12',
    'Tussen licht en beweging',
    '€ 350',
    'Startbod',
    'Details, afmetingen en staat',
    'Deze website aanvaardt geen online biedingen',
] as $expected) {
    if (!str_contains($page, $expected)) {
        throw new RuntimeException('Missing artwork-interest requirement: ' . $expected);
    }
}
if (substr_count($page, 'href="mailto:veiling@41lokeren.be"') !== 2) {
    throw new RuntimeException('Artwork-interest page must offer the specified email twice.');
}
if (str_contains($page, 'SELECT ') || str_contains($page, 'db()->') ||
    str_contains($page, '<form') || str_contains($page, 'data-favorite=') ||
    str_contains($page, 'href="<?=e(url(\'kavel.php\'))')) {
    throw new RuntimeException('Illustrative lot must not query live data, accept bids or link to actual lots.');
}
foreach (['werk-01', 'werk-02'] as $work) {
    $asset = $root . '/catalogus-public/assets/voorbeeld-elise-van-acker-' . $work . '.webp';
    if (!is_file($asset) || filesize($asset) < 1000) {
        throw new RuntimeException('Missing fictitious work image: ' . $work);
    }
    $header = file_get_contents($asset, false, null, 0, 12);
    if (!is_string($header) || substr($header, 0, 4) !== 'RIFF' ||
        substr($header, 8, 4) !== 'WEBP') {
        throw new RuntimeException('Fictitious artwork asset is not WebP: ' . $work);
    }
}
foreach ([
    '.lot-invite-demo{display:grid;',
    'grid-template-columns:minmax(0,1.05fr) minmax(0,.95fr)',
    '.lot-invite .artist-invite-copy{max-width:650px}',
    '.lot-invite .artist-invite-contact{margin-top:auto;align-self:flex-start}',
    '@media(max-width:1050px)',
    '@media(max-width:600px)',
    '.lot-invite-demo{grid-template-columns:1fr;',
] as $rule) {
    if (!str_contains($css, $rule)) {
        throw new RuntimeException('Artwork-interest layout rule missing: ' . $rule);
    }
}
if (!str_contains($sharedCss, '.artist-invite-grid{display:grid;')) {
    throw new RuntimeException('New page must reuse the shared invitation layout.');
}
echo "PASS artwork-interest route, fictitious detail preview, mail CTA and responsive layout.\n";
