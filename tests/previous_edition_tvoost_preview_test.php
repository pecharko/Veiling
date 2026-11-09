<?php
declare(strict_types=1);
$base = dirname(__DIR__);
$page = file_get_contents($base . '/catalogus-public/vorige-editie.php');
$css = file_get_contents($base . '/catalogus-public/assets/vorige-editie.css');
if (!is_string($page) || !is_string($css)) throw new RuntimeException('Missing previous-edition assets');
function assert_reportage(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$link = 'https://www.tvoost.be/nieuws/alderande-vzw-in-lokeren-viert-tien-jaar-soepkar-met-een-gloednieuwe-soepkar-209982';
assert_reportage(str_contains($page, $link), 'TV Oost canonical link is present without Facebook tracking');
assert_reportage(str_contains($page, 'Alderande vzw:') && str_contains($page, '10 jaar soepkar'), 'Reportage headline');
assert_reportage(str_contains($page, 'aria-labelledby="impression-video-title"'), 'Accessible video section');
assert_reportage(str_contains($page, 'aria-label="Bekijk de TV Oost-reportage'), 'Accessible external media card');
assert_reportage(str_contains($page, 'target="_blank" rel="noopener noreferrer"'), 'New tab safely identified');
assert_reportage(!str_contains($page, '<iframe') && !str_contains($page, '<video'), 'No unverified embedded third-party player');
assert_reportage(str_contains($page, 'assets/vorige-editie.css\'))?>?v=3'), 'Cache busting');
assert_reportage(str_contains($css, '.impression-video{display:grid;') && str_contains($css, 'aspect-ratio:16/9'), 'Responsive feature');
assert_reportage(str_contains($css, "url('vorige-editie-soepkar-tegel.webp')"), 'Local soup cart thumbnail used by video card');
assert_reportage(str_contains($css, '@media(max-width:900px){.impression-video{grid-template-columns:1fr;'), 'Mobile feature');
assert_reportage(str_contains($page, 'impression-gallery-grid') && str_contains($page, 'impression-next shell'), 'Original photos and CTA retained');
assert_reportage(strpos($page, '<main id="impressie">') < strpos($page, 'class="impression-video shell"')
    && strpos($page, 'class="impression-video shell"') < strpos($page, 'class="impression-hero shell"')
    && strpos($page, 'class="impression-video shell"') < strpos($page, 'class="impression-gallery shell"'), 'TV Oost section first in page content, above hero and photo gallery');
echo "PASS: TV Oost previous edition feature link, accessible, responsive and preserves gallery.\n";
