<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/private/bootstrap.php';
require_once dirname(__DIR__,2).'/private/lot_sellers.php';
require_once dirname(__DIR__,2).'/private/catalog_mutex.php';
require_auth();
$pdo=db();
$error='';
if (is_post()) {
  check_csrf();
  if (($_POST['action']??'')!=='delete') { http_response_code(400); exit('Ongeldige actie.'); }
  $sellerId=filter_var($_POST['seller_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
  if ($sellerId===false || $sellerId===null) { http_response_code(400); exit('Ongeldige verkoper.'); }
  try {
    catalog_lock_for_request($pdo);
    if (!seller_can_manage(effective_admin_role())) { http_response_code(403); exit('Geen toegang.'); }
    seller_delete($pdo,(int)$sellerId);
    flash('Verkoper verwijderd.');
    redirect('admin/verkopers.php');
  } catch (InvalidArgumentException $ex) {
    $error=$ex->getMessage();
  } catch (PDOException $ex) {
    error_log('[Verkopersbeheer] Verwijderen mislukt.');
    $error='Verwijderen mislukt. Controleer de database.';
  }
}
try {
  $rows=seller_list($pdo);

} catch (PDOException $e) {
  error_log('[Verkopersbeheer] Laden mislukt.');
  http_response_code(503);
  admin_top('Verkopers beheren');
  echo '<div class="alert warning" role="alert">Verkopersbeheer is nog niet beschikbaar. Controleer of de afzonderlijke databasemigratie in de lokale testomgeving is toegepast.</div>';
  admin_bottom();
  exit;
}
admin_top('Verkopers beheren');
?>
<?php
$filterType=in_array($_GET['type'] ?? '', ['kunstenaar','particulier'],true)?(string)$_GET['type']:'';
$search=mb_substr(trim((string)($_GET['q']??'')),0,100);
$artistCount=count(array_filter($rows,static fn($row)=>$row['seller_type']==='kunstenaar'));
$privateCount=count($rows)-$artistCount;
$filteredRows=array_values(array_filter($rows,static function($row) use ($filterType,$search):bool {
  if ($filterType!=='' && $row['seller_type']!==$filterType) return false;
  return $search==='' || mb_stripos((string)$row['name'],$search)!==false
    || mb_stripos((string)$row['artist_name'],$search)!==false;
}));
?>
<div class="dashboard-modern seller-manager">
  <p class="dashboard-subtitle">Beheer kunstenaars en particulieren op dezelfde manier als je kavels beheert. Contactgegevens en bankgegevens blijven privé.</p>
  <?php if ($error): ?><div class="alert error" role="alert"><?=e($error)?></div><?php endif; ?>
  <div class="dashboard-stats seller-stats" aria-label="Verkopersstatistieken">
    <a class="dashboard-stat" href="<?=e(url('admin/verkopers.php'))?>"><span class="dashboard-icon" aria-hidden="true">◇</span><div><small>ALLE VERKOPERS</small><div><strong><?=count($rows)?></strong> verkopers</div></div></a>
    <a class="dashboard-stat" href="<?=e(url('admin/verkopers.php',['type'=>'kunstenaar']))?>"><span class="dashboard-icon" aria-hidden="true">◉</span><div><small>KUNSTENAARS</small><div><strong><?=$artistCount?></strong> kunstenaars</div></div></a>
    <a class="dashboard-stat" href="<?=e(url('admin/verkopers.php',['type'=>'particulier']))?>"><span class="dashboard-icon" aria-hidden="true">◎</span><div><small>PARTICULIEREN</small><div><strong><?=$privateCount?></strong> particulieren</div></div></a>
  </div>
  <section class="dashboard-panel" aria-labelledby="seller-list-heading">
    <div class="dashboard-panel-heading">
      <div><h2 id="seller-list-heading">Alle verkopers</h2><p><?=count($filteredRows)?> resultaten. Klik op Bewerken om een verkoper aan te passen.</p></div>
      <a class="primary" href="<?=e(url('admin/verkoper.php'))?>">+ Nieuwe verkoper</a>
    </div>
    <form class="dashboard-filters seller-filters" method="get" role="search">
      <label class="dashboard-search"><span class="sr-only">Zoek verkoper of kunstenaarsnaam</span><input type="search" name="q" placeholder="Zoek naam of kunstenaarsnaam…" value="<?=e($search)?>"></label>
      <label><span class="sr-only">Type verkoper</span><select name="type"><option value="">Alle verkopers</option><option value="kunstenaar" <?=$filterType==='kunstenaar'?'selected':''?>>Kunstenaars</option><option value="particulier" <?=$filterType==='particulier'?'selected':''?>>Particulieren</option></select></label>
      <button class="primary" type="submit">Zoeken</button>
    </form>
    <div class="dashboard-table-scroll">
      <table class="dashboard-table seller-table">
        <thead><tr><th scope="col">Naam</th><th scope="col">Type</th><th scope="col">Kunstenaarsnaam</th><th scope="col">Kavels</th><th scope="col">Aangemaakt door / op</th><th scope="col">Laatst gewijzigd door / op</th><th scope="col">Acties</th></tr></thead>
        <tbody>
          <?php foreach($filteredRows as $r): ?>
            <tr>
              <td class="dashboard-title"><a href="<?=e(url('admin/verkoper.php',['id'=>(int)$r['id']]))?>"><?=e($r['name'])?></a></td>
              <td><span class="dashboard-category"><?=e($r['seller_type']==='kunstenaar'?'Kunstenaar':'Particulier')?></span></td>
              <td><?=e($r['seller_type']==='kunstenaar'?(string)$r['artist_name']:'—')?></td>
              <td><span class="seller-lot-count"><?=(int)$r['lot_count']?></span></td>
              <td class="seller-audit"><strong><?=e($r['creator_name'] ?: 'Onbekend')?></strong><small><?=e($r['created_at'] ? gmdate('d/m/Y H:i',strtotime($r['created_at'])).' UTC' : 'Niet geregistreerd')?></small></td>
              <td class="seller-audit"><strong><?=e($r['editor_name'] ?: 'Onbekend')?></strong><small><?=e($r['updated_at'] ? gmdate('d/m/Y H:i',strtotime($r['updated_at'])).' UTC' : 'Niet geregistreerd')?></small></td>
              <td><div class="seller-row-actions">
                <a class="dashboard-edit" href="<?=e(url('admin/verkoper.php',['id'=>(int)$r['id']]))?>">✎ Bewerken</a>
                <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="seller_id" value="<?=(int)$r['id']?>"><button class="secondary seller-delete" type="submit" <?=(int)$r['lot_count']>0?'disabled title="Verkoper is gekoppeld aan een kavel"':''?>>Verwijderen</button></form>
              </div></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$filteredRows): ?><tr><td colspan="7">Geen verkopers gevonden. <a href="<?=e(url('admin/verkopers.php'))?>">Wis filters</a>.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

</div>
<?php admin_bottom(); ?>
