<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/private/bootstrap.php';
require_auth();

$error = '';
$pdo = db();
$publishedLots = (int)$pdo->query("SELECT COUNT(*) FROM lots WHERE status='published'")->fetchColumn();
$totalLots = (int)$pdo->query('SELECT COUNT(*) FROM lots')->fetchColumn();

if (is_post()) {
  check_csrf();
  $fields = [
    'title' => mb_substr(trim((string)($_POST['title'] ?? '')), 0, 200),
    'subtitle' => mb_substr(trim((string)($_POST['subtitle'] ?? '')), 0, 220),
    'introduction' => mb_substr(trim((string)($_POST['introduction'] ?? '')), 0, 5000),
    'event_date' => trim((string)($_POST['event_date'] ?? '')) ?: null,
    'event_time' => mb_substr(trim((string)($_POST['event_time'] ?? '')), 0, 90),
    'location' => mb_substr(trim((string)($_POST['location'] ?? '')), 0, 190),
    'address' => mb_substr(trim((string)($_POST['address'] ?? '')), 0, 300),
    'contact_email' => mb_substr(trim((string)($_POST['contact_email'] ?? '')), 0, 190),
    'practical_info' => mb_substr(trim((string)($_POST['practical_info'] ?? '')), 0, 5000),
    'is_published' => isset($_POST['is_published']) ? 1 : 0,
  ];

  if ($fields['title'] === '') {
    $error = 'Een veilingnaam is verplicht.';
  } elseif ($fields['contact_email'] !== '' && !filter_var($fields['contact_email'], FILTER_VALIDATE_EMAIL)) {
    $error = 'Het contactadres is ongeldig.';
  } elseif ($fields['event_date'] !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fields['event_date'])) {
    $error = 'Datumformaat ongeldig.';
  }

  if (!$error) {
    $st = $pdo->prepare('UPDATE settings SET
      title = :title,
      subtitle = :subtitle,
      introduction = :introduction,
      event_date = :event_date,
      event_time = :event_time,
      location = :location,
      address = :address,
      contact_email = :contact_email,
      practical_info = :practical_info,
      is_published = :is_published
      WHERE id = 1');
    $st->execute($fields);

    flash(
      $fields['is_published']
        ? 'Veilinginformatie opgeslagen. Gepubliceerde kavels zijn nu zichtbaar.'
        : 'Veilinginformatie opgeslagen. De publieke catalogus blijft verborgen.'
    );
    redirect('admin/instellingen.php');
  }
}

$s = settings();
$checkFields = [
  'title' => !empty($s['title']),
  'introduction' => !empty($s['introduction']),
  'event_date' => !empty($s['event_date']),
  'location' => !empty($s['location']),
  'contact_email' => !empty($s['contact_email']),
];
$completedCount = count(array_filter($checkFields));
$completenessText = $completedCount . ' / ' . count($checkFields) . ' basisvelden ingevuld';
$publicationEnabled = (int)($s['is_published'] ?? 0) === 1;
$publicStateText = $publicationEnabled ? 'Website is zichtbaar' : 'Website blijft verborgen';
$dateStateText = !empty($s['event_date']) ? date_nl((string)$s['event_date']) : 'Datum nog niet vastgelegd';
$contactStateText = !empty($s['contact_email']) ? (string)$s['contact_email'] : 'Nog geen contactadres';

admin_top('Veilinginformatie');
if ($error) {
  echo '<div class="alert error">' . e($error) . '</div>';
}
?>
<style>
.auction-settings { --set-line:#eadfd5; --set-panel:#fff; --set-soft:#fff6f1; --set-soft-2:#fcf1ea; --set-muted:#6f7c75; --set-ink:#29473e; --set-ok:#246040; --set-ok-bg:#edf8f0; --set-warn:#8d5d12; --set-warn-bg:#fff7e5; }
.auction-settings * { box-sizing:border-box; }
.auction-settings-shell { display:grid; gap:24px; }
.auction-settings-hero { position:relative; overflow:hidden; display:grid; grid-template-columns:minmax(0,1.2fr) minmax(340px,0.9fr); gap:22px; align-items:stretch; padding:28px; border-radius:20px; border:1px solid var(--set-line); background:linear-gradient(135deg, #fff 0%, #fff9f5 100%); box-shadow:0 14px 40px rgba(84,41,30,.06); }
.auction-settings-hero::after { content:""; position:absolute; right:-90px; top:-90px; width:240px; height:240px; border-radius:50%; background:radial-gradient(circle, rgba(239,133,134,.15), rgba(239,133,134,0) 68%); pointer-events:none; }
.auction-settings-hero-copy { position:relative; z-index:1; }
.auction-settings-kicker { display:inline-flex; align-items:center; gap:8px; padding:8px 12px; border-radius:999px; background:#fff; border:1px solid var(--set-line); font-size:12px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; color:var(--wine); margin-bottom:16px; }
.auction-settings-kicker::before { content:""; width:9px; height:9px; border-radius:50%; background:var(--wine); box-shadow:0 0 0 6px rgba(133,0,21,.08); }
.auction-settings-hero h2 { margin:0 0 10px; font-size:clamp(28px,3vw,40px); line-height:1.02; letter-spacing:-.04em; color:var(--wine); }
.auction-settings-hero p { margin:0; max-width:62ch; color:var(--set-muted); font-size:15px; line-height:1.7; }
.auction-settings-status-grid { position:relative; z-index:1; display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px; }
.auction-settings-status-card { min-width:0; background:#fff; border:1px solid var(--set-line); border-radius:16px; padding:18px; box-shadow:0 8px 28px rgba(84,41,30,.04); }
.auction-settings-status-card.full { grid-column:1 / -1; }
.auction-settings-status-label { display:block; font-size:11px; text-transform:uppercase; letter-spacing:.1em; font-weight:800; color:var(--muted); margin-bottom:8px; }
.auction-settings-status-value { display:block; font-size:26px; font-weight:850; line-height:1.05; letter-spacing:-.03em; color:var(--set-ink); }
.auction-settings-status-meta { display:block; margin-top:8px; color:var(--set-muted); font-size:13px; line-height:1.55; }
.auction-settings-status-pill { display:inline-flex; align-items:center; gap:8px; margin-top:10px; padding:7px 12px; border-radius:999px; font-size:12px; font-weight:800; }
.auction-settings-status-pill.ok { color:var(--set-ok); background:var(--set-ok-bg); }
.auction-settings-status-pill.warn { color:var(--set-warn); background:var(--set-warn-bg); }
.auction-settings-layout { display:grid; grid-template-columns:minmax(0,1.45fr) minmax(320px,0.75fr); gap:22px; align-items:start; }
.auction-settings-main, .auction-settings-side { display:grid; gap:20px; min-width:0; }
.auction-settings-card { min-width:0; background:var(--set-panel); border:1px solid var(--set-line); border-radius:18px; padding:24px; box-shadow:0 10px 30px rgba(84,41,30,.045); }
.auction-settings-card-head { display:flex; align-items:flex-start; gap:14px; margin-bottom:22px; }
.auction-settings-step { flex:none; width:40px; height:40px; border-radius:12px; display:grid; place-items:center; background:#f8e7e2; color:var(--wine); font-size:13px; font-weight:900; }
.auction-settings-card-head h2,
.auction-settings-card-head h3 { margin:0 0 4px; color:var(--set-ink); font-size:21px; letter-spacing:-.02em; }
.auction-settings-card-head p { margin:0; font-size:13px; line-height:1.65; color:var(--set-muted); }
.auction-settings-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:18px; }
.auction-settings-grid .full { grid-column:1 / -1; }
.auction-settings-field { display:flex; flex-direction:column; gap:7px; min-width:0; }
.auction-settings-field label,
.auction-settings-field > span { font-size:13px; font-weight:800; color:var(--set-ink); }
.auction-settings-field small { margin-top:-2px; color:var(--set-muted); font-size:12px; line-height:1.5; }
.auction-settings input:not([type=checkbox]),
.auction-settings textarea,
.auction-settings select { width:100%; min-width:0; max-width:none; border:1px solid #d8ddd6; border-radius:12px; background:#fff; color:#21382f; padding:13px 14px; font-size:14px; line-height:1.5; transition:border-color .15s, box-shadow .15s, background .15s; }
.auction-settings input:not([type=checkbox]) { min-height:50px; }
.auction-settings textarea { min-height:132px; resize:vertical; }
.auction-settings input:focus,
.auction-settings textarea:focus,
.auction-settings select:focus { outline:none; border-color:var(--wine); box-shadow:0 0 0 4px rgba(133,0,21,.08); }
.auction-settings-helper-list { display:grid; gap:10px; margin:0; padding:0; list-style:none; }
.auction-settings-helper-list li { display:flex; align-items:flex-start; gap:10px; font-size:13px; color:var(--set-muted); }
.auction-settings-helper-list li::before { content:""; flex:none; width:9px; height:9px; border-radius:50%; margin-top:.45em; background:var(--sage); }
.auction-settings-publish-card { position:sticky; top:22px; }
.auction-settings-toggle { display:block; border:1px solid var(--set-line); border-radius:18px; background:linear-gradient(180deg,#fff 0%, #fffaf7 100%); padding:18px; }
.auction-settings-toggle input { position:absolute; opacity:0; pointer-events:none; }
.auction-settings-toggle-row { display:flex; align-items:flex-start; gap:14px; }
.auction-settings-toggle-ui { position:relative; flex:none; width:58px; height:34px; border-radius:999px; background:#d7d3cc; transition:background .2s; margin-top:1px; }
.auction-settings-toggle-ui::after { content:""; position:absolute; top:4px; left:4px; width:26px; height:26px; border-radius:50%; background:#fff; box-shadow:0 2px 10px rgba(0,0,0,.12); transition:left .2s; }
.auction-settings-toggle input:checked + .auction-settings-toggle-row .auction-settings-toggle-ui { background:linear-gradient(90deg,var(--wine),#a7162b); }
.auction-settings-toggle input:checked + .auction-settings-toggle-row .auction-settings-toggle-ui::after { left:28px; }
.auction-settings-toggle-copy strong { display:block; font-size:15px; color:var(--set-ink); margin-bottom:4px; }
.auction-settings-toggle-copy span { display:block; font-size:13px; color:var(--set-muted); line-height:1.6; }
.auction-settings-note { margin-top:14px; padding:14px 15px; border-radius:14px; background:#fff; border:1px dashed var(--set-line); color:var(--set-muted); font-size:13px; line-height:1.65; }
.auction-settings-summary { display:grid; gap:12px; }
.auction-settings-summary-item { display:grid; grid-template-columns:110px 1fr; gap:12px; align-items:start; padding-top:12px; border-top:1px solid #f0e5dd; }
.auction-settings-summary-item:first-child { padding-top:0; border-top:0; }
.auction-settings-summary-item dt { margin:0; font-size:12px; text-transform:uppercase; letter-spacing:.08em; color:var(--muted); font-weight:800; }
.auction-settings-summary-item dd { margin:0; font-size:14px; color:var(--set-ink); line-height:1.55; word-break:break-word; }
.auction-settings-actionbox { display:grid; gap:14px; }
.auction-settings-save { width:100%; min-height:52px; justify-content:center; font-size:14px; }
.auction-settings-subactions { font-size:12px; color:var(--set-muted); line-height:1.65; }
.auction-settings-subactions strong { color:var(--set-ink); }
@media (max-width: 1100px) {
  .auction-settings-layout,
  .auction-settings-hero { grid-template-columns:minmax(0,1fr); }
  .auction-settings-publish-card { position:static; }
}
@media (max-width: 760px) {
  .auction-settings-hero,
  .auction-settings-card { padding:18px; border-radius:16px; }
  .auction-settings-status-grid,
  .auction-settings-grid { grid-template-columns:minmax(0,1fr); }
  .auction-settings-summary-item { grid-template-columns:1fr; gap:4px; }
}
</style>

<div class="auction-settings">
  <div class="auction-settings-shell">
    <section class="auction-settings-hero">
      <div class="auction-settings-hero-copy">
        <span class="auction-settings-kicker">Veilinginformatie · state of the art</span>
        <h2>Zet je veiling helder, aantrekkelijk en publicatieklaar neer.</h2>
        <p>
          Beheer hier alle informatie die bezoekers op de publieke catalogus zien: de titel, situering,
          praktische details en de publicatiestatus. Zo houd je je veiling professioneel en klaar voor lancering.
        </p>
      </div>
      <div class="auction-settings-status-grid">
        <div class="auction-settings-status-card">
          <span class="auction-settings-status-label">Publicatie</span>
          <span class="auction-settings-status-value"><?= e($publicationEnabled ? 'Online' : 'Verborgen') ?></span>
          <span class="auction-settings-status-meta"><?= e($publicStateText) ?></span>
          <span class="auction-settings-status-pill <?= $publicationEnabled ? 'ok' : 'warn' ?>"><?= e($publicationEnabled ? 'Publiek zichtbaar' : 'Nog niet publiek') ?></span>
        </div>
        <div class="auction-settings-status-card">
          <span class="auction-settings-status-label">Basis compleet</span>
          <span class="auction-settings-status-value"><?= e((string)$completedCount) ?></span>
          <span class="auction-settings-status-meta"><?= e($completenessText) ?></span>
          <span class="auction-settings-status-pill <?= $completedCount >= 4 ? 'ok' : 'warn' ?>"><?= e($completedCount >= 4 ? 'Bijna klaar' : 'Aanvulling aanbevolen') ?></span>
        </div>
        <div class="auction-settings-status-card full">
          <span class="auction-settings-status-label">Gepubliceerde kavels</span>
          <span class="auction-settings-status-value"><?= e((string)$publishedLots) ?> / <?= e((string)$totalLots) ?></span>
          <span class="auction-settings-status-meta">Alleen kavels met status <strong>Gepubliceerd</strong> worden zichtbaar zodra je de publieke catalogus activeert.</span>
        </div>
      </div>
    </section>

    <form method="post" class="auction-settings-form">
      <?= csrf_field() ?>
      <div class="auction-settings-layout">
        <div class="auction-settings-main">
          <section class="auction-settings-card">
            <div class="auction-settings-card-head">
              <div class="auction-settings-step">01</div>
              <div>
                <h2>Identiteit van de veiling</h2>
                <p>De basis voor je titelblok en eerste indruk op de website.</p>
              </div>
            </div>
            <div class="auction-settings-grid">
              <div class="auction-settings-field full">
                <label for="title">Naam van de veiling</label>
                <input id="title" name="title" maxlength="200" required value="<?= e($s['title'] ?? '') ?>" placeholder="Bijvoorbeeld: Kunst & Vintage Veiling 2027">
                <small>Verplicht. Deze naam verschijnt prominent bovenaan de catalogus.</small>
              </div>
              <div class="auction-settings-field full">
                <label for="subtitle">Ondertitel</label>
                <input id="subtitle" name="subtitle" maxlength="220" value="<?= e($s['subtitle'] ?? '') ?>" placeholder="Bijvoorbeeld: Een bijzondere selectie voor het goede doel">
                <small>Korte extra context voor bezoekers.</small>
              </div>
            </div>
          </section>

          <section class="auction-settings-card">
            <div class="auction-settings-card-head">
              <div class="auction-settings-step">02</div>
              <div>
                <h2>Verhaal voor bezoekers</h2>
                <p>Geef duidelijk aan wat bezoekers mogen verwachten en hoe de veiling verloopt.</p>
              </div>
            </div>
            <div class="auction-settings-grid">
              <div class="auction-settings-field full">
                <label for="introduction">Inleiding voor bezoekers</label>
                <textarea id="introduction" name="introduction" rows="4" placeholder="Ontdek de kavels en hun verhaal..."><?= e($s['introduction'] ?? '') ?></textarea>
                <small>Gebruik 2 à 4 korte zinnen die de bezoeker welkom heten en de context schetsen.</small>
              </div>
              <div class="auction-settings-field full">
                <label for="practical_info">Praktische informatie</label>
                <textarea id="practical_info" name="practical_info" rows="5" placeholder="Bieden gebeurt uitsluitend tijdens de fysieke veiling...\nOpeningsuren bezichtiging...\nSpecifieke richtlijnen..."><?= e($s['practical_info'] ?? '') ?></textarea>
                <small>Ideaal voor bezichtiging, ophaalinfo, biedregels of eventuele uitzonderingen.</small>
              </div>
            </div>
          </section>

          <section class="auction-settings-card">
            <div class="auction-settings-card-head">
              <div class="auction-settings-step">03</div>
              <div>
                <h2>Moment, locatie en contact</h2>
                <p>Alle praktische info die bezoekers nodig hebben om aanwezig te zijn.</p>
              </div>
            </div>
            <div class="auction-settings-grid">
              <div class="auction-settings-field">
                <label for="event_date">Veilingdatum</label>
                <input id="event_date" type="date" name="event_date" value="<?= e($s['event_date'] ?? '') ?>">
              </div>
              <div class="auction-settings-field">
                <label for="event_time">Tijdstip</label>
                <input id="event_time" name="event_time" maxlength="90" value="<?= e($s['event_time'] ?? '') ?>" placeholder="Bijvoorbeeld: 17:00 uur">
              </div>
              <div class="auction-settings-field">
                <label for="location">Locatie</label>
                <input id="location" name="location" maxlength="190" value="<?= e($s['location'] ?? '') ?>" placeholder="Bijvoorbeeld: Theaterzaal CC Lokeren">
              </div>
              <div class="auction-settings-field">
                <label for="address">Adres</label>
                <input id="address" name="address" maxlength="300" value="<?= e($s['address'] ?? '') ?>" placeholder="Bijvoorbeeld: Kerkplein 5, 9160 Lokeren">
              </div>
              <div class="auction-settings-field full">
                <label for="contact_email">Contact e-mail</label>
                <input id="contact_email" type="email" name="contact_email" maxlength="190" value="<?= e($s['contact_email'] ?? '') ?>" placeholder="Bijvoorbeeld: info@41lokeren.be">
                <small>Bezoekers kunnen dit adres gebruiken voor praktische vragen.</small>
              </div>
            </div>
          </section>
        </div>

        <aside class="auction-settings-side">
          <section class="auction-settings-card auction-settings-publish-card">
            <div class="auction-settings-card-head">
              <div class="auction-settings-step">04</div>
              <div>
                <h3>Publicatie & controle</h3>
                <p>Activeer de publieke website pas wanneer je klaar bent om bezoekers toe te laten.</p>
              </div>
            </div>

            <label class="auction-settings-toggle">
              <input type="checkbox" name="is_published" value="1" <?= $publicationEnabled ? 'checked' : '' ?>>
              <span class="auction-settings-toggle-row">
                <span class="auction-settings-toggle-ui" aria-hidden="true"></span>
                <span class="auction-settings-toggle-copy">
                  <strong>Publieke catalogus zichtbaar maken</strong>
                  <span>Bij inschakelen worden enkel kavels met status <em>Gepubliceerd</em> getoond aan bezoekers.</span>
                </span>
              </span>
            </label>

            <div class="auction-settings-note">
              <strong>Tip:</strong> Maak de website pas publiek nadat datum, locatie, contactgegevens en de belangrijkste kavels gecontroleerd zijn.
            </div>

            <div class="auction-settings-card" style="margin-top:18px; padding:18px; background:#fffdfb;">
              <div class="auction-settings-card-head" style="margin-bottom:14px; gap:12px;">
                <div class="auction-settings-step" style="width:34px;height:34px;">◎</div>
                <div>
                  <h3 style="font-size:18px;">Live samenvatting</h3>
                  <p>Zo oogt je bezoekersinformatie inhoudelijk.</p>
                </div>
              </div>
              <dl class="auction-settings-summary">
                <div class="auction-settings-summary-item">
                  <dt>Titel</dt>
                  <dd><?= e($s['title'] ?: 'Nog geen titel ingevuld') ?></dd>
                </div>
                <div class="auction-settings-summary-item">
                  <dt>Datum</dt>
                  <dd><?= e($dateStateText) ?></dd>
                </div>
                <div class="auction-settings-summary-item">
                  <dt>Tijdstip</dt>
                  <dd><?= e($s['event_time'] ?: 'Wordt aangekondigd') ?></dd>
                </div>
                <div class="auction-settings-summary-item">
                  <dt>Locatie</dt>
                  <dd><?= e($s['location'] ?: 'Nog niet ingesteld') ?></dd>
                </div>
                <div class="auction-settings-summary-item">
                  <dt>Contact</dt>
                  <dd><?= e($contactStateText) ?></dd>
                </div>
              </dl>
            </div>

            <div class="auction-settings-card" style="margin-top:18px; padding:18px; background:#fff;">
              <div class="auction-settings-card-head" style="margin-bottom:14px; gap:12px;">
                <div class="auction-settings-step" style="width:34px;height:34px;">✓</div>
                <div>
                  <h3 style="font-size:18px;">Checklist</h3>
                  <p>Korte controle vóór publicatie.</p>
                </div>
              </div>
              <ul class="auction-settings-helper-list">
                <li>De veilingnaam en ondertitel zijn duidelijk en aantrekkelijk.</li>
                <li>Datum, tijd en locatie zijn correct of bewust nog leeg gelaten.</li>
                <li>Minstens de relevante kavels staan op <strong>Gepubliceerd</strong>.</li>
                <li>Het contactadres is bruikbaar voor bezoekersvragen.</li>
              </ul>
            </div>

            <div class="auction-settings-actionbox" style="margin-top:18px;">
              <button class="primary auction-settings-save" type="submit">Veilinginformatie bewaren →</button>
              <div class="auction-settings-subactions">
                <strong>Goed om te weten:</strong> je wijzigingen zijn meteen actief in de beheeromgeving.
                Op de publieke website worden ze alleen zichtbaar wanneer de catalogus hierboven is geactiveerd.
              </div>
            </div>
          </section>
        </aside>
      </div>
    </form>
  </div>
</div>
<?php admin_bottom(); ?>
