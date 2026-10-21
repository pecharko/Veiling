<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/private/bootstrap.php';
require_auth();
require_no_index();

// All deletion requires a current authenticated session, a valid CSRF token,
// a server-generated preview token and explicit confirmation in a second POST.
$deleteError = '';
$deletePreview = null;
if (is_post()) {
    check_csrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'preview_delete') {
        unset($_SESSION['analytics_delete_pending']);
        $scope = (string)($_POST['scope'] ?? '');
        $cutoff = (string)($_POST['cutoff'] ?? '');
        if (!in_array($scope, ['all', 'before'], true)) {
            $deleteError = 'Ongeldige verwijderactie.';
        } elseif ($scope === 'before') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $cutoff, new DateTimeZone('UTC'));
            if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D', $cutoff)
                || !$date || $date->format('Y-m-d') !== $cutoff
                || $cutoff > gmdate('Y-m-d')) {
                $deleteError = 'Kies een geldige UTC-datum, uiterlijk vandaag.';
            }
        }
        if ($deleteError === '') {
            try {
                $pdo = db();
                if ($scope === 'before') {
                    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM visitor_stats_daily WHERE visit_date < ?');
                    $countStmt->execute([$cutoff]);
                    $count = (int)$countStmt->fetchColumn();
                } else {
                    $count = (int)$pdo->query('SELECT COUNT(*) FROM visitor_stats_daily')->fetchColumn();
                }
                $token = bin2hex(random_bytes(24));
                $_SESSION['analytics_delete_pending'] = [
                    'token' => $token, 'scope' => $scope,
                    'cutoff' => $scope === 'before' ? $cutoff : '',
                    'expires' => time() + 300,
                ];
                $deletePreview = [
                    'token' => $token, 'scope' => $scope,
                    'cutoff' => $scope === 'before' ? $cutoff : '',
                    'count' => $count,
                ];
            } catch (PDOException $exception) {
                $deleteError = 'Kon de statistiekgegevens niet controleren.';
            }
        }
    } elseif ($action === 'confirm_delete') {
        $pending = $_SESSION['analytics_delete_pending'] ?? null;
        unset($_SESSION['analytics_delete_pending']); // One attempt only.
        $token = (string)($_POST['preview_token'] ?? '');
        $phrase = (string)($_POST['confirmation'] ?? '');
        if (!is_array($pending) || !is_string($pending['token'] ?? null)
            || $token === '' || !hash_equals($pending['token'], $token)
            || !is_int($pending['expires'] ?? null) || $pending['expires'] < time()
            || !in_array($pending['scope'] ?? '', ['all', 'before'], true)
            || $phrase !== 'VERWIJDER') {
            $deleteError = 'Bevestiging ongeldig of verlopen. Start opnieuw.';
        } else {
            try {
                $pdo = db();
                // No TRUNCATE, no dynamic table name; only statistics rows.
                if ($pending['scope'] === 'before') {
                    $cutoff = (string)($pending['cutoff'] ?? '');
                    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $cutoff, new DateTimeZone('UTC'));
                    if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D', $cutoff)
                        || !$date || $date->format('Y-m-d') !== $cutoff
                        || $cutoff > gmdate('Y-m-d')) {
                        throw new RuntimeException('Ongeldige opgeslagen grensdatum.');
                    }
                    $stmt = $pdo->prepare('DELETE FROM visitor_stats_daily WHERE visit_date < ?');
                    $stmt->execute([$cutoff]);
                    $deleted = $stmt->rowCount();
                } else {
                    $deleted = $pdo->exec('DELETE FROM visitor_stats_daily');
                }
                flash((string)$deleted . ' statistiekrecords definitief verwijderd.');
                redirect('admin/statistieken.php');
            } catch (Throwable $exception) {
                error_log('Analytics deletion failed (no sensitive details logged).');
                $deleteError = 'Verwijderen mislukt. Controleer de statistieken en probeer alleen na onderzoek opnieuw.';
            }
        }
    } else {
        $deleteError = 'Onbekende actie.';
    }
}

$summary = ['visitors' => 0, 'views' => 0, 'lot_views' => 0];
$daily = [];
$top = [];
$available = true;
try {
    $pdo = db();
    $summary = $pdo->query("SELECT COUNT(DISTINCT CONCAT(visit_date,HEX(visitor_id))) visitors, COALESCE(SUM(views),0) views, COALESCE(SUM(CASE WHEN page_kind='lot' THEN views ELSE 0 END),0) lot_views FROM visitor_stats_daily WHERE visit_date >= UTC_DATE() - INTERVAL 29 DAY")->fetch();
    $daily = $pdo->query("SELECT visit_date, COUNT(*) visitors, SUM(views) views FROM (SELECT visit_date, visitor_id, SUM(views) views FROM visitor_stats_daily WHERE visit_date >= UTC_DATE() - INTERVAL 29 DAY GROUP BY visit_date, visitor_id) daily_visitors GROUP BY visit_date ORDER BY visit_date DESC")->fetchAll();
    $top = $pdo->query("SELECT l.lot_number, l.title, SUM(v.views) views FROM visitor_stats_daily v INNER JOIN lots l ON l.id=v.lot_id WHERE v.page_kind='lot' AND v.visit_date >= UTC_DATE() - INTERVAL 29 DAY GROUP BY l.id, l.lot_number, l.title ORDER BY views DESC, l.lot_number LIMIT 10")->fetchAll();
} catch (PDOException $exception) {
    $available = false;
}
admin_top('Bezoekersstatistieken');
?>
<div class="dashboard-modern">
  <p class="dashboard-subtitle">Overzicht van de afgelopen 30 UTC-dagen. Unieke bezoekers zijn daggebonden schattingen: wie op meerdere dagen terugkomt telt opnieuw mee.</p>
  <?php if (!$available): ?>
    <div class="alert error">Statistieken zijn nog niet beschikbaar. Controleer de database-installatie.</div>
  <?php else: ?>
    <div class="dashboard-stats">
      <div class="dashboard-stat"><div><small>UNIEKE BEZOEKERSDAGEN</small><div><strong><?=e((string)$summary['visitors'])?></strong></div></div></div>
      <div class="dashboard-stat"><div><small>PAGINAWEERGAVEN</small><div><strong><?=e((string)$summary['views'])?></strong></div></div></div>
      <div class="dashboard-stat"><div><small>KAVELWEERGAVEN</small><div><strong><?=e((string)$summary['lot_views'])?></strong></div></div></div>
    </div>
    <section class="dashboard-panel"><h2>Bezoekers per dag</h2>
      <div class="dashboard-table-scroll"><table class="dashboard-table"><thead><tr><th>Dag (UTC)</th><th>Unieke bezoekers</th><th>Paginaweergaven</th></tr></thead><tbody>
      <?php foreach ($daily as $day): ?><tr><td><?=e($day['visit_date'])?></td><td><?=e((string)$day['visitors'])?></td><td><?=e((string)$day['views'])?></td></tr><?php endforeach; ?>
      <?php if (!$daily): ?><tr><td colspan="3">Nog geen gegevens beschikbaar.</td></tr><?php endif; ?>
      </tbody></table></div>
    </section>
    <section class="dashboard-panel"><h2>Top 10 meest bekeken kavels</h2>
      <div class="dashboard-table-scroll"><table class="dashboard-table"><thead><tr><th>Nr.</th><th>Titel</th><th>Weergaven</th></tr></thead><tbody>
      <?php foreach ($top as $lot): ?><tr><td><?=e((string)$lot['lot_number'])?></td><td><?=e($lot['title'])?></td><td><?=e((string)$lot['views'])?></td></tr><?php endforeach; ?>
      <?php if (!$top): ?><tr><td colspan="3">Nog geen kavelweergaven.</td></tr><?php endif; ?>
      </tbody></table></div>
    </section>
  <?php endif; ?>
  <section class="dashboard-panel" aria-labelledby="analytics-delete-title">
    <h2 id="analytics-delete-title">Statistiekgegevens handmatig verwijderen</h2>
    <p>Er gebeurt geen automatische opschoning. Verwijderen is definitief en raakt uitsluitend de tabel met bezoekersstatistieken; kavels, accounts en foto's blijven behouden. De data-only snapshots bevatten deze statistieken niet.</p>
    <?php if ($deleteError !== ''): ?><div class="alert error" role="alert"><?=e($deleteError)?></div><?php endif; ?>
    <?php if ($deletePreview !== null): ?>
      <div class="alert" role="status">
        <strong>Controle vóór verwijderen</strong>
        <p><?=e((string)$deletePreview['count'])?> records gevonden voor
          <?= $deletePreview['scope'] === 'all' ? 'alle bezoekersstatistieken' : 'datums strikt vóór ' . e($deletePreview['cutoff']) . ' (UTC)' ?>.
          Het aantal kan bij gelijktijdige bezoekers nog veranderen.</p>
        <form method="post" action="<?=e(url('admin/statistieken.php'))?>" onsubmit="return confirm('Definitief statistiekrecords verwijderen? Dit kan niet ongedaan worden gemaakt.');">
          <?=csrf_field()?>
          <input type="hidden" name="action" value="confirm_delete">
          <input type="hidden" name="preview_token" value="<?=e($deletePreview['token'])?>">
          <label for="analytics-confirm">Typ VERWIJDER om definitief te bevestigen</label>
          <input id="analytics-confirm" name="confirmation" required autocomplete="off" placeholder="VERWIJDER">
          <button type="submit" class="primary">Definitief verwijderen</button>
          <a href="<?=e(url('admin/statistieken.php'))?>">Annuleren</a>
        </form>
      </div>
    <?php else: ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:20px">
        <form method="post" action="<?=e(url('admin/statistieken.php'))?>">
          <?=csrf_field()?>
          <input type="hidden" name="action" value="preview_delete">
          <input type="hidden" name="scope" value="before">
          <h3>Verwijder alles vóór een datum</h3>
          <p>De gekozen datum zelf blijft behouden. De grens geldt in UTC.</p>
          <label for="analytics-before">Grensdatum</label>
          <input id="analytics-before" type="date" name="cutoff" required max="<?=e(gmdate('Y-m-d'))?>">
          <button type="submit" <?=!$available?'disabled':''?>>Bekijk te verwijderen records</button>
        </form>
        <form method="post" action="<?=e(url('admin/statistieken.php'))?>">
          <?=csrf_field()?>
          <input type="hidden" name="action" value="preview_delete">
          <input type="hidden" name="scope" value="all">
          <h3>Verwijder alle statistieken</h3>
          <p>Wist alle geregistreerde bezoekers- en kavelweergaven, ongeacht datum.</p>
          <button type="submit" <?=!$available?'disabled':''?>>Bekijk volledige verwijdering</button>
        </form>
      </div>
    <?php endif; ?>
  </section>
</div>
<?php admin_bottom(); ?>
