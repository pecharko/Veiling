<?php
declare(strict_types=1);

// Offline source contract: no database, network or production side effects.
$root = dirname(__DIR__);
$public = [
    'index.php',
    'kavel.php',
    'kunstenaars.php',
    'kunstenaar.php',
    'kunstenaar-interesse.php',
    'kunstwerk-interesse.php',
    'goede-doel.php',
    'vorige-editie.php',
];
foreach ($public as $file) {
    $source = file_get_contents($root . '/catalogus-public/' . $file);
    if (!is_string($source)) {
        throw new RuntimeException('Public page unavailable: ' . $file);
    }
    $brandCount = in_array($file, ['kavel.php', 'kunstenaar.php'], true) ? 0 : 1;
    if (substr_count($source, '<small>KUNSTVEILING</small>') !== $brandCount ||
        str_contains($source, '<small>KUNST · LIFESTYLE · VINTAGE</small>')) {
        throw new RuntimeException('Incorrect public header subtitle: ' . $file);
    }
}
$home = file_get_contents($root . '/catalogus-public/index.php');
if (!is_string($home) ||
    !preg_match('~<h1 id="front-title">(.*?)</h1>~s', $home, $heading) ||
    trim(strip_tags($heading[1])) !== 'Kunstveiling voor het goede doel') {
    throw new RuntimeException('Homepage must show the revised auction headline.');
}
$previous = file_get_contents($root . '/catalogus-public/vorige-editie.php');
$css = file_get_contents($root . '/catalogus-public/assets/vorige-editie.css');
if (!is_string($previous) || !is_string($css) ||
    !str_contains($previous, '<div class="impression-top-actions shell" style="padding-top:clamp(32px,4vw,56px)">') ||
    !str_contains($previous, "assets/vorige-editie.css'))?>?v=8") ||
    !str_contains($css, '.impression-top-actions{display:block;padding-top:clamp(32px,4vw,56px);')) {
    throw new RuntimeException('Previous edition home button must stand clearly below the header.');
}
echo "PASS public KUNSTVEILING header, homepage auction title and previous-edition button spacing.\n";
