<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/private/bootstrap.php';
require_auth();
require_no_index();
require_once dirname(__DIR__, 2) . '/private/analytics_control.php';
require_once dirname(__DIR__, 2) . '/private/visitor_stats_archive_one_day_experimental.php';
require_once dirname(__DIR__, 2) . '/private/visitor_stats_cron_log.php';
require_once dirname(__DIR__, 2) . '/private/visitor_geo_dashboard.php';
require_once dirname(__DIR__, 2) . '/private/visitor_geo_state.php';
require_once dirname(__DIR__, 2) . '/private/visitor_geo_delete.php';
require_once dirname(__DIR__, 2) . '/private/catalog_mutex.php';
require_once dirname(__DIR__, 2) . '/private/catalog_popular_lots.php';

// All deletion requires a current authenticated session, a valid CSRF token,
// a server-generated preview token and explicit confirmation in a second POST.
$deleteError = '';
$toggleError = '';
$geoToggleError = '';
$ipToggleError = '';
$deletePreview = null;
$archivePreview = null;
if (is_post()) {
    check_csrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'toggle_ip_exclusion') {
        require_administrator();
        $enabled = $_POST['enabled'] ?? null;
        if (!is_string($enabled) || !in_array($enabled, ['0','1'], true)) {
            $ipToggleError = 'Ongeldige keuze voor IP-uitsluiting.';
        } else {
            try {
                catalog_lock_for_request(db());
                analytics_ip_set_exclusion(db(), $enabled === '1');
                flash($enabled === '1'
                    ? 'IP-uitsluiting ingeschakeld. Opgeslagen IP-adressen tellen niet mee.'
                    : 'IP-uitsluiting uitgeschakeld. Statistieken tellen normaal; de IP-lijst blijft bewaard.');
                redirect('admin/statistieken.php');
            } catch (Throwable $e) {
                $ipToggleError = 'IP-uitsluiting kon niet worden aangepast. Probeer later opnieuw.';
            }
        }
    }
    if ($action === 'toggle_collection') {
        $enabled = (string)($_POST['enabled'] ?? '');
        if (!in_array($enabled, ['0', '1'], true)) {
            $toggleError = 'Ongeldige keuze voor bezoekersregistratie.';
        } else {
            try {
                if ($enabled === '0') visitor_geo_set_disabled();
                set_analytics_collection_enabled($enabled === '1');
                flash($enabled === '1'
                    ? 'Bezoekersregistratie ingeschakeld. Nieuwe bezoeken worden weer geteld.'
                    : 'Bezoekersregistratie uitgeschakeld. Bestaande statistieken blijven bewaard.');
                redirect('admin/statistieken.php');
            } catch (Throwable $exception) {
                error_log('Analytics toggle failed (no visitor data logged).');
                $toggleError = 'Wijzigen mislukt. Controleer de schrijfrechten van de private map.';
            }
        }
    } elseif ($action === 'toggle_geo_collection') {
        $enabled = (string)($_POST['enabled'] ?? '');
        if (!in_array($enabled, ['0', '1'], true)) {
            $geoToggleError = 'Ongeldige keuze voor geografische registratie.';
        } else {
            try {
                if ($enabled === '1') {
                    visitor_geo_set_enabled(db());
                    flash('Geografische registratie ingeschakeld. Locaties worden uitsluitend vanaf de ingestelde startdatum geschat.');
                } else {
                    visitor_geo_set_disabled();
                    flash('Geografische registratie uitgeschakeld. Bestaande geografische gegevens blijven bewaard.');
                }
                redirect('admin/statistieken.php');
            } catch (Throwable $exception) {
                error_log('Geo registration toggle failed (no visitor data logged).');
                $geoToggleError = 'Geografische registratie wijzigen mislukt. Controleer de configuratie en database.';
            }
        }
    } elseif ($action === 'preview_archive') {
        unset($_SESSION['analytics_archive_pending']);
        try {
            $cutoff = gmdate('Y-m-d', strtotime('-3 days'));
            $stmt = db()->prepare('SELECT COUNT(*) FROM visitor_stats_daily WHERE visit_date < ?');
            $stmt->execute([$cutoff]);
            $count = (int)$stmt->fetchColumn();
            $token = bin2hex(random_bytes(24));
            $_SESSION['analytics_archive_pending'] = ['token' => $token, 'cutoff' => $cutoff, 'expires' => time() + 300];
            $archivePreview = ['token' => $token, 'cutoff' => $cutoff, 'count' => $count];
        } catch (Throwable $e) {
            $deleteError = 'Kon archivering niet voorbereiden. Controleer of de geautoriseerde aggregatietabellen bestaan.';
        }
    } elseif ($action === 'confirm_archive') {
        $pending = $_SESSION['analytics_archive_pending'] ?? null;
        unset($_SESSION['analytics_archive_pending']);
        if (!is_array($pending) || !is_string($pending['token'] ?? null)
            || !hash_equals($pending['token'], (string)($_POST['preview_token'] ?? ''))
            || (int)($pending['expires'] ?? 0) < time()
            || (string)($_POST['confirmation'] ?? '') !== 'ARCHIVEER') {
            $deleteError = 'Archiveringsbevestiging ongeldig of verlopen.';
        } else {
            try {
                // A bounded web request: the CLI cron remains the 14-day catch-up path.
                // Each day commits independently; do not conceal completed days on later failure.
                $archivedDays = 0;
                $deletedRows = 0;
                try {
                    for ($i = 0; $i < 3; ++$i) {
                        $result = archive_visitor_stats_one_day(db(), (string)$pending['cutoff']);
                        if ($result['days'] === 0) break;
                        $archivedDays += $result['days'];
                        $deletedRows += $result['rows'];
                    }
                } catch (Throwable $e) {
                    error_log('Manual archive batch interrupted; completed_days=' . $archivedDays);
                    $deleteError = 'Archivering onderbroken na ' . $archivedDays
                        . ' verwerkte UTC-dagen (' . $deletedRows
                        . ' records). Eerder voltooide dagen blijven veilig gearchiveerd. Controleer de status voordat je opnieuw start.';
                }
                if ($deleteError === '') {
                    flash($archivedDays === 0
                        ? 'Geen afgesloten dag om te archiveren.'
                        : $deletedRows . ' detailrecords van ' . $archivedDays
                          . ' UTC-dagen veilig samengevat en verwijderd. Maximaal 3 dagen per handmatige start; herhaal indien nodig.');
                    redirect('admin/statistieken.php');
                }
            } catch (Throwable $e) {
                error_log('Manual analytics archive failed; no details logged.');
                $deleteError = 'Archivering gestopt zonder gedeeltelijke wijziging van de lopende dag. Mogelijke oorzaken: een tweede verzoek, te veel records op één dag of een databasefout.';
            }
        }
    } elseif ($action === 'preview_delete') {
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
                    $extra = $pdo->prepare('SELECT COUNT(*) FROM visitor_stats_day_totals WHERE visit_date < ?');
                    $extra->execute([$cutoff]);
                    $count += (int)$extra->fetchColumn();
                } else {
                    $count = (int)$pdo->query('SELECT COUNT(*) FROM visitor_stats_daily')->fetchColumn();
                    $count += (int)$pdo->query('SELECT COUNT(*) FROM visitor_stats_day_totals')->fetchColumn();
                }
                if (visitor_geo_state($pdo)!==null) {
                    foreach (['visitor_geo_daily','visitor_geo_day_totals'] as $table) {
                        $geoCount=$pdo->prepare('SELECT COUNT(*) FROM '.$table.($scope==='before'?' WHERE visit_date < ?':''));
                        $geoCount->execute($scope==='before'?[$cutoff]:[]);
                        $count+=(int)$geoCount->fetchColumn();$geoCount->closeCursor();
                    }
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
                // Delete both detailed and aggregated analytics atomically.
                // No TRUNCATE, no dynamic table names, no changes to lots/accounts.
                $analyticsLocked=false;
                catalog_data_lock($pdo,0);$analyticsLocked=true;
                restore_journal_require_clear();
                $pdo->beginTransaction();
                if ($pending['scope'] === 'before') {
                    $cutoff = (string)($pending['cutoff'] ?? '');
                    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $cutoff, new DateTimeZone('UTC'));
                    if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D', $cutoff)
                        || !$date || $date->format('Y-m-d') !== $cutoff
                        || $cutoff > gmdate('Y-m-d')) {
                        throw new RuntimeException('Ongeldige opgeslagen grensdatum.');
                    }
                    $geoDeleted=visitor_geo_delete_rows($pdo,$cutoff);
                    $stmt = $pdo->prepare('DELETE FROM visitor_stats_lot_totals WHERE visit_date < ?');
                    $stmt->execute([$cutoff]);
                    $stmt = $pdo->prepare('DELETE FROM visitor_stats_day_totals WHERE visit_date < ?');
                    $stmt->execute([$cutoff]);
                    $deleted = $stmt->rowCount();
                    $stmt = $pdo->prepare('DELETE FROM visitor_stats_daily WHERE visit_date < ?');
                    $stmt->execute([$cutoff]);
                    $deleted += $stmt->rowCount();
                } else {
                    $geoDeleted=visitor_geo_delete_rows($pdo,null);
                    $pdo->exec('DELETE FROM visitor_stats_lot_totals');
                    $deleted = $pdo->exec('DELETE FROM visitor_stats_day_totals');
                    $deleted += $pdo->exec('DELETE FROM visitor_stats_daily');
                }
                $pdo->commit();
                $analyticsLocked=false;catalog_data_unlock($pdo);
                $deleted+=$geoDeleted;
                flash((string)$deleted . ' statistiekrecords definitief verwijderd.');
                redirect('admin/statistieken.php');
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Analytics deletion failed (no sensitive details logged).');
                $deleteError = 'Verwijderen mislukt. Controleer de statistieken en probeer alleen na onderzoek opnieuw.';
            } finally {
                if ($analyticsLocked??false) catalog_data_unlock($pdo);
            }
        }
    } else {
        $deleteError = 'Onbekende actie.';
    }
}

$summary = ['visitors' => 0, 'views' => 0, 'lot_views' => 0];
$daily = [];
$top = [];
$popularGroups = [];
$popularSplitActive = (int)(settings()['catalog_split_active'] ?? 0) === 1;
$available = true;
$campaign = ['visitors' => 0, 'views' => 0, 'lot_views' => 0];
try {
    $pdo = db();
    // Archived days are stored in totals; live days remain in visitor_stats_daily.
    // These date sets are disjoint because the archive transaction inserts totals
    // and removes the matching raw rows atomically.
    $summary = $pdo->query("SELECT COALESCE(SUM(visitors),0) visitors,
      COALESCE(SUM(views),0) views, COALESCE(SUM(lot_views),0) lot_views FROM (
        SELECT visitor_days visitors, views, lot_views
        FROM visitor_stats_day_totals WHERE visit_date >= UTC_DATE() - INTERVAL 29 DAY
        UNION ALL
        SELECT COUNT(DISTINCT visitor_id) visitors, SUM(views) views,
          SUM(CASE WHEN page_kind='lot' THEN views ELSE 0 END) lot_views
        FROM visitor_stats_daily WHERE visit_date >= UTC_DATE() - INTERVAL 29 DAY
        GROUP BY visit_date
      ) recent_days")->fetch();
    $daily = $pdo->query("SELECT visit_date, SUM(visitors) visitors, SUM(views) views FROM (
        SELECT visit_date, visitor_days visitors, views FROM visitor_stats_day_totals
        WHERE visit_date >= UTC_DATE() - INTERVAL 29 DAY
        UNION ALL
        SELECT visit_date, COUNT(DISTINCT visitor_id) visitors, SUM(views) views
        FROM visitor_stats_daily WHERE visit_date >= UTC_DATE() - INTERVAL 29 DAY
        GROUP BY visit_date
      ) recent_days GROUP BY visit_date ORDER BY visit_date DESC")->fetchAll();
    $top = $pdo->query("SELECT l.lot_number, l.title, SUM(v.views) views
      FROM (
        SELECT lot_id, views FROM visitor_stats_lot_totals
        WHERE visit_date >= UTC_DATE() - INTERVAL 29 DAY
        UNION ALL
        SELECT lot_id, views FROM visitor_stats_daily
        WHERE page_kind='lot' AND visit_date >= UTC_DATE() - INTERVAL 29 DAY
      ) v INNER JOIN lots l ON l.id=v.lot_id
      GROUP BY l.id, l.lot_number, l.title ORDER BY views DESC, l.lot_number LIMIT 10")->fetchAll();
    // This is the same seven-day ranking that generates public 'Populair' tags.
    $popularGroups = catalog_popular_lots($pdo, $popularSplitActive);
    // Historical aggregates and active unarchived days have disjoint dates.
    $campaign = $pdo->query("SELECT COALESCE(SUM(visitor_days),0) visitors, COALESCE(SUM(views),0) views, COALESCE(SUM(lot_views),0) lot_views FROM (
      SELECT visitor_days, views, lot_views FROM visitor_stats_day_totals
      UNION ALL
      SELECT COUNT(DISTINCT visitor_id) visitor_days, SUM(views) views,
        SUM(CASE WHEN page_kind='lot' THEN views ELSE 0 END) lot_views
      FROM visitor_stats_daily GROUP BY visit_date
    ) complete_days")->fetch();

} catch (PDOException $exception) {
    $available = false;
}
$cronRuns = visitor_stats_cron_log_read();
$cronSuccessful = array_slice(array_values(array_filter($cronRuns, static fn(array $run): bool => ($run['status'] ?? '') === 'ok')), 0, 3);
$cronAlerts = array_slice(array_values(array_filter($cronRuns, static fn(array $run): bool => in_array($run['status'] ?? '', ['error', 'limit'], true))), 0, 3);
$collectionEnabled = analytics_collection_enabled();
$ipAvailable = false;
$ipEnabled = true;
$ipRows = [];
$ipCount = 0;
try {
    $ipEnabled = analytics_ip_exclusion_enabled(db());
    $ipCount = (int)db()->query('SELECT COUNT(DISTINCT ip_address) FROM visitor_stats_ip_exclusions')->fetchColumn();
    $ipRows = db()->query('SELECT ip_address,admin_id FROM visitor_stats_ip_exclusions ORDER BY created_at DESC,ip_address,admin_id LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
    $ipAvailable = true;
} catch (Throwable $e) { /* No raw address or database details in logs/output. */ }

$geoState = null;
$geoStatusError = '';
try { $geoState = visitor_geo_state(db()); }
catch (Throwable $e) { $geoStatusError = 'Geografische registratie niet beschikbaar: controleer de databasetabellen.'; }
$geoEnabled = $geoStatusError === '' && visitor_geo_collection_enabled($geoState);
$geoReports=[];
foreach (['Laatste 30 dagen'=>30,'Veilingcampagne sinds geografische registratie'=>null] as $title=>$days) {
    try { $geoReports[$title]=visitor_geo_dashboard(db(),$days); }
    catch (Throwable $e) { $geoReports[$title]=['status'=>'error']; }
}
admin_top('Bezoekersstatistieken');
?>
<div class="dashboard-modern">
  <section class="dashboard-panel" aria-labelledby="analytics-switch-title">
    <h2 id="analytics-switch-title">Bezoekersregistratie</h2>
    <p><strong>Huidige status: <?= $collectionEnabled ? 'Aan' : 'Uit' ?></strong></p>
    <p><?= $collectionEnabled
      ? 'Nieuwe bezoekers- en kavelweergaven worden geregistreerd.'
      : 'Nieuwe bezoekers- en kavelweergaven worden niet geregistreerd. De bestaande statistieken blijven beschikbaar.' ?></p>
    <?php if ($toggleError !== ''): ?><div class="alert error" role="alert"><?=e($toggleError)?></div><?php endif; ?>
    <form method="post" action="<?=e(url('admin/statistieken.php'))?>">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="toggle_collection">
      <input type="hidden" name="enabled" value="<?= $collectionEnabled ? '0' : '1' ?>">
      <button type="submit" class="<?= $collectionEnabled ? '' : 'primary' ?>"><?= $collectionEnabled ? 'Registratie uitschakelen' : 'Registratie inschakelen' ?></button>
    </form>
    <div class="dashboard-table-scroll">
      <h3>Geografische bezoekersregistratie</h3>
      <p><strong>Huidige status: <?= $geoEnabled ? 'Aan' : 'Uit' ?></strong></p>
      <p>Geografische herkomst wordt uitsluitend geschat wanneer zowel de algemene als de geografische bezoekersregistratie aanstaat. Bij uitschakeling blijven bestaande gegevens bewaard.</p>
      <?php if ($geoStatusError !== ''): ?><div class="alert error" role="alert"><?=e($geoStatusError)?></div><?php endif; ?>
      <?php if ($geoToggleError !== ''): ?><div class="alert error" role="alert"><?=e($geoToggleError)?></div><?php endif; ?>
      <form method="post" action="<?=e(url('admin/statistieken.php'))?>">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="toggle_geo_collection">
        <input type="hidden" name="enabled" value="<?= $geoEnabled ? '0' : '1' ?>">
        <button type="submit" class="<?= $geoEnabled ? '' : 'primary' ?>" <?= (!$collectionEnabled || $geoStatusError !== '' || $geoState === null) ? 'disabled' : '' ?>><?= $geoEnabled ? 'Geostatistieken uitschakelen' : 'Geostatistieken inschakelen' ?></button>
      </form>
      <?php if (!$collectionEnabled): ?><p><small>Schakel eerst de algemene bezoekersregistratie in. Geostatistieken blijven afzonderlijk uitgeschakeld tot je ze opnieuw inschakelt.</small></p><?php endif; ?>
      <?php if ($geoState !== null && $geoState['start_date'] === null): ?><p><small>De eerste activering start de geografische meting vanaf de huidige UTC-dag. Oudere bezoeken worden niet achteraf gelokaliseerd.</small></p><?php endif; ?>
    </div>
    <p><small>Dit wist geen bestaande gegevens. Handmatige archivering en verwijdering blijven afzonderlijke acties.</small></p>
  </section>
  <section class="dashboard-panel" aria-labelledby="ip-exclusion-title">
    <h2 id="ip-exclusion-title">IP-adressen uitsluiten van statistieken</h2>
    <?php if (!$ipAvailable): ?>
      <p role="alert">IP-uitsluiting is tijdelijk niet beschikbaar. Bezoekersregistratie wordt uit voorzorg overgeslagen zolang de uitsluiting niet gecontroleerd kan worden.</p>
    <?php else: ?>
      <p><strong>Huidige status: <?= $ipEnabled ? 'Aan' : 'Uit' ?></strong> · <?=e($ipCount)?> opgeslagen IP-adressen.</p>
    <?php endif; ?>
    <p>Na elke geslaagde login slaan we het IP-adres met het admin-ID op. Bestaande combinaties worden niet dubbel toegevoegd. Bij ingeschakelde uitsluiting telt geen enkel bezoek vanaf deze adressen mee in bezoekers- of geostatistieken, ook na uitloggen en vanuit een andere browser. Gebruikers die hetzelfde internetadres delen vallen onder dezelfde uitsluiting.</p>
    <p>Deze functie staat standaard aan. Uitschakelen bewaart de lijst en laat bezoeken weer normaal tellen wanneer bezoekersregistratie aan staat. Lijst en instelling zitten niet in snapshots en blijven bij herstel behouden.</p>
    <?php if ($ipToggleError !== ''): ?><div class="alert error" role="alert"><?=e($ipToggleError)?></div><?php endif; ?>
    <form method="post" action="<?=e(url('admin/statistieken.php'))?>">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="toggle_ip_exclusion">
      <input type="hidden" name="enabled" value="<?= $ipEnabled ? '0' : '1' ?>">
      <button type="submit" <?= !$ipAvailable ? 'disabled' : '' ?>><?= $ipEnabled ? 'IP-uitsluiting uitschakelen' : 'IP-uitsluiting inschakelen' ?></button>
    </form>
    <?php if ($ipRows): ?>
      <div class="dashboard-table-scroll"><table><thead><tr><th>IP-adres</th><th>Admin-ID</th></tr></thead><tbody>
      <?php foreach ($ipRows as $row): ?><tr><td><?=e(inet_ntop((string)$row['ip_address']))?></td><td><?=e($row['admin_id'])?></td></tr><?php endforeach; ?>
      </tbody></table></div><p><small>Maximaal de 50 laatst toegevoegde combinaties. Alle opgeslagen adressen blijven meetellen voor uitsluiting.</small></p>
    <?php endif; ?>
  </section>
  <p class="dashboard-subtitle">Overzicht van de afgelopen 30 UTC-dagen. Unieke bezoekers zijn daggebonden schattingen: wie op meerdere dagen terugkomt telt opnieuw mee.</p>
  <?php if (!$available): ?>
    <div class="alert error">Statistieken zijn nog niet beschikbaar. Controleer de database-installatie.</div>
  <?php else: ?>
    <div class="dashboard-stats">
      <div class="dashboard-stat"><div><small>UNIEKE BEZOEKERSDAGEN</small><div><strong><?=e((string)$summary['visitors'])?></strong></div></div></div>
      <div class="dashboard-stat"><div><small>PAGINAWEERGAVEN</small><div><strong><?=e((string)$summary['views'])?></strong></div></div></div>
      <div class="dashboard-stat"><div><small>KAVELWEERGAVEN</small><div><strong><?=e((string)$summary['lot_views'])?></strong></div></div></div>
    </div>
    <section class="dashboard-panel"><h2>Volledige veilingcampagne (tot vandaag)</h2>
      <p>Bezoekersdagen: <strong><?=e((string)$campaign['visitors'])?></strong> · Paginaweergaven: <strong><?=e((string)$campaign['views'])?></strong> · Kavelweergaven: <strong><?=e((string)$campaign['lot_views'])?></strong>.</p>
      <p>Dit zijn dagelijkse bezoekersschattingen, niet het aantal unieke personen over alle dagen.</p>
    </section>
    <section class="dashboard-panel"><h2>Bezoekers per dag</h2>
      <div class="dashboard-table-scroll"><table class="dashboard-table"><thead><tr><th>Dag (UTC)</th><th>Unieke bezoekers</th><th>Paginaweergaven</th></tr></thead><tbody>
      <?php foreach ($daily as $day): ?><tr><td><?=e($day['visit_date'])?></td><td><?=e((string)$day['visitors'])?></td><td><?=e((string)$day['views'])?></td></tr><?php endforeach; ?>
      <?php if (!$daily): ?><tr><td colspan="3">Nog geen gegevens beschikbaar.</td></tr><?php endif; ?>
      </tbody></table></div>
    </section>
    <section class="dashboard-panel" aria-labelledby="popular-lots-title">
      <h2 id="popular-lots-title">Populaire kavels — laatste 7 dagen</h2>
      <p>Deze kavels krijgen het label <strong>Populair</strong> op de website. De rangschikking telt kavelweergaven van de laatste 7 UTC-dagen (vandaag inbegrepen), inclusief gearchiveerde dagen. Bij gelijke aantallen beslist het kavel-ID. Alleen gepubliceerde of verkochte kavels tellen mee.</p>
      <?php foreach ($popularGroups as $group => $popularLots): ?>
        <?php if ($popularSplitActive): ?><h3><?=e($group === 'veiling' ? 'Veiling — top 5' : 'Marktplaats — top 5')?></h3><?php endif; ?>
        <div class="dashboard-table-scroll"><table class="dashboard-table">
          <thead><tr><th>Positie</th><th>Kavelnr.</th><th>Kavel</th><th>Weergaven (7 dagen)</th><th>Label</th></tr></thead>
          <tbody>
          <?php foreach ($popularLots as $rank => $popularLot): ?>
            <tr>
              <td><?= (int)$rank + 1 ?></td>
              <td><?=e((string)$popularLot['lot_number'])?></td>
              <td><a href="<?=e(url('kavel.php', ['nr'=>(int)$popularLot['lot_number']]))?>" target="_blank" rel="noopener noreferrer"><?=e((string)$popularLot['title'])?></a></td>
              <td><?=e((string)$popularLot['total_views'])?></td>
              <td><strong>Populair</strong></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$popularLots): ?><tr><td colspan="5">Nog geen bekeken kavels in deze groep tijdens de laatste 7 dagen.</td></tr><?php endif; ?>
          </tbody>
        </table></div>
      <?php endforeach; ?>
    </section>
    <section class="dashboard-panel"><h2>Top 10 meest bekeken kavels</h2>
      <div class="dashboard-table-scroll"><table class="dashboard-table"><thead><tr><th>Nr.</th><th>Titel</th><th>Weergaven</th></tr></thead><tbody>
      <?php foreach ($top as $lot): ?><tr><td><?=e((string)$lot['lot_number'])?></td><td><?=e($lot['title'])?></td><td><?=e((string)$lot['views'])?></td></tr><?php endforeach; ?>
      <?php if (!$top): ?><tr><td colspan="3">Nog geen kavelweergaven.</td></tr><?php endif; ?>
      </tbody></table></div>
    </section>
  <?php endif; ?>
  <?=visitor_geo_render_dashboard($geoReports)?>
  <section class="dashboard-panel" aria-labelledby="analytics-cron-title">
    <h2 id="analytics-cron-title">Automatische archivering — cronlog</h2>
    <p>De drie recentste geslaagde uitvoeringen en maximaal drie uitvoeringen met een fout of waarschuwing, uit de laatste 30 geregistreerde runs (UTC). Een waarschuwing betekent dat de inhaallimiet van 14 dagen bereikt is. Een ontbrekend log bewijst niet dat de cronjob niet actief is.</p>
    <?php if (!$cronRuns): ?>
      <p>Nog geen geregistreerde cronuitvoeringen. Controleer of de geplande taak actief is en of de private map schrijfbaar is.</p>
    <?php else: ?>
      <h3>Laatste 3 geslaagde uitvoeringen</h3>
      <div class="dashboard-table-scroll"><table class="dashboard-table">
        <thead><tr><th>Uitgevoerd (UTC)</th><th>Resultaat</th><th>Verwijderde records</th><th>Gearchiveerde dagen</th><th>Paginaweergaven</th></tr></thead>
        <tbody>
        <?php foreach ($cronSuccessful as $run): ?>
          <tr>
            <td><?=e((string)$run['finished_utc'])?></td>
            <td>Geslaagd</td>
            <td><?=e((string)$run['rows_deleted'])?></td>
            <td><?=e((string)($run['days_archived'] ?? 0))?></td>
            <td><?=e((string)($run['views_archived'] ?? 0))?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$cronSuccessful): ?><tr><td colspan="5">Geen geslaagde uitvoeringen in de beschikbare loghistoriek.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
      <h3>Laatste 3 fouten of waarschuwingen</h3>
      <?php if (!$cronAlerts): ?>
        <p>Geen fouten of waarschuwingen in de beschikbare loghistoriek.</p>
      <?php else: ?>
        <div class="dashboard-table-scroll"><table class="dashboard-table">
          <thead><tr><th>Uitgevoerd (UTC)</th><th>Resultaat</th><th>Verwijderde records</th><th>Gearchiveerde dagen</th><th>Foutcategorie</th></tr></thead>
          <tbody>
          <?php foreach ($cronAlerts as $run): ?>
            <tr>
              <td><?=e((string)$run['finished_utc'])?></td>
              <td><?=e($run['status'] === 'limit' ? 'Waarschuwing: inhaallimiet bereikt' : 'Fout')?></td>
              <td><?=e((string)$run['rows_deleted'])?></td>
              <td><?=e((string)($run['days_archived'] ?? 0))?></td>
              <td><?=e((string)($run['error_type'] ?? '—'))?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    <?php endif; ?>
  </section>
  <section class="dashboard-panel" aria-labelledby="analytics-archive-title">
    <h2 id="analytics-archive-title">Handmatige archivering starten</h2>
    <p>Start de archivering onmiddellijk vanuit de adminpagina. Per bevestiging worden maximaal 3 afgesloten UTC-dagen ouder dan 3 dagen verwerkt, met maximaal 20.000 records per dag. Elke dag wordt binnen een eigen transactie samengevat voordat detailrecords worden verwijderd. De dagelijkse OVH-cron blijft onafhankelijk actief.</p>
    <?php if ($archivePreview !== null): ?>
      <p><strong><?=e((string)$archivePreview['count'])?></strong> historische detailrecords vóór <?=e($archivePreview['cutoff'])?> (UTC) komen in totaal in aanmerking. Per bevestiging worden maximaal 3 oudste dagen verwerkt (maximaal 20.000 records per dag).</p>
      <form method="post" action="<?=e(url('admin/statistieken.php'))?>" onsubmit="return confirm('Archiveren en detailcodes definitief wissen?');">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="confirm_archive">
        <input type="hidden" name="preview_token" value="<?=e($archivePreview['token'])?>">
        <label for="analytics-archive-confirm">Typ ARCHIVEER ter bevestiging</label>
        <input id="analytics-archive-confirm" name="confirmation" required autocomplete="off">
        <button type="submit" class="primary">Archivering nu starten</button>
        <a href="<?=e(url('admin/statistieken.php'))?>">Annuleren</a>
      </form>
    <?php else: ?>
      <form method="post" action="<?=e(url('admin/statistieken.php'))?>">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="preview_archive">
        <button type="submit" <?=!$available?'disabled':''?>>Handmatige archivering voorbereiden</button>
      </form>
    <?php endif; ?>
  </section>
  <section class="dashboard-panel" aria-labelledby="analytics-delete-title">
    <h2 id="analytics-delete-title">Statistiekgegevens handmatig verwijderen</h2>
    <p>Er gebeurt geen automatische opschoning. Verwijderen is definitief en raakt uitsluitend de tabel met bezoekersstatistieken; kavels, accounts en foto's blijven behouden. De data-only snapshots bevatten deze statistieken niet. Ook geaggregeerde statistieken worden verwijderd.</p>
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
