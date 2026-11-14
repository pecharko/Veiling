<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/private/bootstrap.php';
require_once dirname(__DIR__,2).'/private/lot_sellers.php';
require_once dirname(__DIR__,2).'/private/catalog_mutex.php';
require_auth();
$pdo=db();
$error='';
$filterType=in_array($_GET['type'] ?? '', ['kunstenaar','particulier'],true)?(string)$_GET['type']:'';
$search=mb_substr(trim((string)($_GET['q']??'')),0,100);
if (is_post()) {
  check_csrf();
  if (!in_array(($_POST['action']??''),['delete','artist-publication'],true)) { http_response_code(400); exit('Ongeldige actie.'); }
  $sellerId=filter_var($_POST['seller_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
  if ($sellerId===false || $sellerId===null) { http_response_code(400); exit('Ongeldige verkoper.'); }
  try {
    catalog_lock_for_request($pdo);
    if (!seller_can_manage(effective_admin_role())) { http_response_code(403); exit('Geen toegang.'); }
    if (($_POST['action']??'')==='artist-publication') {
      $status=(string)($_POST['artist_status']??'');
      if (!in_array($status,['published','unpublished'],true)) throw new InvalidArgumentException('Ongeldige publicatiestatus.');
      seller_set_artist_publication($pdo,(int)$sellerId,$status==='published',effective_admin_role(),(int)$_SESSION['admin_id']);
      flash('Publicatiestatus kunstenaar aangepast.');
      // Keep the submitted artist in view after the POST/redirect/GET refresh.
      $returnFilters=[];
      if ($filterType!=='') $returnFilters['type']=$filterType;
      if ($search!=='') $returnFilters['q']=$search;
      header('Location: '.url('admin/verkopers.php',$returnFilters).'#seller-row-'.(int)$sellerId,true,303);
      exit;
    }
    seller_require_edit($pdo,(int)$sellerId,effective_admin_role(),(int)$_SESSION['admin_id']);
    seller_delete($pdo,(int)$sellerId,effective_admin_role(),(int)$_SESSION['admin_id']);
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
  $portraitPaths=$pdo->query("SELECT seller_id,path FROM seller_images WHERE image_kind='portrait' AND slot=1")->fetchAll(PDO::FETCH_KEY_PAIR);

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
  <section class="dashboard-panel" aria-labelledby="seller-filter-heading">
    <div class="dashboard-panel-heading">
      <div><h2 id="seller-filter-heading">Verkopers beheren</h2><p>Zoek binnen kunstenaars en particulieren. Beide groepen worden afzonderlijk weergegeven.</p></div>
      <a class="primary" href="<?=e(url('admin/verkoper.php'))?>">+ Nieuwe verkoper</a>
    </div>
    <form class="dashboard-filters seller-filters" method="get" role="search">
      <label class="dashboard-search"><span class="sr-only">Zoek verkoper of kunstenaarsnaam</span><input type="search" name="q" placeholder="Zoek naam of kunstenaarsnaam…" value="<?=e($search)?>"></label>
      <label><span class="sr-only">Type verkoper</span><select name="type"><option value="">Alle verkopers</option><option value="kunstenaar" <?=$filterType==='kunstenaar'?'selected':''?>>Kunstenaars</option><option value="particulier" <?=$filterType==='particulier'?'selected':''?>>Particulieren</option></select></label>
      <button class="primary" type="submit">Zoeken</button>
    </form>
  </section>
  <?php foreach (['kunstenaar'=>'Kunstenaars','particulier'=>'Particulieren'] as $groupType=>$groupLabel): ?>
    <?php if ($filterType!=='' && $filterType!==$groupType) continue; ?>
    <?php $groupRows=array_values(array_filter($filteredRows,static fn($row)=>$row['seller_type']===$groupType)); ?>
  <section class="dashboard-panel" aria-labelledby="seller-list-<?=e($groupType)?>-heading">
    <div class="dashboard-panel-heading">
      <div><h2 id="seller-list-<?=e($groupType)?>-heading"><?=e($groupLabel)?></h2><p><?=count($groupRows)?> resultaten</p></div>
    </div>
    <div class="dashboard-table-scroll">
      <table class="dashboard-table seller-table">
        <thead><tr>
          <?php if ($groupType==='kunstenaar'): ?>
            <th scope="col">Portret</th><th scope="col">Kunstenaar</th><th scope="col">Publicatie</th><th scope="col">Kavels</th><th scope="col">Acties</th>
          <?php else: ?>
            <th scope="col">Naam</th><th scope="col">Kavels</th><th scope="col">Acties</th>
          <?php endif; ?>
        </tr></thead>
        <tbody>
          <?php foreach($groupRows as $r): ?>
            <tr id="seller-row-<?=(int)$r['id']?>" style="scroll-margin-top:110px">
              <?php $canEditSeller=seller_can_edit(effective_admin_role(),(int)$_SESSION['admin_id'],$r); ?>
              <?php if ($groupType==='kunstenaar'): ?>
                <?php
                  $portrait=(string)($portraitPaths[(int)$r['id']]??'');
                  $safePortrait=preg_match('~^uploads/[0-9]{4}/[0-9]{2}/[a-f0-9]{32}\\.(?:webp|jpg|png)$~D',$portrait)===1;
                ?>
                <td class="seller-portrait-cell">
                  <?php if ($safePortrait): ?>
                    <img class="seller-portrait-thumb" src="<?=e(url($portrait))?>" alt="Portret van <?=e((string)$r['artist_name'])?>" loading="lazy" decoding="async" width="48" height="48">
                  <?php else: ?>
                    <span class="seller-portrait-placeholder" role="img" aria-label="Geen portret beschikbaar">👤</span>
                  <?php endif; ?>
                </td>
                <td class="dashboard-title"><?php if($canEditSeller): ?><a href="<?=e(url('admin/verkoper.php',['id'=>(int)$r['id']]))?>"><?=e((string)$r['artist_name'])?></a><?php else: ?><?=e((string)$r['artist_name'])?><?php endif; ?></td>
                <td><span class="dashboard-category"><?=((int)$r['artist_published']===1?'Gepubliceerd':'Niet gepubliceerd')?></span></td>
              <?php else: ?>
                <td class="dashboard-title"><?php if($canEditSeller): ?><a href="<?=e(url('admin/verkoper.php',['id'=>(int)$r['id']]))?>"><?=e((string)$r['name'])?></a><?php else: ?><?=e((string)$r['name'])?><?php endif; ?></td>
              <?php endif; ?>
              <td><span class="seller-lot-count"><?=(int)$r['lot_count']?></span></td>
              <td><div class="seller-row-actions">
                <?php if($r['seller_type']==='kunstenaar' && seller_can_publish_artist(effective_admin_role())): ?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="artist-publication"><input type="hidden" name="seller_id" value="<?=(int)$r['id']?>"><input type="hidden" name="artist_status" value="<?=((int)$r['artist_published']===1?'unpublished':'published')?>"><button class="secondary" type="submit"><?=((int)$r['artist_published']===1?'Publicatie intrekken':'Publiceren')?></button></form><?php endif; ?>
                <?php if($canEditSeller): ?><a class="dashboard-edit" href="<?=e(url('admin/verkoper.php',['id'=>(int)$r['id']]))?>">✎ Bewerken</a><?php else: ?><span class="seller-readonly" title="Enkel de maker, veilingmeester of administrator mag dit beheren">Alleen bekijken</span><?php endif; ?>
                <?php if($canEditSeller): ?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="seller_id" value="<?=(int)$r['id']?>"><button class="secondary seller-delete" type="submit" <?=(int)$r['lot_count']>0?'disabled title="Verkoper is gekoppeld aan een kavel"':''?>>Verwijderen</button></form><?php endif; ?>
              </div></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$groupRows): ?><tr><td colspan="<?=$groupType==='kunstenaar'?5:3?>">Geen <?=e(mb_strtolower($groupLabel))?> gevonden. <a href="<?=e(url('admin/verkopers.php'))?>">Wis filters</a>.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
  <?php endforeach; ?>

</div>
<?php admin_bottom(); ?>
