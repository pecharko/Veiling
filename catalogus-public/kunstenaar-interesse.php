<?php
declare(strict_types=1);
require dirname(__DIR__) . '/private/bootstrap.php';
require_once dirname(__DIR__) . '/private/public_social_footer.php';
require_public_site_online();

?><!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="theme-color" content="#850015">
  <meta name="description" content="Ben je kunstenaar en wil je bijdragen aan de kunstveiling voor het goede doel van 41 Lokeren? Ontdek hoe je werk een podium krijgt.">
  <title>Doe mee als kunstenaar · 41 Lokeren</title>
  <?php if (!admin_preview_active()): ?><link rel="canonical" href="https://41lokeren.be/kunstenaar-interesse.php"><?php endif; ?>
  <link rel="icon" type="image/png" href="<?=e(url('favicon.png'))?>">
  <link rel="stylesheet" href="<?=e(url('assets/catalogus.css'))?>?v=196"><link rel="stylesheet" href="<?=e(url('assets/public-buttons.css'))?>?v=3">
  <link rel="stylesheet" href="<?=e(url('assets/home-v18.css'))?>?v=184">
  <link rel="stylesheet" href="<?=e(url('assets/kunstenaar-interesse.css'))?>?v=5">
  <link rel="stylesheet" href="<?=e(url('assets/public-footer.css'))?>?v=1">
</head>
<body>
<?php admin_preview_banner(); ?>
<a class="artist-invite-skip" href="#inhoud">Naar de inhoud</a>
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
      <a href="<?=e(url('goede-doel.php'))?>">Het goede doel</a>
      <a href="<?=e(url('vorige-editie.php'))?>">Vorige editie</a>
    </nav>
  </div>
</header>
<main id="inhoud" class="artist-invite">
  <div class="shell">
    <a class="front-btn front-btn-outline artist-invite-return" href="<?=e(url('index.php'))?>">Startpagina</a>
    <div class="artist-invite-grid">
      <section class="artist-invite-copy" aria-labelledby="artist-invite-title">
        <header class="artist-invite-heading">
          <p class="front-eyebrow">SAMEN GEVEN WE KUNST EEN PODIUM</p>
          <h1 id="artist-invite-title">Ik ben kunstenaar en heb interesse</h1>
        </header>
        <p class="artist-invite-lead">Heb je zin om met één of meerdere kunstwerken bij te dragen aan onze veiling voor het goede doel? Dan horen we heel graag van je!</p>
        <p>Stuur ons gerust een mailtje naar <a href="mailto:veiling@41lokeren.be"><strong>veiling@41lokeren.be</strong></a>. Een van onze veilingmedewerkers neemt graag persoonlijk contact met je op om meer te vertellen over ons initiatief, hoe de veiling verloopt en wat we samen kunnen betekenen.</p>
        <p><strong>Je bepaalt bovendien helemaal zelf welk deel van de verkoopprijs je aan het goede doel wilt schenken.</strong> Zo draag je bij op een manier die voor jou goed voelt.</p>
        <p>Ook voor jou als kunstenaar willen we iets betekenen. We maken er graag werk van om <strong>jou en je kunstwerken extra zichtbaarheid te geven via onze website en de bijbehorende sociale media</strong>. Zo hopen we jouw werk onder de aandacht te brengen van een breder publiek en nieuwe kunstliefhebbers.</p>
        <p>Alvast een warme dankjewel voor je interesse. <strong>Samen geven we kunst een podium én maken we het verschil voor het goede doel!</strong></p>
        <a class="front-btn front-btn-primary artist-invite-contact" href="mailto:veiling@41lokeren.be">Mail ons jouw interesse</a>
      </section>
      <aside class="artist-invite-example" aria-labelledby="artist-invite-example-title">
        <div class="artist-invite-example-intro">
          <p class="front-eyebrow">JOUW VERHAAL IN BEELD</p>
          <h2 id="artist-invite-example-title">Zo kan jouw kunstenaarspagina eruitzien</h2>
          <p>Een kleine impressie van de persoonlijke pagina waarmee we jou en je werk voorstellen.</p>
        </div>
        <div class="artist-invite-sample" aria-label="Fictieve kunstenaar Elise Van Acker met portret en twee kunstwerken">
          <p class="artist-invite-sample-notice">FICTIEF VOORBEELD</p>
          <div class="artist-invite-sample-sheet">
            <header class="artist-invite-sample-heading">
              <p>ONTMOET DE KUNSTENAAR</p>
              <h3>Elise Van Acker</h3>
              <div class="artist-invite-sample-tagline">
                <span aria-hidden="true"></span>
                <p>Kleur, gevoel en beweging.</p>
                <span aria-hidden="true"></span>
              </div>
            </header>
            <div class="artist-invite-sample-columns">
              <div class="artist-invite-sample-column">
                <figure class="artist-invite-sample-portrait">
                  <img src="<?=e(url('assets/voorbeeld-elise-van-acker-portret.webp'))?>" alt="Portret van de fictieve kunstenaar Elise Van Acker" width="214" height="280" loading="lazy" decoding="async">
                </figure>
                <figure class="artist-invite-sample-art artist-invite-sample-art--one">
                  <img src="<?=e(url('assets/voorbeeld-elise-van-acker-werk-01.webp'))?>" alt="Abstract schilderij van Elise Van Acker in aardetinten en saliegroen" width="256" height="320" loading="lazy" decoding="async">
                </figure>
              </div>
              <div class="artist-invite-sample-column">
                <p class="artist-invite-sample-bio">Expressieve penseelstreken, gelaagde kleuren en abstracte composities geven haar werk een eigen karakter.</p>
                <figure class="artist-invite-sample-art artist-invite-sample-art--two">
                  <img src="<?=e(url('assets/voorbeeld-elise-van-acker-werk-02.webp'))?>" alt="Abstract schilderij van Elise Van Acker met rode, gele en witte penseelstreken" width="256" height="320" loading="lazy" decoding="async">
                </figure>
              </div>
            </div>
          </div>
        </div>
        <p class="artist-invite-example-note">Jouw eigen pagina wordt gevuld met je naam, je verhaal en foto's van jouw kunstwerken.</p>
      </aside>
    </div>
  </div>
</main>
<footer class="footer"><div class="shell footer-inner public-footer-inner"><span class="footer-brand"><strong>41 LOKEREN · VEILING 2027</strong></span><p class="footer-secondary-note">Geen online biedingen. Geen registratie vereist.</p><a href="<?=e(url('privacy.php'))?>">Privacy</a><a href="https://pecharko-website.pages.dev/">Designed by Pecharko</a><?php public_social_footer(); ?></div></footer>
</body>
</html>
