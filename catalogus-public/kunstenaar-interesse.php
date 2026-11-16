<?php
declare(strict_types=1);
require dirname(__DIR__) . '/private/bootstrap.php';
require_once dirname(__DIR__) . '/private/public_social_footer.php';
require_public_site_online();

// Choose only an explicitly published artist with a complete and valid
// public portrait/gallery. Prefer the local synthetic artist Mira Veld.
// Other published artists may serve as a live example outside the preview.
// Never select contact, payment, audit or unpublished seller information.
$sampleArtist = null;
$samplePortrait = '';
$sampleWorks = [];
$allowSyntheticImages = getenv('LOCAL_PREVIEW_ISOLATED') === '1'
  && cfg('setup_key') === 'local-preview-only-nonproduction';
$candidates = db()->query("
  SELECT s.id, s.artist_name, s.artist_slogan, s.artist_intro
  FROM lot_sellers s
  WHERE s.seller_type='kunstenaar' AND s.artist_published=1 AND s.artist_name<>''
  ORDER BY CASE WHEN s.artist_name='Mira Veld' THEN 0 ELSE 1 END, s.artist_name, s.id
  LIMIT 40")->fetchAll(PDO::FETCH_ASSOC);
$photosStmt = db()->prepare("
  SELECT image_kind, slot, path
  FROM seller_images
  WHERE seller_id=? AND ((image_kind='portrait' AND slot=1)
    OR (image_kind='work' AND slot BETWEEN 1 AND 3))
  ORDER BY CASE WHEN image_kind='portrait' THEN 0 ELSE 1 END, slot
  LIMIT 4");
foreach ($candidates as $candidate) {
  $photosStmt->execute([(int)$candidate['id']]);
  $portrait = '';
  $works = [];
  foreach ($photosStmt->fetchAll(PDO::FETCH_ASSOC) as $photo) {
    $photoPath = (string)$photo['path'];
    $validUpload = preg_match('~^uploads/[0-9]{4}/[0-9]{2}/[a-f0-9]{32}\\.(?:webp|jpg|png)$~D', $photoPath) === 1;
    $validFixture = $allowSyntheticImages
      && preg_match('~^uploads/preview-fixtures/artist-[1-3]-(?:portrait-1|work-[1-3])\\.webp$~D', $photoPath) === 1;
    if ((!$validUpload && !$validFixture) || !is_file(__DIR__ . '/' . $photoPath)) continue;
    if ($photo['image_kind'] === 'portrait' && (int)$photo['slot'] === 1) {
      $portrait = $photoPath;
    } elseif ($photo['image_kind'] === 'work') {
      $works[(int)$photo['slot']] = $photoPath;
    }
  }
  if ($portrait !== '' && isset($works[1], $works[2], $works[3])) {
    $sampleArtist = $candidate;
    $samplePortrait = $portrait;
    $sampleWorks = $works;
    break;
  }
}
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
  <link rel="stylesheet" href="<?=e(url('assets/catalogus.css'))?>?v=195">
  <link rel="stylesheet" href="<?=e(url('assets/home-v18.css'))?>?v=184">
  <link rel="stylesheet" href="<?=e(url('assets/kunstenaar-interesse.css'))?>?v=1">
</head>
<body>
<?php admin_preview_banner(); ?>
<a class="artist-invite-skip" href="#inhoud">Naar de inhoud</a>
<header class="site-header front-header">
  <div class="shell header-inner front-header-inner">
    <a class="brand front-brand" href="<?=e(url('index.php'))?>" aria-label="41 Lokeren - naar startpagina">
      <span class="brand-mark" aria-hidden="true">41</span>
      <span class="brand-name">41 LOKEREN<small>KUNST · LIFESTYLE · VINTAGE</small></span>
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
    <a class="front-btn front-btn-outline artist-invite-return" href="<?=e(url('index.php'))?>">Terug naar de startpagina <span aria-hidden="true">→</span></a>
    <header class="artist-invite-heading">
      <p class="front-eyebrow">SAMEN GEVEN WE KUNST EEN PODIUM</p>
      <h1>Ik ben kunstenaar en heb interesse</h1>
      <p>Heb je zin om met één of meerdere kunstwerken bij te dragen aan onze veiling voor het goede doel? Dan horen we heel graag van je!</p>
    </header>
    <div class="artist-invite-grid">
      <section class="artist-invite-copy" aria-label="Deelnemen als kunstenaar">
        <p>Stuur ons gerust een mailtje naar <a href="mailto:veiling@41lokeren.be"><strong>veiling@41lokeren.be</strong></a>. Een van onze veilingmedewerkers neemt graag persoonlijk contact met je op om meer te vertellen over ons initiatief, hoe de veiling verloopt en wat we samen kunnen betekenen.</p>
        <p><strong>Je bepaalt bovendien helemaal zelf welk deel van de verkoopprijs je aan het goede doel wilt schenken.</strong> Zo draag je bij op een manier die voor jou goed voelt.</p>
        <p>Ook voor jou als kunstenaar willen we iets betekenen. We maken er graag werk van om <strong>jou en je kunstwerken extra zichtbaarheid te geven via onze website en de bijbehorende sociale media</strong>. Zo hopen we jouw werk onder de aandacht te brengen van een breder publiek en nieuwe kunstliefhebbers.</p>
        <p>Alvast een warme dankjewel voor je interesse. <strong>Samen geven we kunst een podium én maken we het verschil voor het goede doel!</strong></p>
        <a class="front-btn front-btn-primary artist-invite-contact" href="mailto:veiling@41lokeren.be">Mail ons jouw interesse <span aria-hidden="true">→</span></a>
      </section>
      <aside class="artist-invite-example" aria-labelledby="artist-invite-example-title">
        <div class="artist-invite-example-intro">
          <p class="front-eyebrow">JOUW VERHAAL IN BEELD</p>
          <h2 id="artist-invite-example-title">Zo kan jouw kunstenaarspagina eruitzien</h2>
          <p>Een kleine impressie van de persoonlijke pagina waarmee we jou en je werk voorstellen.</p>
        </div>
        <?php if ($sampleArtist !== null): ?>
          <?php $sampleName = (string)$sampleArtist['artist_name']; ?>
          <div class="artist-invite-sample" aria-label="Voorbeeld van een kunstenaarspagina met portret en kunstwerken">
            <p class="artist-invite-sample-notice"><?=e($allowSyntheticImages && $sampleName === 'Mira Veld' ? 'FICTIEF VOORBEELD' : 'VOORBEELD VAN EEN DEELNEMENDE KUNSTENAAR')?></p>
            <div class="artist-invite-sample-sheet">
              <header class="artist-invite-sample-heading">
                <p>ONTMOET DE KUNSTENAAR</p>
                <h3><?=e($sampleName)?></h3>
                <?php if (trim((string)$sampleArtist['artist_slogan']) !== ''): ?>
                  <div class="artist-invite-sample-tagline">
                    <span aria-hidden="true"></span>
                    <p><?=e($sampleArtist['artist_slogan'])?></p>
                    <span aria-hidden="true"></span>
                  </div>
                <?php endif; ?>
              </header>
              <div class="artist-invite-sample-columns">
                <div class="artist-invite-sample-column">
                  <figure class="artist-invite-sample-portrait">
                    <img src="<?=e(url($samplePortrait))?>" alt="Portret van <?=e($sampleName)?>" loading="lazy" decoding="async">
                  </figure>
                  <figure class="artist-invite-sample-art artist-invite-sample-art--one">
                    <img src="<?=e(url($sampleWorks[1]))?>" alt="Kunstwerk 1 van <?=e($sampleName)?>" loading="lazy" decoding="async">
                  </figure>
                </div>
                <div class="artist-invite-sample-column">
                  <?php if (trim((string)$sampleArtist['artist_intro']) !== ''): ?>
                    <p class="artist-invite-sample-bio"><?=nl2br(e($sampleArtist['artist_intro']))?></p>
                  <?php endif; ?>
                  <figure class="artist-invite-sample-art artist-invite-sample-art--two">
                    <img src="<?=e(url($sampleWorks[2]))?>" alt="Kunstwerk 2 van <?=e($sampleName)?>" loading="lazy" decoding="async">
                  </figure>
                  <figure class="artist-invite-sample-art artist-invite-sample-art--three">
                    <img src="<?=e(url($sampleWorks[3]))?>" alt="Kunstwerk 3 van <?=e($sampleName)?>" loading="lazy" decoding="async">
                  </figure>
                </div>
              </div>
            </div>
          </div>
        <?php else: ?>
          <p class="artist-invite-example-unavailable">Zodra de deelnemende kunstenaars gepubliceerd zijn, tonen we hier een voorbeeld met een echt portret en drie werken.</p>
        <?php endif; ?>
        <p class="artist-invite-example-note">Jouw eigen pagina wordt gevuld met je naam, je verhaal en foto's van jouw kunstwerken.</p>
      </aside>
    </div>
  </div>
</main>
<footer class="footer">
  <div class="shell footer-inner">
    <span class="footer-brand"><strong>41 LOKEREN · VEILING 2027</strong></span>
    <a href="<?=e(url('privacy.php'))?>">Privacy</a>
    <a href="<?=e(url('index.php'))?>">Terug naar startpagina</a>
    <?php public_social_footer(); ?>
  </div>
</footer>
</body>
</html>
