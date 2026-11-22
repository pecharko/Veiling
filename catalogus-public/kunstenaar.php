<?php
declare(strict_types=1);
require dirname(__DIR__) . '/private/bootstrap.php';
require_once dirname(__DIR__) . '/private/public_social_footer.php';
require_once dirname(__DIR__) . '/private/lot_artist.php';
require_public_site_online();
// Artist publication is independent from lot/catalog publication.
// The directory can list published artists while the catalog itself is hidden.
$artistId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if (!$artistId) {
  http_response_code(404);
  exit('Kunstenaar niet gevonden.');
}
// Only explicit public artist fields may leave seller management. Never SELECT *,
// contact information, bank data, creator identity, or unrelated sellers.
// Artist publication does not depend on whether linked lots are published.
$stmt = db()->prepare("
  SELECT s.artist_name, s.artist_slogan, s.artist_intro, s.artist_url, s.artist_instagram_url
  FROM lot_sellers s
  WHERE s.id=? AND s.seller_type='kunstenaar' AND s.artist_name<>'' AND s.artist_published=1
  LIMIT 1");
$stmt->execute([$artistId]);
$artist = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$artist) { http_response_code(404); exit('Kunstenaar niet gevonden.'); }
// Only allow a return link to a real, public lot belonging to this artist.
// Reject arbitrary URLs and unrelated, unpublished or nonexistent lots.
$returnLotNum = filter_input(INPUT_GET, 'kavel', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if ($returnLotNum) {
  $returnStmt = db()->prepare("SELECT 1 FROM lots WHERE lot_number=? AND seller_id=? AND status IN ('published','sold') LIMIT 1");
  $returnStmt->execute([$returnLotNum, $artistId]);
  if (!$returnStmt->fetchColumn()) $returnLotNum = false;
}
$fromDirectory = filter_input(INPUT_GET, 'van') === 'kunstenaars';
$photosStmt = db()->prepare("
  SELECT image_kind, slot, path
  FROM seller_images
  WHERE seller_id=? AND (image_kind='portrait' AND slot=1 OR image_kind='work' AND slot BETWEEN 1 AND 3)
  ORDER BY CASE WHEN image_kind='portrait' THEN 0 ELSE 1 END, slot
  LIMIT 4");
$photosStmt->execute([$artistId]);
$portrait = null;
$works = [];
foreach ($photosStmt->fetchAll(PDO::FETCH_ASSOC) as $photo) {
  $path = (string)$photo['path'];
  $normalUpload = preg_match('~^uploads/[0-9]{4}/[0-9]{2}/[a-f0-9]{32}\\.(?:webp|jpg|png)$~D', $path) === 1;
  // Synthetic fixtures have a separate fixed namespace. Never serve this
  // exception outside the isolated preview, even on an admin preview session.
  $syntheticPreview = getenv('LOCAL_PREVIEW_ISOLATED') === '1'
    && cfg('setup_key') === 'local-preview-only-nonproduction'
    && preg_match('~^uploads/preview-fixtures/artist-[1-3]-(?:portrait-1|work-[1-3])\\.webp$~D', $path) === 1;
  if (!$normalUpload && !$syntheticPreview) continue;
  if ($photo['image_kind']==='portrait') $portrait = $path;
  elseif ($photo['image_kind']==='work') $works[] = $path;
}
$name = (string)$artist['artist_name'];
$slogan = trim((string)$artist['artist_slogan']);
$intro = trim((string)$artist['artist_intro']);
$artistWebsite = '';
$artistInstagram = '';
try {
  [, $artistWebsite] = lot_artist_validate($name, (string)($artist['artist_url'] ?? ''));
} catch (InvalidArgumentException) {
  // Invalid legacy URLs must never become public hyperlinks.
}
try {
  $artistInstagram = artist_instagram_validate((string)($artist['artist_instagram_url'] ?? ''));
} catch (InvalidArgumentException) {
  // A manipulated or invalid legacy profile is never emitted as a link.
}
?><!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="icon" type="image/png" href="<?=e(url('favicon.png'))?>">
  <title><?=e($name)?> · Kunstenaar · 41 Lokeren</title>
  <meta name="description" content="<?=e($slogan!=='' ? $slogan : 'Ontdek kunstenaar '.$name.' bij 41 Lokeren.')?>">
  <?php if (!admin_preview_active()): ?><link rel="canonical" href="https://41lokeren.be/kunstenaar.php?id=<?=(int)$artistId?>"><?php endif; ?>
  <link rel="stylesheet" href="<?=e(url('assets/catalogus.css'))?>?v=198"><link rel="stylesheet" href="<?=e(url('assets/public-buttons.css'))?>?v=3">
  <link rel="stylesheet" href="<?=e(url('assets/public-footer.css'))?>?v=1">
</head>
<body>
<?php admin_preview_banner(); ?>
<main class="artist-profile shell artist-brochure">
  <?php if ($returnLotNum): ?>
    <a class="back-link" href="<?=e(url('kavel.php', ['nr'=>(int)$returnLotNum]))?>">← Terug naar kavel</a>
  <?php elseif ($fromDirectory || filter_has_var(INPUT_GET, 'kavel')): ?>
    <a class="back-link" href="<?=e(url('kunstenaars.php'))?>">← Terug naar alle kunstenaars</a>
  <?php else: ?>
    <a class="back-link" href="<?=e(url('index.php'))?>#catalogus">← Terug naar catalogus</a>
  <?php endif; ?>
  <article class="artist-brochure-sheet">
    <header class="artist-brochure-heading">
      <p class="eyebrow">ONTMOET DE KUNSTENAAR</p>
      <h1><?=e($name)?></h1>
      <?php if ($slogan!==''): ?>
        <div class="artist-brochure-subheading">
          <span class="artist-brochure-rule" aria-hidden="true"></span>
          <p class="artist-profile-slogan"><?=e($slogan)?></p>
          <span class="artist-brochure-rule" aria-hidden="true"></span>
        </div>
      <?php endif; ?>
    </header>
    <div class="artist-brochure-columns">
      <div class="artist-brochure-column">
        <?php if ($portrait): ?>
          <figure class="artist-profile-portrait"><img src="<?=e(url($portrait))?>" alt="Portret van <?=e($name)?>" decoding="async"></figure>
        <?php endif; ?>
        <?php if (isset($works[0])): ?>
          <figure class="artist-brochure-artwork"><img src="<?=e(url($works[0]))?>" alt="Kunstwerk 1 van <?=e($name)?>" loading="lazy" decoding="async"></figure>
        <?php endif; ?>
      </div>
      <div class="artist-brochure-column">
        <?php if ($intro!==''): ?><div class="artist-profile-intro"><?=nl2br(e($intro))?></div><?php endif; ?>
        <?php if (isset($works[1])): ?>
          <figure class="artist-brochure-artwork"><img src="<?=e(url($works[1]))?>" alt="Kunstwerk 2 van <?=e($name)?>" loading="lazy" decoding="async"></figure>
        <?php endif; ?>
        <?php if (isset($works[2])): ?>
          <figure class="artist-brochure-artwork"><img src="<?=e(url($works[2]))?>" alt="Kunstwerk 3 van <?=e($name)?>" loading="lazy" decoding="async"></figure>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($artistWebsite !== '' || $artistInstagram !== ''): ?>
      <nav class="artist-brochure-links" aria-label="Externe links van de kunstenaar">
        <?php if ($artistWebsite !== ''): ?>
          <p class="artist-brochure-website"><a class="artist-brochure-link" href="<?=e($artistWebsite)?>" target="_blank" rel="noopener noreferrer" aria-label="Website van <?=e($name)?> (opent in een nieuw tabblad)"><svg aria-hidden="true" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"/></svg><span>Website</span></a></p>
        <?php endif; ?>
        <?php if ($artistInstagram !== ''): ?>
          <p class="artist-brochure-instagram"><a class="artist-brochure-link" href="<?=e($artistInstagram)?>" target="_blank" rel="noopener noreferrer" aria-label="Instagram-profiel van <?=e($name)?> (opent in een nieuw tabblad)"><svg aria-hidden="true" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="2.5" width="19" height="19" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="18" cy="6.5" r="1" fill="currentColor" stroke="none"/></svg><span>Instagram</span></a></p>
        <?php endif; ?>
      </nav>
    <?php endif; ?>
  </article>
</main>
<footer class="footer"><div class="shell footer-inner public-footer-inner"><span class="footer-brand"><strong>41 LOKEREN · VEILING 2027</strong></span><p class="footer-secondary-note">Geen online biedingen. Geen registratie vereist.</p><a href="<?=e(url('privacy.php'))?>">Privacy</a><a href="https://pecharko-website.pages.dev/">Designed by Pecharko</a><?php public_social_footer(); ?></div></footer>
</body>
</html>
