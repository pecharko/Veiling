<?php
declare(strict_types=1);
require dirname(__DIR__) . '/private/bootstrap.php';
require_once dirname(__DIR__) . '/private/public_social_footer.php';
require_once dirname(__DIR__) . '/private/catalog_preview_modes.php';
require_once dirname(__DIR__) . '/private/lot_artist.php';
require_once dirname(__DIR__) . '/private/catalog_popular_lots.php';
if (is_post() && array_key_exists('admin_preview_action', $_POST)) {
  require_auth();
  check_csrf();
  $action = $_POST['admin_preview_action'];
  if (!is_string($action) || !in_array($action, ['start', 'stop', 'split_on', 'split_off'], true)) {
    http_response_code(400); exit('Ongeldige voorbeeldactie.');
  }
  if ($action === 'start') {
    $_SESSION['admin_catalog_preview'] = $_SESSION['admin_id'];
    unset($_SESSION['catalog_split_preview']);
  } elseif ($action === 'stop') {
    unset($_SESSION['admin_catalog_preview'], $_SESSION['catalog_split_preview']);
  } else {
    if (!admin_preview_active()) { http_response_code(403); exit('Geen actieve adminvoorbeeldsessie.'); }
    $_SESSION['catalog_split_preview'] = $action === 'split_on';
  }
  redirect('index.php');
}
require_public_site_online();
require_once dirname(__DIR__) . '/private/visitor_stats.php';
track_catalog_view();
$s = settings();
$published = (int)($s['is_published'] ?? 0) === 1 || admin_preview_active();
$splitPreview = catalog_split_active($s);
$splitSection = ($_GET['deel'] ?? '') === 'markt' ? 'markt' : 'veiling';
$onlyFavorites = isset($_GET['favorieten']) && $_GET['favorieten'] === '1';
$favoriteNumbers = [];
if ($onlyFavorites) {
  $raw = explode(',', (string)(is_string($_GET['nummers'] ?? null) ? $_GET['nummers'] : ''));
  foreach (array_slice($raw, 0, 300) as $number) {
    if (ctype_digit($number) && (int)$number > 0) $favoriteNumbers[(int)$number] = (int)$number;
  }
}
$page = max(1, min(9999, (int)($_GET['pagina'] ?? 1)));
$perPage = 24;
$where = ["l.status IN ('published','sold')"];
if ($splitPreview) $where[] = $splitSection === 'markt' ? "l.sale_location='markt'" : "l.sale_location='veiling'";
$params = [];
if ($onlyFavorites) {
  if (!$favoriteNumbers) $where[] = '1=0';
  else { $where[] = 'l.lot_number IN (' . implode(',', array_fill(0,count($favoriteNumbers),'?')) . ')'; $params = array_merge($params, array_values($favoriteNumbers)); }
}
$order = $splitPreview && $splitSection === 'veiling' ? 'l.auction_number IS NULL, l.auction_number ASC, l.lot_number ASC' : 'l.lot_number ASC';
$rows = []; $total = 0; $totalPublic = 0; $splitCounts = ['veiling'=>0,'markt'=>0];
$popularLotIds = [];
if ($published) {
  $pdo = db();
  $totalPublic = (int)$pdo->query("SELECT COUNT(*) FROM lots WHERE status IN ('published','sold')")->fetchColumn();
  if ($splitPreview) foreach ($pdo->query("SELECT sale_location, COUNT(*) n FROM lots WHERE status IN ('published','sold') GROUP BY sale_location")->fetchAll() as $x) if (isset($splitCounts[$x['sale_location']])) $splitCounts[$x['sale_location']] = (int)$x['n'];
  $whereSql = implode(' AND ', $where);
  $count = $pdo->prepare('SELECT COUNT(*) FROM lots l WHERE ' . $whereSql);
  $count->execute($params);
  $total = (int)$count->fetchColumn();
  $pages = max(1, (int)ceil($total / $perPage));
  $page = min($page, $pages);
  $offset = ($page - 1) * $perPage;
  $st = $pdo->prepare('SELECT l.*, (SELECT li.path FROM lot_images li WHERE li.lot_id=l.id ORDER BY li.sort_order, li.id LIMIT 1) AS cover FROM lots l WHERE ' . $whereSql . ' ORDER BY ' . $order . ' LIMIT ? OFFSET ?');
  $i = 1;
  foreach ($params as $param) $st->bindValue($i++, $param, is_int($param) ? PDO::PARAM_INT : PDO::PARAM_STR);
  $st->bindValue($i++, $perPage, PDO::PARAM_INT);
  $st->bindValue($i, $offset, PDO::PARAM_INT);
  $st->execute();
  $rows = $st->fetchAll();
  // One shared, read-only popularity ranking for public badges and admin statistics.
  try {
    foreach (catalog_popular_lots($pdo, $splitPreview) as $popularGroup) {
      foreach ($popularGroup as $popularLot) $popularLotIds[(int)$popularLot['lot_id']] = true;
    }
  } catch (PDOException $exception) {
    error_log('Popular lot statistics unavailable.');
  }
}
function page_link(int $number): string {
  $p = [];
  if (catalog_split_active(settings())) $p['deel'] = ($_GET['deel'] ?? '') === 'markt' ? 'markt' : 'veiling';
  if (($_GET['favorieten'] ?? '') === '1') {
    $p['favorieten'] = 1;
    if (is_string($_GET['nummers'] ?? null)) $p['nummers'] = $_GET['nummers'];
  }
  $p['pagina'] = $number;
  return url('index.php', $p) . '#catalogus';
}
$date = !empty($s['event_date']) ? date_nl($s['event_date']) : 'Verwacht in oktober 2027';
$title = (string)($s['title'] ?? 'Veilingcatalogus');
$subtitle = (string)($s['subtitle'] ?? 'Een bijzondere selectie');
?><!doctype html>
<html lang="nl"><head><link rel="icon" type="image/png" href="/favicon.png?v=3"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#850015"><meta name="description" content="<?=e($published ? "Bekijk de kavels, foto's en beschrijvingen van " . $title . ". Bieden gebeurt uitsluitend op locatie." : $title . ". Een veiling voor het goede doel, georganiseerd door 41 Lokeren.")?>"><title><?=e($title)?> | 41 Lokeren</title><?php if (!admin_preview_active()): ?><link rel="canonical" href="https://41lokeren.be/<?= $page > 1 ? '?pagina=' . $page : '' ?>"><?php endif; ?><link rel="icon" type="image/png" href="<?=e(url('favicon.png'))?>?v=18"><link rel="stylesheet" href="<?=e(url('assets/catalogus.css'))?>?v=188"><link rel="stylesheet" href="<?=e(url('assets/home-v18.css'))?>?v=184"></head>
<body data-favorites="<?=$onlyFavorites ? '1' : '0'?>">
  <?php admin_preview_banner(); ?>
  <header class="site-header front-header" id="top">
    <div class="shell header-inner front-header-inner">
      <a class="brand front-brand" href="<?=e(url('index.php'))?>" aria-label="41 Lokeren - naar startpagina">
        <span class="brand-mark" aria-hidden="true">41</span>
        <span class="brand-name">41 LOKEREN<small>KUNST · LIFESTYLE · VINTAGE</small></span>
      </a>
      <nav class="header-nav front-nav" aria-label="Hoofdnavigatie">
        <a class="front-nav-home" href="<?=e(url('index.php'))?>">Home</a>
        <?php if ($published): ?><a href="#catalogus">Bekijk kavels</a><?php endif; ?>
        <a href="#informatie">Veilinginfo</a>
        <a href="#goede-doel">Het goede doel</a>
        <?php if ((int)($s['show_report_issue_link'] ?? 0) === 1): ?><a href="https://github.com/pecharko/Veiling/issues/new/choose" target="_blank" rel="noopener noreferrer">Probleem melden</a><?php endif; ?>
        <?php if(!empty($s['contact_email'])): ?><a class="front-contact-link" href="mailto:<?=e($s['contact_email'])?>">Contact</a><?php endif; ?>
        <?php if ($published): ?><a id="favoriteLink" class="header-favorites" href="<?=e(url('index.php',['favorieten'=>1]))?>" aria-label="Mijn favorieten"><span aria-hidden="true">♡</span> <span id="favoriteCount">0</span></a>
        <a class="front-header-cta" href="#catalogus">Bekijk kavels ↗</a><?php endif; ?>
      </nav>
    </div>
  </header>
  <main>
    <section class="front-hero" aria-labelledby="front-title">
      <div class="shell front-hero-grid">
        <div class="front-hero-art">
          <img src="<?=e(url('assets/poster-41-transparent.webp'))?>" alt="Het hart en de kleurrijke veilinghamer van 41 Lokeren" width="390" height="575" fetchpriority="high">
        </div>
        <div class="front-hero-copy">
          <p class="front-eyebrow">41 LOKEREN ORGANISEERT</p>
          <h1 id="front-title">Kunst, lifestyle &amp; vintage<br><em>Veiling voor het goede doel</em></h1>
          <div class="front-subtitle-rule" aria-hidden="true"></div>
          <p class="front-hero-subline"><?=e($title)?></p>
          <p class="front-hero-intro">Kunst, lifestyle &amp; vintage — bijzondere stukken, een warm verhaal en samen iets betekenen.</p>
          <div class="front-hero-actions">
            <?php if ($published): ?><a class="front-btn front-btn-primary" href="#catalogus">Bekijk de kavels <span aria-hidden="true">↗</span></a><?php endif; ?>
            <a class="front-btn front-btn-outline" href="#goede-doel">Meer over het goede doel <span aria-hidden="true">→</span></a>
            <a class="front-btn front-btn-outline" href="<?=e(url('vorige-editie.php'))?>">Bekijk de vorige editie <span aria-hidden="true">→</span></a>
          </div>
        </div>
        <div class="front-hero-gallery" aria-hidden="true">
          <img src="<?=e(url('assets/galerij-illustratie.webp'))?>" alt="" width="435" height="368" loading="eager">
        </div>
      </div>
    </section>

    <section class="front-facts" aria-label="Veiling in het kort">
      <div class="shell front-facts-grid">
        <div class="front-fact">
          <span class="front-fact-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 10h18"/><path d="M7 14h3v3H7z"/></svg></span>
          <span><strong>Veilingsdag</strong><small><?=e($date)?></small><small><?=e($s['event_time'] ?: 'Tijdstip wordt aangekondigd')?></small></span>
        </div>
        <div class="front-fact">
          <span class="front-fact-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1116 0Z"/><circle cx="12" cy="10" r="2.7"/></svg></span>
          <span><strong>Locatie</strong><small><?=e($s['location'] ?: 'Wordt aangekondigd')?></small><?php if(!empty($s['address'])): ?><small><?=e($s['address'])?></small><?php endif; ?></span>
        </div>
        <div class="front-fact">
          <span class="front-fact-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"><path d="M20.8 5.6a5.5 5.5 0 00-7.8 0l-1 1-1-1a5.5 5.5 0 00-7.8 7.8L12 22l8.8-8.6a5.5 5.5 0 000-7.8Z"/></svg></span>
          <span><strong>Kunst, lifestyle en vintage</strong><small>Bijzondere stukken voor een goed doel</small></span>
        </div>
        <div class="front-fact front-fact-last">
          <span class="front-fact-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3"/><path d="M2 20v-2a7 7 0 0114 0v2"/><circle cx="18" cy="9" r="2"/><path d="M17.5 15.5A5 5 0 0122 20"/></svg></span>
          <span><strong>Een organisatie van<br>41 Lokeren</strong><small>Het goede doel wordt later onthuld</small></span>
        </div>
      </div>
    </section>

    <section class="front-impact shell" id="goede-doel" aria-labelledby="front-impact-title">
      <div class="front-impact-copy">
        <p class="front-eyebrow front-impact-eyebrow">SAMEN MAKEN WE HET VERSCHIL</p>
        <h2 id="front-impact-title">Een bijzondere veiling<br>voor het goede doel</h2>
        <p>Ontdek bijzondere objecten en unieke stukken. Met je interesse en aanwezigheid help je 41 Lokeren een waardevol initiatief te ondersteunen.</p>
        <p class="front-impact-note">Het goede doel wordt binnenkort bekendgemaakt.</p>
        <a class="front-btn front-btn-sage" href="#informatie">Praktische informatie <span aria-hidden="true">→</span></a>
      </div>
      <div class="front-impact-media"><img src="<?=e(url('assets/veiling-kunstselectie.webp'))?>" alt="Illustratie van een kunstselectie met schilderij, vaas en beeldhouwwerk" width="736" height="342" loading="lazy"></div>
    </section>
  <?php if ($published): ?>
  <section class="catalog-section shell" id="catalogus"><div class="section-lead"><div><p class="eyebrow dark-eyebrow">KUNST · LIFESTYLE · VINTAGE</p><h2><?= $splitPreview ? "Veiling en marktplaats" : "Ontdek de kavels" ?><span class="gold-dot">.</span></h2></div><p><?=e($s['introduction'] ?: 'Bekijk de kavels in detail en bewaar je favorieten. Bieden kan uitsluitend tijdens de fysieke veiling.')?></p></div>
  <?php if ($splitPreview): ?>
    <div class="catalog-preview-explain">
      <span class="catalog-preview-icon catalog-preview-icon--gavel"><svg viewBox="0 0 48 48" fill="currentColor" aria-hidden="true" focusable="false" data-icon="auction-gavel"><g transform="rotate(35 24 24)"><rect x="13" y="10" width="22" height="14" rx="2"/><rect x="7" y="8" width="8" height="18" rx="2"/><rect x="33" y="8" width="8" height="18" rx="2"/><rect x="21" y="23" width="6" height="19" rx="2"/></g></svg></span>
      <div class="catalog-preview-explain-copy">
        <h3>Veiling en marktplaats</h3>
        <p>De kavels zijn verdeeld in twee categorieën:</p>
        <ul>
          <li><strong>Veiling:</strong> een selectie bijzondere kavels die tijdens de benefietveiling worden toegewezen aan de hoogste bieder.</li>
          <li><strong>Marktplaats:</strong> een breed aanbod kavels die je tijdens de kijkdagen onmiddellijk kunt aankopen. Kom zeker langs, want op = op!</li>
        </ul>
      </div>
    </div>
    <nav class="catalog-preview-tabs" aria-label="Veiling of marktplaats">
      <a class="<?= $splitSection === 'veiling' ? 'active' : '' ?>" href="<?=e(url('index.php',['deel'=>'veiling']))?>" <?= $splitSection === 'veiling' ? 'aria-current="page"' : '' ?>><span class="catalog-preview-tab-icon"><svg viewBox="0 0 48 48" fill="currentColor" aria-hidden="true" focusable="false" data-icon="auction-gavel"><g transform="rotate(35 24 24)"><rect x="13" y="10" width="22" height="14" rx="2"/><rect x="7" y="8" width="8" height="18" rx="2"/><rect x="33" y="8" width="8" height="18" rx="2"/><rect x="21" y="23" width="6" height="19" rx="2"/></g></svg></span><span>Veiling (<?=$splitCounts['veiling']?>)</span></a>
      <a class="<?= $splitSection === 'markt' ? 'active' : '' ?>" href="<?=e(url('index.php',['deel'=>'markt']))?>" <?= $splitSection === 'markt' ? 'aria-current="page"' : '' ?>><span class="catalog-preview-tab-icon"><svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="3.3" stroke-linejoin="round" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M7 20 11 8h26l4 12v5c-1 4-6 5-9 1-3 4-8 4-11 0-3 4-8 4-11 0-3 4-8 3-9-1v-5z" transform="translate(3 0) scale(.88)"/><path d="M10 25v15h28V25M20 40V29h10v11"/></svg></span><span>Marktplaats (<?=$splitCounts['markt']?>)</span></a>
    </nav>
    <div class="catalog-preview-help">
      <span class="catalog-preview-icon catalog-preview-icon--small"><?= $splitSection === 'veiling' ? '<svg viewBox="0 0 48 48" fill="currentColor" aria-hidden="true" focusable="false" data-icon="auction-gavel"><g transform="rotate(35 24 24)"><rect x="13" y="10" width="22" height="14" rx="2"/><rect x="7" y="8" width="8" height="18" rx="2"/><rect x="33" y="8" width="8" height="18" rx="2"/><rect x="21" y="23" width="6" height="19" rx="2"/></g></svg>' : '<svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="3.3" stroke-linejoin="round" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M7 20 11 8h26l4 12v5c-1 4-6 5-9 1-3 4-8 4-11 0-3 4-8 4-11 0-3 4-8 3-9-1v-5z" transform="translate(3 0) scale(.88)"/><path d="M10 25v15h28V25M20 40V29h10v11"/></svg>' ?></span>
      <div><strong><?= $splitSection === 'veiling' ? 'Veiling' : 'Marktplaats' ?></strong>
        <p><?= $splitSection === 'veiling' ? 'Deze kavels worden geveild. Je vindt hier het veilingsnummer en het startbod.' : 'Deze kavels kunnen tijdens de kijkdagen onmiddellijk worden aangekocht. Je vindt hier de marktprijs.' ?></p>
      </div>
    </div>
  <?php endif; ?>
  <?php if (!$published): ?>
    <div class="empty-state"><span class="empty-art">◌</span><h3>De catalogus wordt voorbereid</h3><p>De kavels verschijnen hier zodra de organisatie de veiling publiceert.</p></div>
  <?php else: ?>
    <div class="catalog-status"><div><span class="result-count"><?=e((string)$total)?> kavels</span> <span class="status-separator">/</span><span class="status-caption">Pagina <?=e((string)$page)?> van <?=e((string)($pages ?? 1))?></span></div><div class="status-actions"><a class="chip <?=$onlyFavorites?'selected':''?>" id="favoritesOnly" href="<?=e(url('index.php',['favorieten'=>1]))?>">♡ Mijn favorieten</a></div></div>
    <?php if (!$rows): ?><div class="empty-state"><span class="empty-art">◌</span><h3>Geen kavels in dit overzicht</h3><p>Er zijn momenteel geen kavels beschikbaar in dit overzicht.</p></div>
    <?php else: ?><div class="catalog-grid"><?php foreach($rows as $lot): ?>
    <article class="lot-card"><a class="lot-cover" href="<?=e(url('kavel.php',['nr'=>(int)$lot['lot_number']]))?>"><img loading="lazy" src="<?=e(url($lot['cover'] ?: 'assets/placeholder.svg'))?>" alt="<?=e($lot['title'])?>"><span class="lot-badge<?= $splitPreview && $splitSection === 'veiling' ? ' lot-badge--auction-bottom' : '' ?>">KAVEL <?=e(str_pad((string)$lot['lot_number'],3,'0',STR_PAD_LEFT))?></span><?php if (isset($popularLotIds[(int)$lot['id']])): ?><span class="lot-popular-tag<?= $lot['status'] === 'sold' ? ' lot-popular-tag--sold' : '' ?>">Populair</span><?php endif; ?><?php if ($splitPreview && $splitSection === "veiling" && !empty($lot["auction_number"])): ?><span class="lot-preview-number">VEILINGNR. <?= (int)$lot["auction_number"] ?></span><?php endif; ?><?php if ($lot['status'] === 'sold'): ?><span class="lot-sold-badge">Verkocht</span><?php endif; ?></a><div class="lot-card-text"><p class="lot-category"><?=e($lot['category'])?></p><h3><a href="<?=e(url('kavel.php',['nr'=>(int)$lot['lot_number']]))?>" title="<?=e($lot['title'])?>"><?=e($lot['title'])?></a></h3><p class="lot-subtitle"><?=e($lot['subtitle'])?></p><?php if (!empty($lot['artist_name'])): ?><p class="lot-artist"><span class="lot-artist-label">Kunstenaar:</span> <span class="lot-artist-name" title="<?=e($lot['artist_name'])?>"><?=e($lot['artist_name'])?></span></p><?php endif; ?><div class="lot-footer"><?php if (($previewPrice = catalog_preview_price($lot, $splitPreview)) !== null): ?><div class="lot-estimate"><small><?=e($previewPrice["label"])?></small><strong><?=e($previewPrice["amount"])?></strong></div><?php endif; ?><button class="heart-btn" type="button" data-favorite="<?=(int)$lot['lot_number']?>" aria-label="Bewaar kavel <?=(int)$lot['lot_number']?>" aria-pressed="false">♡</button></div></div></article>
    <?php endforeach; ?></div>
    <?php if (($pages ?? 1)>1): ?><nav class="pagination" aria-label="Paginering"><?php if($page>1): ?><a class="page-arrow" href="<?=e(page_link($page-1))?>">← Vorige</a><?php endif; ?><span>Pagina <?=e((string)$page)?> van <?=e((string)$pages)?></span><?php if($page<$pages): ?><a class="page-arrow" href="<?=e(page_link($page+1))?>">Volgende →</a><?php endif; ?></nav><?php endif; ?>
    <?php endif; ?>
  <?php endif; ?></section>
  <?php endif; ?>
  <section class="info-section" id="informatie"><div class="shell info-layout"><div><p class="eyebrow info-eyebrow">GOED OM TE WETEN</p><h2>Een bijzonder moment<br>om <em>bij te zijn.</em></h2><p><?= $published ? "Alle kavels zijn online te bekijken. De veiling zelf vindt fysiek plaats. Online bieden is niet mogelijk." : "De veiling vindt fysiek plaats. Online bieden is niet mogelijk." ?></p></div><div class="info-card"><h3>Praktische informatie</h3><dl><div><dt>Datum</dt><dd><?=e($date)?></dd></div><div><dt>Uur</dt><dd><?=e($s['event_time'] ?: 'Wordt aangekondigd')?></dd></div><div><dt>Locatie</dt><dd><?=e($s['location'] ?: 'Wordt aangekondigd')?></dd></div><div><dt>Adres</dt><dd><?=e($s['address'] ?: 'Wordt aangekondigd')?></dd></div></dl><p><?=nl2br(e($s['practical_info'] ?: 'Bieden gebeurt uitsluitend ter plaatse.'))?></p><?php if(!empty($s['contact_email'])): ?><a class="contact-link" href="mailto:<?=e($s['contact_email'])?>">Contacteer de organisatie ↗</a><?php endif; ?></div></div></section>
  </main><footer class="footer"><div class="shell footer-inner"><span class="footer-brand"><strong>41 LOKEREN · VEILING 2027</strong></span><p>Geen online biedingen. Geen registratie vereist.</p><a href="<?=e(url('privacy.php'))?>">Privacy</a><a href="https://pecharko-website.pages.dev/">Designed by Pecharko</a><?php public_social_footer(); ?></div></footer><script src="<?=e(url('assets/catalogus.js'))?>" defer></script>
</body></html>
