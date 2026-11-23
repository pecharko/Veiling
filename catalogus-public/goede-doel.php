<?php
declare(strict_types=1);
require dirname(__DIR__) . '/private/bootstrap.php';
require_once dirname(__DIR__) . '/private/public_social_footer.php';
require_public_site_online();
?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="theme-color" content="#850015">
  <meta name="description" content="Lees waarom 41 Lokeren een veiling voor het goede doel organiseert en hoe je kunt bijdragen.">
  <title>Het goede doel · 41 Lokeren</title>
  <?php if (!admin_preview_active()): ?><link rel="canonical" href="https://41lokeren.be/goede-doel.php"><?php endif; ?>
  <link rel="icon" type="image/png" href="<?=e(url('favicon.png'))?>">
  <link rel="stylesheet" href="<?=e(url('assets/catalogus.css'))?>?v=196"><link rel="stylesheet" href="<?=e(url('assets/public-buttons.css'))?>?v=5">
  <link rel="stylesheet" href="<?=e(url('assets/home-v18.css'))?>?v=184">
  <link rel="stylesheet" href="<?=e(url('assets/goede-doel.css'))?>?v=4">
  <link rel="stylesheet" href="<?=e(url('assets/public-footer.css'))?>?v=1">
</head>
<body>
<?php admin_preview_banner(); ?>
<a class="cause-skip" href="#inhoud">Naar de inhoud</a>
<header class="site-header front-header">
  <div class="shell header-inner front-header-inner">
    <a class="brand front-brand" href="<?=e(url('index.php'))?>" aria-label="41 Lokeren - naar startpagina">
      <span class="brand-mark" aria-hidden="true">41</span>
      <span class="brand-name">41 LOKEREN<small>KUNSTVEILING</small></span>
    </a>
    <nav class="header-nav front-nav" aria-label="Hoofdnavigatie">
      <a href="<?=e(url('index.php'))?>">Home</a>
      <a href="<?=e(url('index.php'))?>#catalogus">Bekijk kavels</a>
      <a href="<?=e(url('kunstenaars.php'))?>">Kunstenaars</a>
      <a href="<?=e(url('vorige-editie.php'))?>">Vorige editie</a>
    </nav>
    <?php require __DIR__ . '/mobile-menu.php'; ?>
  </div>
</header>
<main id="inhoud">
  <section class="cause-announcement" aria-labelledby="cause-announcement-title">
    <div class="shell cause-announcement-inner">
      <a class="back-link" href="<?=e(url('index.php'))?>#catalogus">← Terug naar de catalogus</a>
      <p class="front-eyebrow">HET GOEDE DOEL VAN DE KOMENDE EDITIE</p>
      <h1 id="cause-announcement-title">Binnenkort stellen we het goede doel voor.</h1>
      <p>Welke organisatie of welk project door deze editie wordt ondersteund, maken we bekend zodra de keuze officieel is aangekondigd. Op deze pagina lees je dan meer over het initiatief en wat de steun mogelijk maakt.</p>
    </div>
  </section>
  <section class="shell cause-story" aria-labelledby="cause-story-title">
    <div>
      <p class="front-eyebrow">WAAROM WE DIT ORGANISEREN</p>
      <h2 id="cause-story-title">Bijzondere stukken.<br><em>Een betekenisvol gebaar.</em></h2>
    </div>
    <div class="cause-story-copy">
      <p>41 Lokeren organiseert een veiling voor het goede doel. Kunstenaars, schenkers, bezoekers en vrijwilligers dragen elk op hun manier bij aan een initiatief dat mensen samenbrengt.</p>
      <p>Op de website kun je de kavels ontdekken en je favorieten bewaren. Bieden gebeurt uitsluitend tijdens de fysieke veiling, niet online.</p>
    </div>
  </section>
  <section class="shell cause-last" aria-labelledby="cause-last-title">
    <h2 id="cause-last-title">Ook jouw aanwezigheid maakt het verschil.</h2>
    <p>Kom de bijzondere stukken bekijken, ontdek de verhalen achter de kavels en beleef de veiling mee.</p>
    <div class="cause-actions"><a class="back-link" href="<?=e(url('index.php'))?>#catalogus">← Terug naar de catalogus</a></div>
  </section>
</main>
<footer class="footer"><div class="shell footer-inner public-footer-inner"><span class="footer-brand"><strong>41 LOKEREN · VEILING 2027</strong></span><p class="footer-secondary-note">Geen online biedingen. Geen registratie vereist.</p><a href="<?=e(url('privacy.php'))?>">Privacy</a><a href="https://pecharko-website.pages.dev/">Designed by Pecharko</a><?php public_social_footer(); ?></div></footer>
</body>
</html>
