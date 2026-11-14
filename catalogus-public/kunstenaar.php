<?php
declare(strict_types=1);
require dirname(__DIR__) . '/private/bootstrap.php';
require_once dirname(__DIR__) . '/private/public_social_footer.php';
require_once dirname(__DIR__) . '/private/lot_artist.php';
require_public_site_online();
$settings = settings();
$artistId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if (!$artistId || ((int)($settings['is_published'] ?? 0) !== 1 && !admin_preview_active())) {
  http_response_code(404);
  exit('Kunstenaar niet gevonden.');
}
// Only explicit public artist fields may leave seller management. Never SELECT *,
// contact information, bank data, creator identity, or unrelated sellers.
// Artist publication does not depend on whether linked lots are published.
$stmt = db()->prepare("
  SELECT s.artist_name, s.artist_slogan, s.artist_intro, s.artist_url
  FROM lot_sellers s
  WHERE s.id=? AND s.seller_type='kunstenaar' AND s.artist_name<>'' AND s.artist_published=1
  LIMIT 1");
$stmt->execute([$artistId]);
$artist = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$artist) { http_response_code(404); exit('Kunstenaar niet gevonden.'); }
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
try {
  [, $artistWebsite] = lot_artist_validate($name, (string)($artist['artist_url'] ?? ''));
} catch (InvalidArgumentException) {
  // Invalid legacy URLs must never become public hyperlinks.
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
  <link rel="stylesheet" href="<?=e(url('assets/catalogus.css'))?>?v=192">
</head>
<body>
<?php admin_preview_banner(); ?>
<header class="site-header"><div class="shell header-inner">
  <a href="<?=e(url('index.php'))?>" class="brand"><span class="brand-mark">41</span><span class="brand-name">41 LOKEREN<small>KUNST · LIFESTYLE · VINTAGE</small></span></a>
  <nav class="header-nav"><a href="<?=e(url('kunstenaars.php'))?>">← Alle kunstenaars</a><a href="<?=e(url('index.php'))?>#catalogus">Alle kavels</a><a href="<?=e(url('index.php'))?>#informatie">Praktisch</a></nav>
</div></header>
<main class="artist-profile shell artist-brochure">
  <a class="back-link" href="<?=e(url('kunstenaars.php'))?>">← Terug naar alle kunstenaars</a>
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
    <?php if ($artistWebsite !== ''): ?>
      <p class="artist-brochure-website"><a href="<?=e($artistWebsite)?>" target="_blank" rel="noopener noreferrer">Website van de kunstenaar ↗</a></p>
    <?php endif; ?>
  </article>
</main>
<footer class="footer"><div class="shell footer-inner"><span class="footer-brand"><strong>41 LOKEREN · VEILING 2027</strong></span><a href="<?=e(url('privacy.php'))?>">Privacy</a><a href="https://pecharko-website.pages.dev/">Designed by Pecharko</a><a href="<?=e(url('index.php'))?>#catalogus">Terug naar catalogus</a><?php public_social_footer(); ?></div></footer>
</body>
</html>
