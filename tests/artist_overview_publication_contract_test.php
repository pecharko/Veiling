<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$admin = file_get_contents($root . '/catalogus-public/admin/instellingen.php');
$page = file_get_contents($root . '/catalogus-public/kunstenaars.php');
$seed = file_get_contents($root . '/dev/preview/seed.php');
$migration = file_get_contents($root . '/database/migrations/20261116_artist_overview_publication.sql');
foreach ([$admin, $page, $seed, $migration] as $source) {
    if (!is_string($source)) throw new RuntimeException('Artist overview publication source missing');
}
foreach ([
    "'show_artists_overview' => isset(\$_POST['show_artists_overview']) ? 1 : 0",
    'show_artists_overview = :show_artists_overview',
    'name="show_artists_overview"',
    "['show_artists_overview'] ?? 1",
    'Kunstenaarsoverzicht tonen op website',
    'check_csrf();',
] as $expected) {
    if (!str_contains($admin, $expected)) {
        throw new RuntimeException('Admin publication setting missing: ' . $expected);
    }
}
foreach ([
    '$artistsOverviewEnabled = (int)($settings[\'show_artists_overview\'] ?? 0) === 1;',
    'if ($artistsOverviewEnabled) {',
    'if (!$artistsOverviewEnabled):',
    'Binnenkort vind je hier een overzicht van de deelnemende kunstenaars.',
    "s.artist_published=1",
    'elseif (!$artists):',
] as $expected) {
    if (!str_contains($page, $expected)) {
        throw new RuntimeException('Public artists switch missing: ' . $expected);
    }
}
if (str_contains($page, "exit('Kunstenaars niet beschikbaar.')")) {
    throw new RuntimeException('Artists coming-soon page must remain reachable when catalog is unpublished');
}
if (!str_contains($seed, 'ADD COLUMN show_artists_overview TINYINT(1) NOT NULL DEFAULT 1')
    || !str_contains($migration, 'ADD COLUMN show_artists_overview TINYINT(1) NOT NULL DEFAULT 1')) {
    throw new RuntimeException('Default-on isolated seed or migration missing');
}
echo "PASS artist overview switch and public coming-soon contract\n";
