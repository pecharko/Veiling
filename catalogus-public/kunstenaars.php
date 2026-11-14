<?php
declare(strict_types=1);
require dirname(__DIR__) . '/private/bootstrap.php';
require_once dirname(__DIR__) . '/private/public_social_footer.php';
require_public_site_online();
$settings = settings();
if ((int)($settings['is_published'] ?? 0) !== 1 && !admin_preview_active()) {
  http_response_code(404);
  exit('Kunstenaars niet beschikbaar.');
}
// Only public artist metadata and a single portrait path are selected.
// Eligibility is identical to the individual public artist profile.
$stmt = db()->query("
  SELECT s.id, s.artist_name, s.artist_slogan, si.path AS portrait_path
  FROM lot_sellers s
  LEFT JOIN seller_images si
    ON si.seller_id=s.id AND si.image_kind='portrait' AND si.slot=1
  WHERE s.seller_type='kunstenaar' AND s.artist_name<>'' AND s.artist_published=1
    AND EXISTS (
      SELECT 1 FROM lots l
      WHERE l.seller_id=s.id AND l.status IN ('published','sold')
    )
  ORDER BY s.artist_name, s.id
");
$artists = $stmt->fetchAll(PDO::FETCH_ASSOC);
?><!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="description" content="Ontmoet de kunstenaars achter de kunstwerken van 41 Lokeren. Ontdek hun verhaal en hun werk.">
  <title>Onze kunstenaars · 41 Lokeren</title>
  <?php if (!admin_preview_active()): ?><link rel="canonical" href="https://41lokeren.be/kunstenaars.php"><?php endif; ?>
  <link rel="icon" type="image/png" href="<?=e(url('favicon.png'))?>">
  <link rel="stylesheet" href="<?=e(url('assets/catalogus.css'))?>?v=193">
</head>
<body>
<?php admin_preview_banner(); ?>
<header class="site-header"><div class="shell header-inner">
  <a href="<?=e(url('index.php'))?>" class="brand"><span class="brand-mark">41</span><span class="brand-name">41 LOKEREN<small>KUNST · LIFESTYLE · VINTAGE</small></span></a>
  <nav class="header-nav" aria-label="Hoofdnavigatie"><a href="<?=e(url('index.php'))?>">Home</a><a href="<?=e(url('kunstenaars.php'))?>" aria-current="page">Kunstenaars</a><a href="<?=e(url('index.php'))?>#catalogus">Bekijk kavels</a></nav>
</div></header>
<main class="shell artists-overview">
  <a class="back-link" href="<?=e(url('index.php'))?>#catalogus">← Terug naar de catalogus</a>
  <div class="artists-overview-heading">
    <p class="eyebrow">DE MENSEN ACHTER DE KUNST</p>
    <h1>Ontmoet onze kunstenaars</h1>
    <p>Elk werk heeft een maker en een verhaal. Ontdek de kunstenaars die deelnemen aan de veiling van 41 Lokeren.</p>
  </div>
  <?php if (!$artists): ?>
    <p class="artists-overview-empty">Binnenkort ontdek je hier de deelnemende kunstenaars.</p>
  <?php else: ?>
    <div class="artists-overview-grid">
      <?php foreach ($artists as $artist):
        $name = trim((string)$artist['artist_name']);
        $slogan = trim((string)$artist['artist_slogan']);
        $portrait = trim((string)($artist['portrait_path'] ?? ''));
        $validUpload = preg_match('~^uploads/[0-9]{4}/[0-9]{2}/[a-f0-9]{32}\\.(?:webp|jpg|png)$~D', $portrait) === 1;
        $validFixture = getenv('LOCAL_PREVIEW_ISOLATED') === '1'
          && cfg('setup_key') === 'local-preview-only-nonproduction'
          && preg_match('~^uploads/preview-fixtures/artist-[1-3]-portrait-1\\.webp$~D', $portrait) === 1;
        $portrait = ($validUpload || $validFixture) ? $portrait : '';
      ?>
      <a class="artists-overview-card" href="<?=e(url('kunstenaar.php',['id'=>(int)$artist['id']]))?>" aria-label="Ontdek kunstenaar <?=e($name)?>">
        <span class="artists-overview-image">
          <?php if ($portrait !== ''): ?>
            <img src="<?=e(url($portrait))?>" alt="Portret van <?=e($name)?>" loading="lazy" decoding="async" width="480" height="600">
          <?php else: ?>
            <span class="artists-overview-placeholder" aria-hidden="true">41</span>
          <?php endif; ?>
        </span>
        <span class="artists-overview-card-body">
          <strong><?=e($name)?></strong>
          <?php if ($slogan !== ''): ?><span class="artists-overview-slogan"><?=e($slogan)?></span><?php endif; ?>
          <span class="artists-overview-more">Ontdek de kunstenaar <span aria-hidden="true">↗</span></span>
        </span>
      </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>
<footer class="footer"><div class="shell footer-inner"><span class="footer-brand"><strong>41 LOKEREN · VEILING 2027</strong></span><a href="<?=e(url('privacy.php'))?>">Privacy</a><a href="https://pecharko-website.pages.dev/">Designed by Pecharko</a><a href="<?=e(url('index.php'))?>#catalogus">Terug naar catalogus</a><?php public_social_footer(); ?></div></footer>
</body>
</html>
