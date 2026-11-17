<?php
declare(strict_types=1);
// Offline regression for the homepage hero artist CTA.
$root = dirname(__DIR__);
$home = file_get_contents($root . '/catalogus-public/index.php');
$css = file_get_contents($root . '/catalogus-public/assets/home-v18.css');
$artists = file_get_contents($root . '/catalogus-public/kunstenaars.php');
if (!is_string($home) || !is_string($css) || !is_string($artists)) {
    throw new RuntimeException('Homepage or artists page missing.');
}
if (!preg_match('~<div class="front-hero-actions">\\s*<div class="front-hero-action-row">(?P<top>.*?)</div>\\s*<div class="front-hero-action-row">(?P<bottom>.*?)</div>\\s*</div>~s', $home, $rows)) {
    throw new RuntimeException('Homepage hero must contain two fixed, separate action rows.');
}
$actions = $rows[0];
$topRow = $rows['top'];
$bottomRow = $rows['bottom'];
if (!str_contains($topRow, "url('goede-doel.php')") ||
    !str_contains($topRow, "url('vorige-editie.php')") ||
    str_contains($topRow, "url('kunstenaars.php')") ||
    str_contains($topRow, 'href="#catalogus"') ||
    !str_contains($bottomRow, "url('kunstenaars.php')") ||
    !str_contains($bottomRow, 'href="#catalogus"')) {
    throw new RuntimeException('First row must hold good cause/previous edition; second artists/catalog.');
}
$links = [
    "url('goede-doel.php')" => 'Meer over het goede doel',
    "url('vorige-editie.php')" => 'Vorige editie',
    "url('kunstenaars.php')" => 'Deelnemende kunstenaars',
];
foreach ($links as $href => $label) {
    if (!str_contains($actions, $href) || !str_contains($actions, $label)) {
        throw new RuntimeException('Missing homepage CTA: ' . $label);
    }
}
if (!str_contains($actions, '<a class="front-btn front-btn-outline" href="<?=e(url(\'kunstenaars.php\'))?>">Deelnemende kunstenaars')) {
    throw new RuntimeException('Artists CTA must reuse established outline button and safe URL.');
}
if (strpos($actions, "url('kunstenaars.php')") < strpos($actions, "url('vorige-editie.php')")) {
    throw new RuntimeException('Artists CTA must follow previous edition CTA.');
}
$ctaCatalog = '<?php if ($published): ?><a class="front-btn front-btn-primary" href="#catalogus">Bekijk de kavels</a><?php endif; ?>';
if (substr_count($actions, $ctaCatalog) !== 1) {
    throw new RuntimeException('Catalog CTA must retain its original link, style and publication guard.');
}
$goodCauseAt = strpos($actions, "url('goede-doel.php')");
$previousAt = strpos($actions, "url('vorige-editie.php')");
$artistsAt = strpos($actions, "url('kunstenaars.php')");
$catalogAt = strpos($actions, 'href="#catalogus"');
if ($goodCauseAt === false || $previousAt === false || $artistsAt === false || $catalogAt === false ||
    !($goodCauseAt < $previousAt && $previousAt < $artistsAt && $artistsAt < $catalogAt)) {
    throw new RuntimeException('Hero CTA order must be: good cause, previous edition, artists, then catalog.');
}
if (str_contains($actions, 'if ($published): ?><a class="front-btn front-btn-outline" href="<?=e(url(\'kunstenaars.php\'))?>')) {
    throw new RuntimeException('Artists CTA must remain available before catalog publication.');
}
if (!str_contains($css, '.front-hero-actions { display:flex; flex-direction:column;') ||
    !str_contains($css, '.front-hero-action-row { display:flex; flex-wrap:wrap;') ||
    !str_contains($css, '.front-hero-action-row { gap:8px; }') ||
    !str_contains($home, "assets/home-v18.css'))?>?v=185")) {
    throw new RuntimeException('Homepage actions require fixed rows and responsive CSS.');
}
if (!str_contains($artists, 'if ($artistsOverviewEnabled) {')) {
    throw new RuntimeException('Artists destination must keep coming-soon behavior.');
}
echo "PASS homepage artists CTA route, text, existing design and responsive wrap.\n";
