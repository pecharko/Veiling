<?php
declare(strict_types=1);

// Regression: directory cards must not lead to 404 just because the lot
// catalog is unpublished. This test reads source only; it does not query OVH.
$root = dirname(__DIR__);
$directory = file_get_contents($root . '/catalogus-public/kunstenaars.php');
$profile = file_get_contents($root . '/catalogus-public/kunstenaar.php');
$lot = file_get_contents($root . '/catalogus-public/kavel.php');
if (!is_string($directory) || !is_string($profile) || !is_string($lot)) {
    throw new RuntimeException('Public detail or directory pages missing');
}
$checks = [
    'directory links to artist profile with the directory return context'
        => str_contains($directory, "url('kunstenaar.php',['id'=>(int)\$artist['id'], 'van'=>'kunstenaars'])")
            && str_contains($profile, "filter_input(INPUT_GET, 'van') === 'kunstenaars'"),
    'directory requires explicitly published artists'
        => str_contains($directory, "s.artist_published=1"),
    'profile requires public site online'
        => str_contains($profile, 'require_public_site_online();'),
    'profile validates positive artist id'
        => str_contains($profile, "'min_range'=>1"),
    'profile requires explicitly published artist'
        => str_contains($profile, "s.artist_published=1"),
    'profile requires artist seller type'
        => str_contains($profile, "s.seller_type='kunstenaar'"),
    'profile maintains 404 for absent or invalid artist'
        => substr_count($profile, "exit('Kunstenaar niet gevonden.')") === 2,
    'profile is independent of catalog publication status'
        => !str_contains($profile, "['is_published']")
            && !str_contains($profile, 'settings()'),
    'no private seller fields exposed'
        => !preg_match('/SELECT\\s+s\\.\\*/i', $profile),
    'lot has a bottom return link with the catalog target'
        => str_contains($lot, '<nav class="detail-bottom-nav" aria-label="Terug naar overzicht"><a href="<?=e(url(\'index.php\'))?>#catalogus">Terug naar de catalogus</a></nav>'),
];
foreach ($checks as $name => $passed) {
    if (!$passed) throw new RuntimeException('Artist navigation regression: ' . $name);
}
// The homepage already links to the bare profile URL. A validated lot takes
// precedence, and an explicit directory origin or an invalid lot goes to the directory.
$navStart = strpos($profile, '<main class="artist-profile shell artist-brochure">');
$navEnd = strpos($profile, '<article class="artist-brochure-sheet">', $navStart ?: 0);
if ($navStart === false || $navEnd === false) {
    throw new RuntimeException('Artist return navigation missing.');
}
$nav = substr($profile, $navStart, $navEnd - $navStart);
$expected = [
    '<?php if ($returnLotNum): ?>',
    'href="<?=e(url(\'kavel.php\', [\'nr\'=>(int)$returnLotNum]))?>">← Terug naar kavel</a>',
    '<?php elseif ($fromDirectory || filter_has_var(INPUT_GET, \'kavel\')): ?>',
    'href="<?=e(url(\'kunstenaars.php\'))?>">← Terug naar alle kunstenaars</a>',
    '<?php else: ?>',
    'href="<?=e(url(\'index.php\'))?>#catalogus">← Terug naar de catalogus</a>',
    '<?php endif; ?>',
];
$offset = 0;
foreach ($expected as $item) {
    $position = strpos($nav, $item, $offset);
    if ($position === false) {
        throw new RuntimeException('Artist profile must show the correct contextual return link.');
    }
    $offset = $position + strlen($item);
}
echo "PASS artist navigation from the catalog, directory and lot, and the lot catalog return link\n";
