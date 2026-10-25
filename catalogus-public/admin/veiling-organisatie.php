<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/private/bootstrap.php';
require_once dirname(__DIR__, 2) . '/private/catalog_mutex.php';
require_once dirname(__DIR__, 2) . '/private/auction_organisation.php';
require_auth();
$pdo = db();
$error = '';
$select = "SELECT id,lot_number,title,auction_number,status FROM lots WHERE sale_location='veiling' ORDER BY auction_number,id";
if (is_post()) {
  check_csrf();
  catalog_lock_for_request($pdo);
  try {
    $lotId = filter_var($_POST['lot_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    $position = filter_var($_POST['position'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if (!$lotId || !$position) throw new InvalidArgumentException('Kies een kavel en een geldig veilingsnummer.');
    $pdo->beginTransaction();
    $rows = $pdo->query($select)->fetchAll(PDO::FETCH_ASSOC);
    $ids = array_map(static fn (array $r): int => (int)$r['id'], $rows);
    $fingerprint = hash('sha256', implode(',', array_map(static fn (array $r): string => $r['id'].':'.($r['auction_number'] ?? 'null'), $rows)));
    if (!hash_equals($fingerprint, (string)($_POST['order_version'] ?? ''))) throw new RuntimeException('De volgorde is intussen veranderd. Vernieuw de pagina.');
    if (count($ids) !== count(array_filter($rows, static fn (array $r): bool => $r['auction_number'] !== null))) throw new RuntimeException('De nummering is onvolledig. Eerst controleren.');
    $ordered = auction_move_ids($ids, (int)$lotId, (int)$position);
    if ($ordered !== $ids) auction_apply_order($pdo, $ordered);
    $pdo->commit();
    flash('Veilingvolgorde opgeslagen. Kavels zijn doorlopend hernummerd.');
    redirect('admin/veiling-organisatie.php');
  } catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $error = $ex instanceof PDOException ? 'Opslaan mislukt. De bestaande volgorde bleef behouden.' : $ex->getMessage();
    error_log('[Auction order] '.$ex->getMessage());
  }
}
$rows = $pdo->query($select)->fetchAll(PDO::FETCH_ASSOC);
$orderVersion = hash('sha256', implode(',', array_map(static fn (array $r): string => $r['id'].':'.($r['auction_number'] ?? 'null'), $rows)));
admin_top('Veiling organisatie');
?>
<section class="dashboard-panel">
  <div class="dashboard-panel-heading">
    <div><h2>Volgorde van de veiling</h2><p>Sleep een kavel naar een andere positie of geef het een nieuw veilingsnummer. Alle overige kavels schuiven automatisch door.</p></div>
  </div>
  <?php if ($error !== ''): ?><div class="alert error" role="alert"><?=e($error)?></div><?php endif; ?>
  <p><?=count($rows)?> kavels met verkoopplaats <strong>Veiling</strong>. Lotnummers blijven behouden; alleen veilingsnummers wijzigen.</p>
  <?php if (!$rows): ?><p>Er zijn nog geen kavels in de veiling. Wijzig eerst de verkoopplaats van een kavel naar Veiling.</p><?php endif; ?>
  <div class="dashboard-table-scroll">
    <table class="dashboard-table" id="auction-order-table">
      <thead><tr><th>Positie</th><th>Lotnummer</th><th>Kavel</th><th>Status</th><th>Verplaatsen</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $index => $row): ?>
        <tr draggable="true" data-id="<?=(int)$row['id']?>" data-position="<?=$index+1?>">
          <td><strong><?=e((string)$row['auction_number'])?></strong> <span aria-hidden="true" title="Versleep">↕</span></td>
          <td><?=e((string)$row['lot_number'])?></td>
          <td><a href="<?=e(url('admin/kavel.php',['id'=>(int)$row['id']]))?>"><?=e($row['title'])?></a></td>
          <td><?=e($row['status']==='published'?'Gepubliceerd':'Concept')?></td>
          <td>
            <form method="post" class="auction-move-form">
              <?=csrf_field()?>
              <input type="hidden" name="order_version" value="<?=e($orderVersion)?>">
              <input type="hidden" name="lot_id" value="<?=(int)$row['id']?>">
              <label>Nieuw nr. <input type="number" name="position" min="1" max="<?=count($rows)?>" required value="<?=$index+1?>" style="width:6rem"></label>
              <button type="submit" class="secondary">Verplaats</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <form method="post" id="auction-drop-form" hidden>
    <?=csrf_field()?>
    <input type="hidden" name="order_version" value="<?=e($orderVersion)?>">
    <input type="hidden" name="lot_id" id="auction-drag-id">
    <input type="hidden" name="position" id="auction-drag-position">
  </form>
  <p id="auction-drag-help">Versleep een rij op desktop, of gebruik het nummer en de knop Verplaats (ook geschikt voor toetsenbord en mobiel).</p>
</section>
<script>
(function () {
  const table = document.getElementById('auction-order-table');
  let dragging = null;
  table.addEventListener('dragstart', function (event) {
    const row = event.target.closest('tr[data-id]');
    if (!row) return;
    dragging = row.dataset.id;
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', dragging);
  });
  table.addEventListener('dragover', function (event) {
    if (dragging && event.target.closest('tr[data-position]')) event.preventDefault();
  });
  table.addEventListener('drop', function (event) {
    const row = event.target.closest('tr[data-position]');
    if (!dragging || !row) return;
    event.preventDefault();
    document.getElementById('auction-drag-id').value = dragging;
    document.getElementById('auction-drag-position').value = row.dataset.position;
    document.getElementById('auction-drop-form').requestSubmit();
    dragging = null;
  });
  table.addEventListener('dragend', function () { dragging = null; });
})();
</script>
<?php admin_bottom(); ?>
