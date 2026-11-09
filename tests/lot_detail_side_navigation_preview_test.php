<?php
declare(strict_types=1);
// Regression: previous and next lot links are larger and vertically centred,
 // while the catalogue return link remains below the detail.
$root = dirname(__DIR__);
$detail = file_get_contents($root . '/catalogus-public/kavel.php');
$css = file_get_contents($root . '/catalogus-public/assets/catalogus.css');
if (!is_string($detail) || !is_string($css)) throw new RuntimeException('Lot detail source unavailable');
foreach (['detail-side-nav', 'detail-side-nav-prev', 'detail-side-nav-next', 'detail-side-nav-arrow'] as $class) {
  if (!str_contains($detail, 'class="' . $class . '"') && !str_contains($detail, 'class="' . $class . ' ')) throw new RuntimeException("Missing nav class: $class");
}
if (!str_contains($detail, 'aria-label="Navigeren tussen kavels"')) throw new RuntimeException('Missing accessible side navigation label');
if (!str_contains($detail, 'Vorig kavel') || !str_contains($detail, 'Volgend kavel')) throw new RuntimeException('Navigation link text missing');
if (!str_contains($detail, '<nav class="detail-bottom-nav" aria-label="Terug naar overzicht"><a') ||
    !str_contains($detail, '>Alle kavels</a></nav>')) throw new RuntimeException('Catalogue return link missing');
if (!str_contains($css, '.detail-side-nav a{position:fixed;top:50%;transform:translateY(-50%)') ||
    !str_contains($css, '.detail-side-nav-arrow{font-size:32px;') ||
    !str_contains($css, '@media(max-width:800px)')) throw new RuntimeException('Midpoint or responsive arrow CSS missing');
echo "PASS: centred previous/next navigation with larger arrows and labels.\n";
