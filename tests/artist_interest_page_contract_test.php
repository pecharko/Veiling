<?php
declare(strict_types=1);

// Offline contract only: no production DB, HTTP requests or uploaded images.
$root = dirname(__DIR__);
$home = file_get_contents($root . '/catalogus-public/index.php');
$page = file_get_contents($root . '/catalogus-public/kunstenaar-interesse.php');
$css = file_get_contents($root . '/catalogus-public/assets/kunstenaar-interesse.css');
$homeCss = file_get_contents($root . '/catalogus-public/assets/home-v18.css');
if (!is_string($home) || !is_string($page) || !is_string($css) || !is_string($homeCss)) {
    throw new RuntimeException('Artist interest page or styles are missing.');
}

$cta = '<a class="front-btn front-btn-sage front-impact-action" href="<?=e(url(\'kunstenaar-interesse.php\'))?>">Ik ben kunstenaar en heb interesse</a>';
if (!str_contains($home, $cta) || str_contains($home, 'type="button" disabled title="Binnenkort beschikbaar">Ik ben kunstenaar en heb interesse')) {
    throw new RuntimeException('Homepage artist action must link to an enabled local page.');
}
if (!str_contains($home, '<a class="front-btn front-btn-sage front-impact-action" href="<?=e(url(\'kunstwerk-interesse.php\'))?>">Ik heb een kunstwerk voor de veiling</a>')) {
    throw new RuntimeException('Separate artwork-interest CTA must link to the approved static information page.');
}
foreach ([
    'require_public_site_online();',
    'admin_preview_banner();',
    'public_social_footer();',
    '<main id="inhoud"',
    '<nav class="header-nav front-nav"',
    '<h1 id="artist-invite-title">Ik ben kunstenaar en heb interesse</h1>',
    'assets/kunstenaar-interesse.css',
    'href="<?=e(url(\'index.php\'))?>"',
    'href="mailto:veiling@41lokeren.be"',
    'Heb je zin om met één of meerdere kunstwerken',
    'Dan horen we heel graag van je!',
    'Een van onze veilingmedewerkers neemt graag persoonlijk contact',
    'Je bepaalt bovendien helemaal zelf welk deel van de verkoopprijs',
    'jou en je kunstwerken extra zichtbaarheid te geven via onze website en de bijbehorende sociale media',
    'Samen geven we kunst een podium én maken we het verschil voor het goede doel!',
    'Zo kan jouw kunstenaarspagina eruitzien',
    'FICTIEF VOORBEELD',
    'artist-invite-sample-portrait',
    'artist-invite-sample-art--one',
    'artist-invite-sample-art--two',
    'Elise Van Acker',
    'assets/voorbeeld-elise-van-acker-portret.webp',
    'assets/voorbeeld-elise-van-acker-werk-01.webp',
    'assets/voorbeeld-elise-van-acker-werk-02.webp',
] as $required) {
    if (!str_contains($page, $required)) {
        throw new RuntimeException('Missing artist interest page requirement: ' . $required);
    }
}
// The user-supplied example must always use exactly one portrait and two
// paintings, independently of preview DB content or artist publication.
$sampleStart = strpos($page, '<div class="artist-invite-sample"');
$sampleEnd = strpos($page, '<p class="artist-invite-example-note">', $sampleStart ?: 0);
if ($sampleStart === false || $sampleEnd === false) {
    throw new RuntimeException('Example brochure source missing.');
}
$sample = substr($page, $sampleStart, $sampleEnd - $sampleStart);
if (substr_count($sample, '<img ') !== 3 || substr_count($sample, 'artist-invite-sample-art--') !== 2
    || str_contains($sample, 'artist-invite-sample-art--three')
    || str_contains($page, 'Mira Veld')) {
    throw new RuntimeException('Example must show Elise Van Acker, one portrait and only two works.');
}
if (str_contains($page, 'SELECT ') || str_contains($page, 'db()->') || str_contains($page, '<form')) {
    throw new RuntimeException('The example must remain static and independent of the seller database.');
}
foreach (['portret','werk-01','werk-02'] as $kind) {
    $photoFile = $root . '/catalogus-public/assets/voorbeeld-elise-van-acker-' . $kind . '.webp';
    if (!is_file($photoFile) || filesize($photoFile) < 1000) {
        throw new RuntimeException('Missing optimized artist example asset: ' . $kind);
    }
    $header = file_get_contents($photoFile, false, null, 0, 12);
    if (!is_string($header) || substr($header, 0, 4) !== 'RIFF'
        || substr($header, 8, 4) !== 'WEBP') {
        throw new RuntimeException('Artist photo is not a valid WebP asset: ' . $kind);
    }
}
if (!str_contains($css, 'max-width:300px')
    || !str_contains($css, '.artist-invite-sample-portrait img')
    || !str_contains($css, '.artist-invite-sample-art img')) {
    throw new RuntimeException('Artist example must be compact and use actual photos rather than drawn placeholders.');
}
if (!str_contains($css, '@media(max-width:600px)')
    || !str_contains($css, '@media(max-width:900px)')
    || !str_contains($homeCss, '.front-impact-actions .front-impact-action:not([disabled])')) {
    throw new RuntimeException('Missing responsive design or enabled CTA styling.');
}
if (substr_count($page, 'href="mailto:veiling@41lokeren.be"') !== 2) {
    throw new RuntimeException('Artist participation contact must use the specified mail address.');
}

// Align the sample portrait with its biography in the same grid row;
// both artworks should start together in the second row, also on mobile.
foreach ([
    '.artist-invite-sample-column{display:contents}',
    '.artist-invite-sample-portrait{grid-column:1;grid-row:1;',
    '.artist-invite-sample-bio{grid-column:2;grid-row:1;align-self:start;',
    '.artist-invite-sample-art--one{grid-column:1;grid-row:2}',
    '.artist-invite-sample-art--two{grid-column:2;grid-row:2}',
    '.artist-invite-sample-portrait img{display:block;width:100%;height:100%;min-height:156px;',
    'grid-template-rows:minmax(145px,auto) auto',
] as $expectedAlignmentRule) {
    if (!str_contains($css, $expectedAlignmentRule)) {
        throw new RuntimeException('Missing artist example alignment rule: ' . $expectedAlignmentRule);
    }
}
if (!str_contains($page, "assets/kunstenaar-interesse.css'))?>?v=5")) {
    throw new RuntimeException('Artist interest page must refresh the sample alignment CSS cache.');
}
// The heading now belongs inside the left grid column, so the right-side
// illustration begins beside the title, as on the artwork-interest page.
$gridAt = strpos($page, '<div class="artist-invite-grid">');
$copyAt = strpos($page, '<section class="artist-invite-copy" aria-labelledby="artist-invite-title">');
$headingAt = strpos($page, '<header class="artist-invite-heading">');
$headingClose = strpos($page, '</header>', $headingAt ?: 0);
$leadAt = strpos($page, '<p class="artist-invite-lead">');
$exampleAt = strpos($page, '<aside class="artist-invite-example"');
if ($gridAt === false || $copyAt === false || $headingAt === false ||
    $headingClose === false || $leadAt === false || $exampleAt === false ||
    !($gridAt < $copyAt && $copyAt < $headingAt && $headingAt < $headingClose &&
      $headingClose < $leadAt && $leadAt < $exampleAt)) {
    throw new RuntimeException('Artist heading must be inside the left grid column, beside the example.');
}
foreach ([
    '.artist-invite-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(280px,.74fr);gap:clamp(26px,4vw,58px);align-items:stretch}',
    '.artist-invite-copy{display:flex;flex-direction:column;',
    '.artist-invite-contact{margin-top:auto;align-self:flex-start}',
    '.artist-invite-copy .artist-invite-lead{',
    '.artist-invite-grid{grid-template-columns:1fr;gap:28px}',
    '.artist-invite-contact{margin-top:12px}',
] as $rule) {
    if (!str_contains($css, $rule)) {
        throw new RuntimeException('Missing artist interest vertical alignment rule: ' . $rule);
    }
}
echo "PASS: artist-interest page with Elise Van Acker portrait, two artworks, compact layout and contact text.\n";
