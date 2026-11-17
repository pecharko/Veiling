<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/private/bootstrap.php';
require_administrator();
require_no_index();
header('Content-Security-Policy: default-src \'none\'; style-src \'self\'; form-action \'self\'; base-uri \'none\'; frame-ancestors \'none\'');
header('Referrer-Policy: no-referrer');

if (!in_array(($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Methode niet toegestaan.');
}

// Rate-limit is per authenticated admin session, not a global lock or cron.
$status = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    check_csrf();
    admin_session();
    $now = time();
    if ($now < (int)($_SESSION['mail_tls_probe_after'] ?? 0)) {
        $status = 'recent';  // Avoid repeated connections and noisy error reports.
    } else {
        $_SESSION['mail_tls_probe_after'] = $now + 60;
        require_once dirname(__DIR__, 2) . '/private/mail_tls_probe.php';
        $status = auction_mail_tls_probe();
    }
}
admin_top('IMAP-verbindingstest');
?>
<section class="dashboard-panel">
  <h1>IMAP-verbindingstest (zonder aanmelding)</h1>
  <p>Deze tijdelijke diagnostische pagina controleert uitsluitend of deze PHP-hosting een geldige TLS-verbinding en IMAP-begroeting kan ontvangen van <code>imap.mail.ovh.ca:993</code>.</p>
  <p>Er worden geen mailboxwachtwoorden gebruikt, geen berichten geopend en geen instellingen gewijzigd.</p>
  <?php if ($status === 'reachable'): ?>
    <p role="status"><strong>Bereikbaar:</strong> TLS-verbinding en IMAP-begroeting gelukt vanaf de hosting. Dit bewijst nog geen mailboxaanmelding.</p>
  <?php elseif ($status === 'unavailable'): ?>
    <p role="status"><strong>Niet bereikbaar of niet bevestigd.</strong> Er is geen beveiligde IMAP-verbinding bevestigd. Controleer dit vóór activering.</p>
  <?php elseif ($status === 'recent'): ?>
    <p role="status">De controle is recent uitgevoerd. Probeer niet opnieuw binnen een minuut.</p>
  <?php endif; ?>
  <form method="post" action="<?= e(url('admin/mail-tls-check.php')) ?>">
    <?= csrf_field() ?>
    <button type="submit">Beveiligde verbinding testen</button>
  </form>
  <p><a href="<?= e(url('admin/index.php')) ?>">Terug naar beheer</a></p>
</section>
<?php admin_bottom(); ?>
