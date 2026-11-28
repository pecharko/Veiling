<?php
declare(strict_types=1);
require dirname(__DIR__) . '/private/bootstrap.php';
require_once dirname(__DIR__) . '/private/public_social_footer.php';
require_public_site_online();
$published = (int)(settings()['is_published'] ?? 0) === 1 || admin_preview_active();
?><!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="theme-color" content="#850015">
  <meta name="description" content="Heb je een kunstwerk voor de veiling van 41 Lokeren? Ontdek hoe we jouw werk presenteren en hoe je kunt bijdragen aan het goede doel.">
  <title>Ik heb een kunstwerk voor de veiling · 41 Lokeren</title>
  <?php if (!admin_preview_active()): ?><link rel="canonical" href="https://41lokeren.be/kunstwerk-interesse.php"><?php endif; ?>
  <link rel="icon" type="image/png" href="<?=e(url('favicon.png'))?>">
  <link rel="stylesheet" href="<?=e(url('assets/catalogus.css'))?>?v=196">
  <link rel="stylesheet" href="<?=e(url('assets/home-v18.css'))?>?v=184">
  <link rel="stylesheet" href="<?=e(url('assets/public-buttons.css'))?>?v=5">
  <link rel="stylesheet" href="<?=e(url('assets/kunstenaar-interesse.css'))?>?v=5">
  <link rel="stylesheet" href="<?=e(url('assets/kunstwerk-interesse.css'))?>?v=1">
  <link rel="stylesheet" href="<?=e(url('assets/public-footer.css'))?>?v=1">
</head>
<body>
<?php admin_preview_banner(); ?>
<a class="artist-invite-skip" href="#inhoud">Naar de inhoud</a>
<header class="site-header front-header">
  <div class="shell header-inner front-header-inner">
    <a class="brand front-brand" href="<?=e(url('index.php'))?>" aria-label="41 Lokeren - naar startpagina">
      <span class="brand-mark" aria-hidden="true"></span>
      <span class="brand-name">41 LOKEREN <small>KUNSTVEILING</small></span>
    </a>
    <nav class="header-nav front-nav" aria-label="Hoofdnavigatie">
      <a href="<?=e(url('index.php'))?>">Home</a>
      <?php if ($published): ?><a href="<?=e(url('index.php'))?>#catalogus">Bekijk kavels</a><?php endif; ?>
      <a href="<?=e(url('kunstenaars.php'))?>">Kunstenaars</a>
      <a href="<?=e(url('goede-doel.php'))?>">Het goede doel</a>
      <a href="<?=e(url('vorige-editie.php'))?>">Vorige editie</a>
    </nav>
    <?php require __DIR__ . '/mobile-menu.php'; ?>
  </div>
</header>
<main id="inhoud" class="artist-invite lot-invite">
  <div class="shell">
    <a class="back-link artist-invite-return" href="<?=e(url('index.php'))?>#catalogus">← Terug naar de catalogus</a>
    <div class="artist-invite-grid">
      <section class="artist-invite-copy" aria-labelledby="lot-invite-title">
        <header class="artist-invite-heading">
          <p class="front-eyebrow">SAMEN GEVEN WE KUNST EEN PODIUM</p>
          <h1 id="lot-invite-title">Ik heb een kunstwerk voor de veiling</h1>
        </header>
        <p class="artist-invite-lead">Wil je een kunstwerk bijdragen aan onze veiling voor het goede doel? Dat waarderen we enorm!</p>
        <p>Stuur ons gerust een mailtje naar <a href="mailto:veiling@41lokeren.be"><strong>veiling@41lokeren.be</strong></a>. Een van onze veilingmedewerkers neemt graag persoonlijk contact met je op om de praktische afspraken te bespreken en jouw werk zo mooi mogelijk te presenteren.</p>
        <p><strong>Jij beslist zelf welk deel van de verkoopprijs je aan het goede doel wilt schenken.</strong> Zo kun je bijdragen op een manier die bij jou past.</p>
        <p>We geven jouw kunstwerk een mooie en herkenbare plek in onze online catalogus, met een duidelijke voorstelling, goede foto's en, indien gewenst, een verwijzing naar de maker.</p>
        <a class="front-btn front-btn-primary artist-invite-contact" href="mailto:veiling@41lokeren.be">Mail ons jouw kunstwerk</a>
      </section>
      <aside class="artist-invite-example lot-invite-example" aria-label="Fictief voorbeeld van een kavelpresentatie">
        <p class="lot-invite-eyebrow">Zo kan jouw kavel op onze website verschijnen</p>
        <div class="lot-invite-demo" aria-label="Fictief voorbeeldkavel 037 met abstract schilderij en startbod">
          <div class="lot-invite-media">
            <div class="lot-invite-image">
              <img src="<?=e(url('assets/voorbeeld-elise-van-acker-werk-01.webp'))?>" alt="Fictief abstract schilderij van Elise Van Acker als voorbeeld van een veilingkavel" width="256" height="320" loading="lazy" decoding="async">
              <span class="lot-invite-badge">Veilingnr. 12</span>
              <span class="lot-invite-number">Kavel 037</span>
            </div>
            <div class="lot-invite-thumbs" aria-label="Voorbeeld van aanvullende detailfoto's">
              <img src="<?=e(url('assets/voorbeeld-elise-van-acker-werk-01.webp'))?>" alt="Voorbeeldminiatuur van hetzelfde schilderij" width="256" height="320" loading="lazy" decoding="async">
              <img src="<?=e(url('assets/voorbeeld-elise-van-acker-werk-02.webp'))?>" alt="Illustratieve detailfoto bij het fictieve kavel" width="256" height="320" loading="lazy" decoding="async">
            </div>
          </div>
          <div class="lot-invite-details">
            <p class="lot-invite-kicker">MODERNE KUNST</p>
            <h3>Tussen licht en beweging</h3>
            <p class="lot-invite-artist">Kunstenaar: <strong>Elise Van Acker</strong></p>
            <div class="lot-invite-price"><span>Startbod</span><strong>€ 350</strong></div>
            <p class="lot-invite-description">Een expressief werk waarin kleur, textuur en beweging samenkomen in een evenwichtige compositie.</p>
            <div class="lot-invite-spec">
              <strong>Details, afmetingen en staat</strong>
              <p>Acryl op doek · 80 × 100 cm · Goede staat.</p>
            </div>
          </div>
        </div>
        <p class="lot-invite-disclaimer"><strong>Fictief voorbeeld.</strong> De presentatie van jouw eigen kavel wordt ingevuld met de juiste foto's en informatie. Deze website aanvaardt geen online biedingen.</p>
      </aside>
    </div>
  </div>
</main>
<footer class="footer"><div class="shell footer-inner public-footer-inner"><span class="footer-brand"><strong>41 LOKEREN · VEILING 2027</strong></span><p class="footer-secondary-note">Geen online biedingen. Geen registratie vereist.</p><a href="<?=e(url('privacy.php'))?>">Privacy</a><a href="https://pecharko-website.pages.dev/">Designed by Pecharko</a><?php public_social_footer(); ?></div></footer>
</body>
</html>
