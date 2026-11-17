<?php
declare(strict_types=1);

// Regression: directory cards must not lead to 404 just because the lot
// catalog is unpublished. This test reads source only; it does not query OVH.
$root = dirname(__DIR__);
$directory = file_get_contents($root . '/catalogus-public/kunstenaars.php');
$profile = file_get_contents($root . '/catalogus-public/kunstenaar.php');
if (!is_string($directory) || !is_string($profile)) {
    throw new RuntimeException('Artist public pages missing');
}
$checks = [
    'directory links to artist profile by seller id'
        => str_contains($directory, "url('kunstenaar.php',['id'=>(int)\$artist['id']])"),
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
];
foreach ($checks as $name => $passed) {
    if (!$passed) throw new RuntimeException('Artist navigation regression: ' . $name);
}
// If there is an authenticated public origin lot, the profile must show
// only its return link. Otherwise it must fall back to the artist directory.
$navStart = strpos($profile, '<main class="artist-profile shell artist-brochure">');
$navEnd = strpos($profile, '<article class="artist-brochure-sheet">', $navStart ?: 0);
if ($navStart === false || $navEnd === false) {
    throw new RuntimeException('Artist return navigation missing.');
}
$nav = substr($profile, $navStart, $navEnd - $navStart);
if (!preg_match('~<\\?php if \\(\\$returnLotNum\\): \\?>\\s*<a[^>]+>← Terug naar kavel</a>\\s*<\\?php else: \\?>\\s*<a[^>]+>← Terug naar alle kunstenaars</a>\\s*<\\?php endif; \\?>~', $nav)) {
    throw new RuntimeException('Artist profile must show exactly one contextual return link.');
}
echo "PASS published artist directory-to-profile access independent of catalog publication\n";
