<?php
declare(strict_types=1);
// Offline-only regression: mobile navigation and public CTA labels.
// No database, production server or browser access.
$root = dirname(__DIR__);
$css = file_get_contents($root . '/catalogus-public/assets/public-buttons.css');
if (!is_string($css) ||
    !preg_match('/@media screen and \\(max-width: 767px\\)\\s*\\{\\s*\\.site-header \\.header-nav\\s*\\{\\s*display:\\s*none\\s*!important;/s', $css)) {
    throw new RuntimeException('The desktop navigation must be hidden on small screens.');
}
foreach (['#D8C9B4', '#3B3025', '.front-btn:not(.front-impact-action)'] as $rule) {
    if (!str_contains($css, $rule)) throw new RuntimeException('Missing button rule: ' . $rule);
}
if (!str_contains($css, 'padding-top: clamp(60px, 5vw, 80px);') ||
    !str_contains($css, '.artists-overview-bottom-action {') ||
    !str_contains($css, 'margin-top: 0;') ||
    !str_contains($css, 'display: block;')) {
    throw new RuntimeException('Bottom homepage button needs independent spacing below the last artist tile.');
}
$names = [
    'index.php', 'kunstenaars.php', 'kunstenaar.php', 'kunstenaar-interesse.php',
    'goede-doel.php', 'vorige-editie.php', 'kavel.php', 'kunstwerk-interesse.php',
];
$pages = [];
foreach ($names as $name) {
    $page = file_get_contents($root . '/catalogus-public/' . $name);
    $buttonsVersion = match ($name) {
        'kavel.php' => 4,
        'kunstenaar.php' => 3,
        default => 5,
    };
    $hasHeaderNav = !in_array($name, ['kavel.php', 'kunstenaar.php'], true);
    if (!is_string($page) || !str_contains($page, "assets/public-buttons.css'))?>?v=" . $buttonsVersion) ||
        str_contains($page, 'class="header-nav') !== $hasHeaderNav ||
        str_contains($page, "require __DIR__ . '/mobile-menu.php'") !== $hasHeaderNav) {
        throw new RuntimeException('Missing public button stylesheet or desktop navigation on ' . $name);
    }
    $pages[$name] = $page;
}
foreach ([
    'index.php' => ['>Vorige editie</a>'],
    'kunstenaars.php' => ['>← Terug naar startpagina</a>', 'artists-overview-bottom-action'],
    'goede-doel.php' => ['>← Terug naar de catalogus</a>', '>Vorige editie</a>'],
    'kunstenaar-interesse.php' => ['class="back-link artist-invite-return"', '#catalogus">← Terug naar de catalogus</a>'],
    'kunstwerk-interesse.php' => ['class="back-link artist-invite-return"', '#catalogus">← Terug naar de catalogus</a>'],
    'vorige-editie.php' => ['>← Terug naar startpagina</a>'],
] as $pageName => $required) {
    foreach ($required as $item) {
        if (!str_contains($pages[$pageName], $item)) {
            throw new RuntimeException($pageName . ' missing ' . $item);
        }
    }
}
$artists = $pages['kunstenaars.php'];
if (!str_contains($artists, 'class="artists-overview-bottom-action" style="display:block;margin-top:0;padding-top:clamp(60px,5vw,80px)"') ||
    substr_count($artists, '>← Terug naar startpagina</a>') !== 2 ||
    strpos($artists, 'artists-overview-bottom-action') < strrpos($artists, '<?php endif; ?>') ||
    strpos($artists, 'artists-overview-bottom-action') > strpos($artists, '</main>')) {
    throw new RuntimeException('Homepage return links must appear above and below every state of the artist list.');
}
$home = $pages['index.php'];
foreach ([
    'kunstenaar-interesse.php' => 'Ik wil graag deelnemen',
    'kunstwerk-interesse.php' => 'Ik heb een kunstwerk',
] as $target => $caption) {
    $classes = $target === 'kunstenaar-interesse.php' ? 'front-recruit-button' : 'front-recruit-button front-recruit-button-outline';
    $expected = '<a class="' . $classes . '" href="<?=e(url(\'' . $target . '\'))?>">' . $caption . ' <span aria-hidden="true">→</span></a>';
    if (!str_contains($home, $expected)) {
        throw new RuntimeException('Both participation buttons must share the approved beige style: ' . $target);
    }
}
echo "PASS: mobile disclosure, desktop navigation, compact CTAs, artist bottom button and preserved participation buttons.\n";
