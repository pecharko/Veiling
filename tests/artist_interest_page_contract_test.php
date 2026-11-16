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
if (!str_contains($home, '<button class="front-btn front-btn-outline front-impact-action" type="button" disabled title="Binnenkort beschikbaar">Ik heb een kunstwerk voor de veiling</button>')) {
    throw new RuntimeException('Separate artwork-interest flow must remain unchanged and disabled.');
}
foreach ([
    'require_public_site_online();',
    'admin_preview_banner();',
    'public_social_footer();',
    '<main id="inhoud"',
    '<nav class="header-nav front-nav"',
    '<h1>Ik ben kunstenaar en heb interesse</h1>',
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
    'artist-invite-sample-art--three',
] as $required) {
    if (!str_contains($page, $required)) {
        throw new RuntimeException('Missing artist interest page requirement: ' . $required);
    }
}
if (str_contains($page, 'SELECT ') || str_contains($page, 'seller_images')
    || str_contains($page, 'lot_sellers') || str_contains($page, '<form')) {
    throw new RuntimeException('Example must be static and must not expose seller data or collect submissions.');
}
if (!str_contains($css, '@media(max-width:600px)')
    || !str_contains($css, '@media(max-width:900px)')
    || !str_contains($homeCss, '.front-impact-actions .front-impact-action:not([disabled])')) {
    throw new RuntimeException('Missing responsive design or enabled CTA styling.');
}
if (substr_count($page, 'href="mailto:veiling@41lokeren.be"') !== 2) {
    throw new RuntimeException('Artist participation contact must use the specified mail address.');
}

echo "PASS: artist-interest page and homepage CTA with static brochure, copy and mobile styles.\n";
