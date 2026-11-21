<?php
declare(strict_types=1);

// Contract for the two public homepage invitations, without DB or network.
$root = dirname(__DIR__);
$home = file_get_contents($root . '/catalogus-public/index.php');
$css = file_get_contents($root . '/catalogus-public/assets/home-recruitment-preview.css');
if ($home === false || $css === false) {
    fwrite(STDERR, "Homepage assets missing.\n");
    exit(1);
}
$required = [
    'class="front-recruit front-recruit-artist"',
    'class="front-recruit front-recruit-lot shell"',
    "url('assets/home-recruitment-preview.css')",
    "url('assets/artist-brushes-from-mockup.webp')",
    "url('kunstenaar-interesse.php')",
    "url('kunstwerk-interesse.php')",
    'Zichtbaarheid op onze website en sociale media',
];
foreach ($required as $needle) {
    if (substr_count($home, $needle) !== 1) {
        fwrite(STDERR, "Missing or repeated homepage element: $needle\n");
        exit(1);
    }
}
if (str_contains($home, 'class="front-impact shell"')) {
    fwrite(STDERR, "Removed legacy good-cause section is still present.\n");
    exit(1);
}
foreach (['#D8C9B4', '#3B3025', '#CBB79B', '.front-recruit-button'] as $needle) {
    if (!str_contains($css, $needle)) {
        fwrite(STDERR, "Recruitment button styling missing: $needle\n");
        exit(1);
    }
}
$image = $root . '/catalogus-public/assets/artist-brushes-from-mockup.webp';
if (!is_file($image) || filesize($image) < 1000) {
    fwrite(STDERR, "Recruitment photo missing or empty.\n");
    exit(1);
}
$metadata = getimagesize($image);
if (!$metadata || $metadata[2] !== IMAGETYPE_WEBP) {
    fwrite(STDERR, "Recruitment photo is not WebP.\n");
    exit(1);
}
echo "Homepage recruitment contract OK.\n";
