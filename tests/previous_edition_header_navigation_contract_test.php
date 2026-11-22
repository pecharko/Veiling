<?php
declare(strict_types=1);

// The previous-edition page must link to the other public sections without
// linking to itself or duplicating the current-auction CTA in the header.
$root = dirname(__DIR__);
$page = file_get_contents($root.'/catalogus-public/vorige-editie.php');
if (!is_string($page)) throw new RuntimeException('Previous edition source unavailable.');
if (!preg_match('~<nav class="header-nav front-nav" aria-label="Hoofdnavigatie">(.*?)</nav>~s', $page, $matches)) {
    throw new RuntimeException('Previous edition header navigation missing.');
}
$nav = $matches[1];
foreach ([
    '>Home</a>',
    '>Bekijk kavels</a>',
    "url('index.php')",
    "url('kunstenaars.php')",
    '>Kunstenaars</a>',
    "url('goede-doel.php')",
    '>Het goede doel</a>',
] as $expected) {
    if (!str_contains($nav, $expected)) throw new RuntimeException('Missing previous edition menu item: '.$expected);
}
foreach (['>Vorige editie</a>', "url('vorige-editie.php')", 'Naar de huidige veiling', 'front-header-cta'] as $forbidden) {
    if (str_contains($nav, $forbidden)) throw new RuntimeException('Obsolete previous edition menu item: '.$forbidden);
}
if (!str_contains($page, 'id="impressie"') || !str_contains($page, 'Alderande vzw:')) {
    throw new RuntimeException('Previous edition content must remain intact.');
}
$topAction = '<div class="impression-top-actions shell" style="padding-top:clamp(32px,4vw,56px)">';
$topActionAt = strpos($page, $topAction);
$reportageAt = strpos($page, '<section class="impression-video shell"');
if ($topActionAt === false || $reportageAt === false || $topActionAt >= $reportageAt) {
    throw new RuntimeException('Startpagina button must appear above the TV Oost section.');
}
$topSection = substr($page, $topActionAt, $reportageAt - $topActionAt);
$homepageLink = '<a class="back-link" href="<?=e(url(\'index.php\'))?>">← Terug naar startpagina</a>';
if (!str_contains($topSection, $homepageLink)
    || !str_contains($page, '<div class="impression-next-actions">' . $homepageLink . '</div>')
    || substr_count($page, $homepageLink) !== 2) {
    throw new RuntimeException('Missing shared homepage return links above and below the previous edition.');
}
if (!str_contains($page, "assets/vorige-editie.css'))?>?v=7")) {
    throw new RuntimeException('Previous edition CSS cache must be updated.');
}
$css = file_get_contents($root . '/catalogus-public/assets/vorige-editie.css');
if (!is_string($css) || !str_contains($css, '.impression-top-actions{')) {
    throw new RuntimeException('Top return action requires layout styling.');
}
if (str_contains($topSection, 'VORIGE EDITIE · 41 LOKEREN') ||
    str_contains($page, 'impression-top-label')) {
    throw new RuntimeException('Extra previous-edition label must be removed.');
}
foreach ([
    '.impression-top-actions{display:block;padding-top:clamp(32px,4vw,56px);',
    'margin-bottom:32px',
    '@media(max-width:720px){.impression-top-actions{',
    '@media(max-width:767px){.impression-page .front-header .front-nav{display:none!important}}',
    '.impression-hero{display:grid;',
] as $layoutRule) {
    if (!str_contains($css, $layoutRule)) {
        throw new RuntimeException('Missing unobtrusive top return-button layout rule: ' . $layoutRule);
    }
}
// The fixed page-level top padding keeps the button away from the header
// even when cached or inherited button styles affect the other spacing.
if (!str_contains($topSection, 'style="padding-top:clamp(32px,4vw,56px)"') ||
    str_contains($css, '.impression-top-actions{padding-top:22px;')) {
    throw new RuntimeException('Startpagina button must keep clear vertical space below the header.');
}
if (str_contains($css, '.impression-top-label{') ||
    str_contains($css, 'justify-content:space-between;flex-wrap:wrap;gap:14px 24px;')) {
    throw new RuntimeException('Old toolbar layout or label style remains.');
}
if (str_contains($css, '.front-header .front-nav > a:not(.front-header-cta){display:inline-block!important}')) {
    throw new RuntimeException('Obsolete mobile navigation override re-exposes menu items.');
}
echo "PASS previous edition header links to catalog, artists and charity without redundant actions.\n";
