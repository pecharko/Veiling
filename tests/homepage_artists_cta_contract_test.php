<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$home = file_get_contents($root . '/catalogus-public/index.php');
$artists = file_get_contents($root . '/catalogus-public/kunstenaars.php');
if (!is_string($home) || !is_string($artists)) {
    throw new RuntimeException('Homepage or artists page missing.');
}

if (!preg_match('~<nav class="front-hero-actions"[^>]*>(.*?)</nav>~s', $home, $match)) {
    throw new RuntimeException('Homepage title bar must contain a navigation row.');
}
$actions = $match[1];
$links = [
    'goede-doel.php' => 'Meer over het goede doel',
    'vorige-editie.php' => 'Vorige editie',
    'kunstenaars.php' => 'Deelnemende kunstenaars',
];
$expected = '';
foreach ($links as $path => $label) {
    $expected .= '<a class="front-btn front-btn-outline" href="<?=e(url(\'' . $path . '\'))?>">' . $label . '</a>';
}
$expected .= '<?php if ($published): ?><a class="front-btn front-btn-primary" href="#catalogus">Bekijk de kavels</a><?php endif; ?>';
if (preg_replace('~>\s+<~', '><', trim($actions)) !== $expected) {
    throw new RuntimeException('Title bar buttons must keep their routes, order and plain labels; only the catalog link is conditional.');
}
if (!str_contains($home, "assets/home-v18.css'))?>?v=187")) {
    throw new RuntimeException('Homepage title bar CSS needs the updated cache version.');
}
if (!str_contains($artists, 'if ($artistsOverviewEnabled) {')) {
    throw new RuntimeException('Artists destination must keep coming-soon behavior.');
}
echo "PASS homepage title bar buttons, link order and publication guard.\n";
