<?php
declare(strict_types=1);
// Offline contract: no production credentials/database needed.
$root=dirname(__DIR__);
$php=file_get_contents($root.'/catalogus-public/admin/verkoper.php');
$js=file_get_contents($root.'/catalogus-public/admin/seller-photo-preview.js');
$css=file_get_contents($root.'/catalogus-public/admin/admin.css');
$images=file_get_contents($root.'/private/images.php');
foreach ([
    'four artist file inputs' => substr_count((string)$php, 'data-seller-photo-input')===2
        && str_contains((string)$php, 'name="photo_work_<?=$slot?>"')
        && str_contains((string)$php, 'name="photo_portrait_1"'),
    'photo previews shipped on seller page' => str_contains((string)$php, "admin/seller-photo-preview.js")
        && str_contains((string)$php, 'id="seller-manager-form"'),
    'pending previews not uploaded immediately' => str_contains((string)$js, 'nog niet opgeslagen.')
        && !str_contains((string)$js, 'fetch('),
    'safe file validation' => str_contains((string)$js, '16 * 1024 * 1024')
        && str_contains((string)$js, '48000000')
        && str_contains((string)$js, "image/webp"),
    'preview can be removed without deleting existing image' => str_contains((string)$js, 'input.value =')
        && str_contains((string)$js, 'current.hidden = false'),
    'drag and drop keyboard picker and accessible status' => str_contains((string)$js, "zone.addEventListener('drop'")
        && str_contains((string)$php, 'aria-live="polite"')
        && str_contains((string)$css, '.seller-photo-dropzone'),
    'hide plus work when photo is present' => str_contains((string)$js, 'zone.hidden = hasPhoto')
        && str_contains((string)$js, "replace.addEventListener('click'")
        && str_contains((string)$php, 'data-seller-photo-replace')
        && str_contains((string)$css, '.seller-photo-dropzone[hidden]'),
    'artist slogan controls and persistence' => str_contains((string)$php, 'name="artist_slogan"')
        && str_contains((string)$php, 'maxlength="250"')
        && str_contains((string)$php, 'Korte voorstelling van de kunstenaar')
        && strpos((string)$php, 'name="artist_slogan"') < strpos((string)$php, 'name="artist_intro"')
        && str_contains((string)file_get_contents($root.'/private/lot_sellers.php'), "'artist_slogan'=>250"),
    'additive slogan migration' => str_contains((string)file_get_contents($root.'/database/migrations/20261112_seller_artist_slogan.sql'), 'ADD COLUMN artist_slogan VARCHAR(250)')
        && str_contains((string)file_get_contents($root.'/dev/preview/seed.php'), "SHOW COLUMNS FROM lot_sellers LIKE 'artist_slogan'"),
    'same server-side resizing as lots' => str_contains((string)$images, '1800 / max')
        && str_contains((string)$images, "imagewebp(")
        && str_contains((string)file_get_contents($root.'/private/seller_images.php'), 'store_image('),
] as $name=>$ok) {
    if (!$ok) { fwrite(STDERR,"FAIL: $name\n"); exit(1); }
}
echo "Artist photo staging contract OK\n";
