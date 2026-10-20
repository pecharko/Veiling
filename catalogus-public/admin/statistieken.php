<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/private/bootstrap.php';
require_auth();

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
</div>
<?php admin_bottom(); ?>
