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
$heroStart = strpos($home, 'class="front-hero-actions"');
$heroEnd = $heroStart === false ? false : strpos($home, '</div>', $heroStart);
if ($heroStart === false || $heroEnd === false) {
    throw new RuntimeException('Homepage hero actions missing.');
}
$actions = substr($home, $heroStart, $heroEnd - $heroStart);
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
if (!str_contains($css, '.front-hero-actions { display:flex;') || !str_contains($css, 'flex-wrap:wrap;')) {
    throw new RuntimeException('Hero actions must wrap responsively.');
}
if (!str_contains($artists, 'if ($artistsOverviewEnabled) {')) {
    throw new RuntimeException('Artists destination must keep coming-soon behavior.');
}
echo "PASS homepage artists CTA route, text, existing design and responsive wrap.\n";
