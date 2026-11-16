<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$home = file_get_contents($root . '/catalogus-public/index.php');
if (!is_string($home)) {
    throw new RuntimeException('Homepage source unavailable.');
}
foreach (['class="info-section"', 'id="informatie"', 'GOED OM TE WETEN', 'Een bijzonder moment<br>', 'href="#informatie"'] as $old) {
    if (str_contains($home, $old)) {
        throw new RuntimeException('Removed practical info section/link remains: ' . $old);
    }
}
$photo = "url('assets/samen-kunst-verbindt-foto-1.webp')";
if (!str_contains($home, $photo) || !str_contains($home, 'Jonge kunstliefhebbers ontdekken samen')) {
    throw new RuntimeException('Chosen new photo is not referenced from the homepage.');
}
if (str_contains($home, "url('assets/veiling-kunstselectie.webp')")) {
    throw new RuntimeException('Former gallery image is still referenced.');
}
foreach (['catalogus-public/goede-doel.php', 'catalogus-public/vorige-editie.php',
          'catalogus-public/kunstenaar.php', 'catalogus-public/kavel.php'] as $file) {
    $source = file_get_contents($root . '/' . $file);
    if (!is_string($source)) {
        throw new RuntimeException('Missing page: ' . $file);
    }
    if (str_contains($source, '#informatie')) {
        throw new RuntimeException('Broken practical info anchor remains in ' . $file);
    }
}
echo "PASS: homepage info section removed and photo 1 selected.\n";
