<?php
declare(strict_types=1);
require dirname(__DIR__) . '/private/bootstrap.php';
require_once dirname(__DIR__) . '/private/public_social_footer.php';
require_public_site_online();
require_once dirname(__DIR__) . '/private/visitor_stats.php';
track_catalog_view();
$photos = [
  ['zaal', 'Samen in de zaal', 'Bezoekers in de rode theaterstoelen tijdens de vorige veiling', 1600, 1200],
  ['kijkmoment', 'Bijzondere stukken ontdekken', 'Schilderijen en andere kavels uitgestald tijdens het kijkmoment', 1600, 900],
  ['ontmoeting', 'Kijken, ontmoeten, kiezen', 'Bezoekers bekijken de uitgestalde kavels in de tentoonstellingsruimte', 1600, 900],
  ['podium', 'Elk kavel heeft een verhaal', 'Een spreker in het rood presenteert een kavel naast een bakfiets op het podium', 1600, 1067],
  ['publiek', 'Een zaal vol aandacht', 'Het publiek volgt de veiling vanuit de theaterzaal', 1600, 1067],
  ['veilingteam', 'Samen achter de veilingtafel', 'Drie leden van het veilingteam achter de tafel op het podium', 1600, 1067],
  ['veilingmeester', 'Onder de hamer', 'De veilingmeester aan het woord bij de microfoon', 1600, 1067],
  ['kavel', 'Kunst in de schijnwerpers', 'Een schilderij wordt op het grote scherm tijdens de veiling getoond', 1600, 1200],
  ['samenspel', 'In gesprek met de zaal', 'Het veilingteam maakt contact met het publiek vanuit de veilingtafel', 1600, 1067],
];
function impression_photo(array $photo, int $index, string $class = ''): void {
  [$name, , $alt, $width, $height] = $photo;
  $small = url('assets/vorige-editie-' . $name . '-800.webp');
  $large = url('assets/vorige-editie-' . $name . '-1600.webp');
  ?>
  <figure class="impression-photo <?=e($class)?>">
    <div class="impression-photo-image">
      <img src="<?=e($index === 0 ? $large : $small)?>" <?php if ($index === 0): ?>srcset="<?=e($small)?> 800w, <?=e($large)?> 1600w" sizes="(min-width: 900px) 54vw, 100vw" fetchpriority="high"<?php else: ?>loading="lazy"<?php endif; ?> width="<?=$width?>" height="<?=$height?>" alt="<?=e($alt)?>" decoding="async">
    </div>
  </figure>
  <?php
}
?><!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="theme-color" content="#850015">
  <meta name="description" content="Blik terug op de vorige succesvolle veiling van 41 Lokeren. Een impressie in foto's: bijzondere kavels, een betrokken publiek en samen iets betekenen.">
  <title>De vorige editie in beeld · 41 Lokeren</title>
  <link rel="icon" type="image/png" href="<?=e(url('favicon.png'))?>">
  <link rel="stylesheet" href="<?=e(url('assets/catalogus.css'))?>?v=187">
  <link rel="stylesheet" href="<?=e(url('assets/home-v18.css'))?>?v=184">
  <link rel="stylesheet" href="<?=e(url('assets/vorige-editie.css'))?>?v=2">
</head>
<body class="impression-page">
<?php admin_preview_banner(); ?>
<a class="impression-skip" href="#impressie">Naar de inhoud</a>
<header class="site-header front-header" id="top">
  <div class="shell header-inner front-header-inner">
    <a class="brand front-brand" href="<?=e(url('index.php'))?>" aria-label="41 Lokeren - naar startpagina">
      <span class="brand-mark" aria-hidden="true">41</span>
      <span class="brand-name">41 LOKEREN<small>KUNST · LIFESTYLE · VINTAGE</small></span>
    </a>
    <nav class="header-nav front-nav" aria-label="Hoofdnavigatie">
      <a href="<?=e(url('index.php'))?>">Home</a>
      <a href="<?=e(url('vorige-editie.php'))?>" aria-current="page">Vorige editie</a>
      <a href="<?=e(url('index.php'))?>#informatie">Veilinginfo</a>
      <a class="front-header-cta" href="<?=e(url('index.php'))?>">Naar de huidige veiling →</a>
    </nav>
  </div>
</header>
<main id="impressie">
  <section class="impression-hero shell" aria-labelledby="impression-title">
    <div class="impression-hero-copy">
      <p class="impression-eyebrow"><span aria-hidden="true"></span> TERUGBLIK · 41 LOKEREN</p>
      <h1 id="impression-title">Een veiling<br>om op <em>terug<br> te kijken.</em></h1>
      <p class="impression-lead">Bijzondere stukken. Een betrokken publiek.<br>En vooral: samen iets betekenen.</p>
      <p class="impression-intro">De vorige editie bracht kunst, ontmoeting en engagement samen. Deze beelden nemen je mee naar het kijkmoment, de zaal en de veiling op het podium.</p>
      <a class="front-btn front-btn-primary" href="#in-beeld">Beleef de sfeer <span aria-hidden="true">↓</span></a>
      <div class="impression-hero-footnote"><span class="impression-footnote-line" aria-hidden="true"></span> Een geslaagde editie, in negen beelden.</div>
    </div>
    <div class="impression-hero-visual">
      <?php impression_photo($photos[0], 0, 'impression-cover'); ?>
      <span class="impression-seal" aria-hidden="true">SAMEN VOOR<br><strong>het goede<br>doel.</strong></span>
    </div>
  </section>
  <div class="impression-ribbon"><div class="shell"><span>DE KRACHT VAN SAMEN</span><p>Van een eerste blik op een kavel tot alle aandacht in de zaal.</p><span aria-hidden="true">✳</span></div></div>
  <section class="impression-discover shell" aria-labelledby="discover-title">
    <div class="impression-section-heading">
      <div><p class="impression-eyebrow">HET KIJKMOMENT</p><h2 id="discover-title">Er viel heel wat<br>te <em>ontdekken.</em></h2></div>
      <p>Even van dichtbij kijken, een favoriet ontdekken en met elkaar in gesprek gaan. De sfeer begon al tussen de uitgestalde kavels.</p>
    </div>
    <div class="impression-pair">
      <?php impression_photo($photos[1], 1); ?>
      <?php impression_photo($photos[2], 2); ?>
    </div>
  </section>
  <section class="impression-gallery shell" id="in-beeld" aria-labelledby="gallery-title">
    <div class="impression-section-heading">
      <div><p class="impression-eyebrow">DE VEILING IN BEELD</p><h2 id="gallery-title">Alle ogen op<br>het <em>podium.</em></h2></div>
      <div><p>Presentaties, bijzondere kavels en het samenspel tussen veilingteam en publiek. Een terugblik op wat deze editie zo bijzonder maakte.</p></div>
    </div>
    <div class="impression-gallery-grid">
      <?php for ($i = 3; $i < count($photos); $i++): impression_photo($photos[$i], $i); endfor; ?>
    </div>
  </section>
  <section class="impression-video shell" aria-labelledby="impression-video-title">
    <div class="impression-video-intro">
      <p class="impression-eyebrow">IN DE KIJKER · TV OOST</p>
      <h2 id="impression-video-title">Alderande vzw:<br><em>10 jaar soepkar.</em></h2>
      <p>TV Oost bracht een reportage over tien jaar soepkar in Lokeren en de gloednieuwe soepkar van Alderande vzw. Bekijk het verhaal bij de oorspronkelijke uitgever.</p>
    </div>
    <a class="impression-video-card" href="https://www.tvoost.be/nieuws/alderande-vzw-in-lokeren-viert-tien-jaar-soepkar-met-een-gloednieuwe-soepkar-209982" target="_blank" rel="noopener noreferrer" aria-label="Bekijk de TV Oost-reportage over de soepkar van Alderande vzw (opent in een nieuw tabblad)">
      <span class="impression-video-play" aria-hidden="true">▶</span>
      <span class="impression-video-card-copy">
        <span class="impression-video-kicker">REPORTAGE · TV OOST</span>
        <strong>Bekijk de reportage</strong>
        <span>Opent bij TV Oost <span aria-hidden="true">↗</span></span>
      </span>
    </a>
  </section>
  <section class="impression-next shell" aria-labelledby="next-title">
    <p class="impression-eyebrow">HET VERHAAL GAAT VERDER</p>
    <h2 id="next-title">De volgende herinnering<br>maken we <em>samen.</em></h2>
    <p>Laat je inspireren door de vorige editie en ontdek wat de huidige veiling in petto heeft.</p>
    <div class="impression-next-actions"><a class="front-btn front-btn-primary" href="<?=e(url('index.php'))?>">Naar de huidige veiling <span aria-hidden="true">→</span></a><a class="impression-text-link" href="<?=e(url('index.php'))?>#informatie">Praktische informatie →</a></div>
  </section>
</main>
<footer class="footer"><div class="shell footer-inner"><span class="footer-brand"><strong>41 LOKEREN · SAMEN VOOR HET GOEDE DOEL</strong></span><p>Een impressie van de vorige editie.</p><a href="<?=e(url('privacy.php'))?>">Privacy</a><?php public_social_footer(); ?></div></footer>

</body>
</html>
