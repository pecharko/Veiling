<?php
declare(strict_types=1);
require dirname(__DIR__) . '/private/bootstrap.php';
$s = settings();
$published = (int)($s['is_published'] ?? 0) === 1;
$q = mb_substr(trim((string)(is_string($_GET['q'] ?? null) ? $_GET['q'] : '')), 0, 100);
$category = mb_substr(trim((string)(is_string($_GET['categorie'] ?? null) ? $_GET['categorie'] : '')), 0, 120);
$price = (string)($_GET['prijs'] ?? '');
$sort = (string)($_GET['sort'] ?? 'number');
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
$where = ["l.status='published'"];
$params = [];
if ($q !== '') {
  $where[] = "(CAST(l.lot_number AS CHAR) LIKE ? OR l.title LIKE ? OR l.subtitle LIKE ? OR l.description LIKE ? OR l.category LIKE ?)";
  for ($i=0; $i<5; $i++) $params[] = '%' . $q . '%';
}
if ($category !== '') { $where[] = 'l.category = ?'; $params[] = $category; }
$priceRules = [
  '0-250' => 'COALESCE(l.estimate_min,l.estimate_max) <= 250',
  '250-500' => 'COALESCE(l.estimate_min,l.estimate_max) > 250 AND COALESCE(l.estimate_min,l.estimate_max) <= 500',
  '500-1000' => 'COALESCE(l.estimate_min,l.estimate_max) > 500 AND COALESCE(l.estimate_min,l.estimate_max) <= 1000',
  '1000+' => 'COALESCE(l.estimate_min,l.estimate_max) > 1000',
  'unknown' => 'l.estimate_min IS NULL AND l.estimate_max IS NULL'
];
if (isset($priceRules[$price])) $where[] = '(' . $priceRules[$price] . ')';
if ($onlyFavorites) {
  if (!$favoriteNumbers) $where[] = '1=0';
  else { $where[] = 'l.lot_number IN (' . implode(',', array_fill(0,count($favoriteNumbers),'?')) . ')'; $params = array_merge($params, array_values($favoriteNumbers)); }
}
$orders = [
  'number' => 'l.lot_number ASC',
  'number-desc' => 'l.lot_number DESC',
  'title' => 'l.title ASC, l.lot_number ASC',
  'estimate' => '(COALESCE(l.estimate_min,l.estimate_max) IS NULL), COALESCE(l.estimate_min,l.estimate_max) ASC, l.lot_number ASC'
];
$order = $orders[$sort] ?? $orders['number'];
$rows = []; $categories = []; $total = 0; $totalPublic = 0;
if ($published) {
  $pdo = db();
  $totalPublic = (int)$pdo->query("SELECT COUNT(*) FROM lots WHERE status='published'")->fetchColumn();
  $categories = $pdo->query("SELECT DISTINCT category FROM lots WHERE status='published' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
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
}
function page_link(int $number): string {
  $p = $_GET;
  $p['pagina'] = $number;
  return url('index.php', $p) . '#catalogus';
}
$date = !empty($s['event_date']) ? date_nl($s['event_date']) : 'Verwacht in oktober 2027';
$title = (string)($s['title'] ?? 'Veilingcatalogus');
$subtitle = (string)($s['subtitle'] ?? 'Een bijzondere selectie');
?><!doctype html>
<html lang="nl"><head><link rel="icon" type="image/png" href="/favicon.png?v=3"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#850015"><meta name="description" content="Bekijk de kavels, foto's en beschrijvingen van <?=e($title)?>. Bieden gebeurt uitsluitend op locatie."><title><?=e($title)?> | Online catalogus</title><link rel="icon" type="image/png" href="<?=e(url('favicon.png'))?>?v=18"><link rel="stylesheet" href="<?=e(url('assets/catalogus.css'))?>?v=186"><link rel="stylesheet" href="<?=e(url('assets/home-v18.css'))?>?v=184"></head>
<body data-favorites="<?=$onlyFavorites ? '1' : '0'?>">
  <header class="site-header front-header" id="top">
    <div class="shell header-inner front-header-inner">
      <a class="brand front-brand" href="<?=e(url('index.php'))?>" aria-label="41 Lokeren - naar startpagina">
        <span class="brand-mark" aria-hidden="true">41</span>
        <span class="brand-name">41 LOKEREN<small>KUNST · LIFESTYLE · VINTAGE</small></span>
      </a>
      <nav class="header-nav front-nav" aria-label="Hoofdnavigatie">
        <a class="front-nav-home" href="<?=e(url('index.php'))?>">Home</a>
        <a href="#catalogus">Bekijk kavels</a>
        <a href="#informatie">Veilinginfo</a>
        <a href="#goede-doel">Het goede doel</a>
        <?php if(!empty($s['contact_email'])): ?><a class="front-contact-link" href="mailto:<?=e($s['contact_email'])?>">Contact</a><?php endif; ?>
        <a id="favoriteLink" class="header-favorites" href="<?=e(url('index.php',['favorieten'=>1]))?>" aria-label="Mijn favorieten"><span aria-hidden="true">♡</span> <span id="favoriteCount">0</span></a>
        <a class="front-header-cta" href="#catalogus">Bekijk kavels ↗</a>
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
            <a class="front-btn front-btn-primary" href="#catalogus">Bekijk de kavels <span aria-hidden="true">↗</span></a>
            <a class="front-btn front-btn-outline" href="#goede-doel">Meer over het goede doel <span aria-hidden="true">→</span></a>
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
          <span><strong>Kunst, lifestyle en vintage</strong><small>Unieke kavels voor een goed doel</small></span>
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
  <section class="catalog-section shell" id="catalogus"><div class="section-lead"><div><p class="eyebrow dark-eyebrow">KUNST · LIFESTYLE · VINTAGE</p><h2>Ontdek de kavels<span class="gold-dot">.</span></h2></div><p><?=e($s['introduction'] ?: 'Bekijk de kavels in detail en bewaar je favorieten. Bieden kan uitsluitend tijdens de fysieke veiling.')?></p></div>
  <?php if (!$published): ?>
    <div class="empty-state"><span class="empty-art">◌</span><h3>De catalogus wordt voorbereid</h3><p>De kavels verschijnen hier zodra de organisatie de veiling publiceert.</p></div>
  <?php else: ?>
    <form class="toolbar" action="<?=e(url('index.php'))?>#catalogus" method="get">
      <label class="search-field"><span class="field-name">ZOEKEN</span><span class="search-inline"><span aria-hidden="true">⌕</span><input name="q" type="search" value="<?=e($q)?>" placeholder="Lotnummer of trefwoord…" aria-label="Zoeken in kavels"></span></label>
      <label class="filter-field"><span class="field-name">CATEGORIE</span><select name="categorie" aria-label="Categorie"><option value="">Alle categorieën</option><?php foreach($categories as $cat): ?><option value="<?=e($cat)?>" <?=$category === $cat ? 'selected' : ''?>><?=e($cat)?></option><?php endforeach; ?></select></label>
      <label class="filter-field"><span class="field-name">SCHATTING</span><select name="prijs"><option value="">Alle schattingen</option><?php foreach(['0-250'=>'Tot € 250','250-500'=>'€ 250 – 500','500-1000'=>'€ 500 – 1.000','1000+'=>'Vanaf € 1.000','unknown'=>'Zonder schatting'] as $key=>$label): ?><option value="<?=e($key)?>" <?=$price === $key ? 'selected' : ''?>><?=e($label)?></option><?php endforeach; ?></select></label>
      <label class="filter-field"><span class="field-name">SORTEREN</span><select name="sort"><option value="number" <?=$sort==='number'?'selected':''?>>Lotnummer oplopend</option><option value="number-desc" <?=$sort==='number-desc'?'selected':''?>>Lotnummer aflopend</option><option value="title" <?=$sort==='title'?'selected':''?>>Titel A–Z</option><option value="estimate" <?=$sort==='estimate'?'selected':''?>>Laagste schatting</option></select></label>
      <button class="filter-submit" type="submit">Filter toepassen →</button>
    </form>
    <div class="catalog-status"><div><span class="result-count"><?=e((string)$total)?> kavels</span> <span class="status-separator">/</span><span class="status-caption">Pagina <?=e((string)$page)?> van <?=e((string)($pages ?? 1))?></span></div><div class="status-actions"><a class="chip <?=$onlyFavorites?'selected':''?>" id="favoritesOnly" href="<?=e(url('index.php',['favorieten'=>1]))?>">♡ Mijn favorieten</a><a class="text-button" href="<?=e(url('index.php'))?>#catalogus">Wis filters ↺</a></div></div>
    <?php if (!$rows): ?><div class="empty-state"><span class="empty-art">◌</span><h3>Geen kavels gevonden</h3><p>Pas je zoekterm of filters aan, of bekijk alle kavels.</p><a class="btn btn-dark" href="<?=e(url('index.php'))?>#catalogus">Toon alle kavels</a></div>
    <?php else: ?><div class="catalog-grid"><?php foreach($rows as $lot): ?>
    <article class="lot-card"><a class="lot-cover" href="<?=e(url('kavel.php',['nr'=>(int)$lot['lot_number']]))?>"><img loading="lazy" src="<?=e(url($lot['cover'] ?: 'assets/placeholder.svg'))?>" alt="<?=e($lot['title'])?>"><span class="lot-badge">KAVEL <?=e(str_pad((string)$lot['lot_number'],3,'0',STR_PAD_LEFT))?></span></a><div class="lot-card-text"><p class="lot-category"><?=e($lot['category'])?></p><h3><a href="<?=e(url('kavel.php',['nr'=>(int)$lot['lot_number']]))?>"><?=e($lot['title'])?></a></h3><p class="lot-subtitle"><?=e($lot['subtitle'])?></p><div class="lot-footer"><div class="lot-estimate"><small>RICHTWAARDE</small><strong><?=e(estimate($lot))?></strong></div><button class="heart-btn" type="button" data-favorite="<?=(int)$lot['lot_number']?>" aria-label="Bewaar kavel <?=(int)$lot['lot_number']?>" aria-pressed="false">♡</button></div></div></article>
    <?php endforeach; ?></div>
    <?php if (($pages ?? 1)>1): ?><nav class="pagination" aria-label="Paginering"><?php if($page>1): ?><a class="page-arrow" href="<?=e(page_link($page-1))?>">← Vorige</a><?php endif; ?><span>Pagina <?=e((string)$page)?> van <?=e((string)$pages)?></span><?php if($page<$pages): ?><a class="page-arrow" href="<?=e(page_link($page+1))?>">Volgende →</a><?php endif; ?></nav><?php endif; ?>
    <?php endif; ?>
  <?php endif; ?></section>
  <section class="info-section" id="informatie"><div class="shell info-layout"><div><p class="eyebrow info-eyebrow">GOED OM TE WETEN</p><h2>Een bijzonder moment<br>om <em>bij te zijn.</em></h2><p>Alle kavels zijn online te bekijken. De veiling zelf vindt fysiek plaats. Online bieden is niet mogelijk.</p></div><div class="info-card"><h3>Praktische informatie</h3><dl><div><dt>Datum</dt><dd><?=e($date)?></dd></div><div><dt>Uur</dt><dd><?=e($s['event_time'] ?: 'Wordt aangekondigd')?></dd></div><div><dt>Locatie</dt><dd><?=e($s['location'] ?: 'Wordt aangekondigd')?></dd></div><div><dt>Adres</dt><dd><?=e($s['address'] ?: 'Wordt aangekondigd')?></dd></div></dl><p><?=nl2br(e($s['practical_info'] ?: 'Bieden gebeurt uitsluitend ter plaatse.'))?></p><?php if(!empty($s['contact_email'])): ?><a class="contact-link" href="mailto:<?=e($s['contact_email'])?>">Contacteer de organisatie ↗</a><?php endif; ?></div></div></section>
  </main><footer class="footer"><div class="shell footer-inner"><span class="footer-brand"><strong>41 LOKEREN · VEILING 2027</strong></span><p>Geen online biedingen. Geen registratie vereist.</p><a href="#catalogus">Naar boven ↑</a></div></footer><script src="<?=e(url('assets/catalogus.js'))?>" defer></script>
</body></html>
