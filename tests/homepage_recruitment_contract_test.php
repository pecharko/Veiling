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
    'class="front-recruit front-recruit-lot"',
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

$lotSection = explode(
    '</section>',
    explode('class="front-recruit front-recruit-lot"', $home, 2)[1],
    2
)[0];

foreach ([
    "url('assets/artwork-industrial-abstract.webp')",
    'Jij kiest welk deel van de verkoopprijs naar het goede doel gaat',
    'We geven jouw werk extra zichtbaarheid via onze website en sociale media',
    "url('kunstwerk-interesse.php')",
] as $needle) {
    if (substr_count($lotSection, $needle) !== 1) {
        fwrite(STDERR, "Artwork recruitment element missing or repeated: $needle\n");
        exit(1);
    }
}
if (str_contains($lotSection, 'Persoonlijke begeleiding')) {
    fwrite(STDERR, "Unexpected third artwork benefit.\n");
    exit(1);
}
$image = $root . '/catalogus-public/assets/artwork-industrial-abstract.webp';
if (!is_file($image) || filesize($image) < 1000 ||
    !getimagesize($image) ||
    getimagesize($image)[2] !== IMAGETYPE_WEBP) {
    fwrite(STDERR, "Artwork recruitment WebP missing or invalid.\n");
    exit(1);
}

echo "Homepage recruitment contract OK.\n";
