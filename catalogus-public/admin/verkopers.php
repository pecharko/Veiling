<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/private/bootstrap.php';
require_once dirname(__DIR__,2).'/private/lot_sellers.php';
require_once dirname(__DIR__,2).'/private/catalog_mutex.php';
require_auth();
$sellerScreen=($sellerScreen??'particulier')==='kunstenaar'?'kunstenaar':'particulier';
$screenUrl=$sellerScreen==='kunstenaar'?'admin/kunstenaars.php':'admin/verkopers.php';
$editorUrl=$sellerScreen==='kunstenaar'?'admin/kunstenaar-bewerken.php':'admin/verkoper.php';
$screenTitle=$sellerScreen==='kunstenaar'?'Kunstenaars beheren':'Verkopers beheren';
$screenSingular=$sellerScreen==='kunstenaar'?'kunstenaar':'verkoper';
$pdo=db();
$error='';
if (is_post()) {
  check_csrf();
  if (!in_array(($_POST['action']??''),['delete','artist-publication'],true)) { http_response_code(400); exit('Ongeldige actie.'); }
  $sellerId=filter_var($_POST['seller_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
  if ($sellerId===false || $sellerId===null) { http_response_code(400); exit('Ongeldige verkoper.'); }
  // Never allow an artist operation on the private-seller screen or vice versa.
  $screenCheck=$pdo->prepare('SELECT seller_type FROM lot_sellers WHERE id=?');
  $screenCheck->execute([(int)$sellerId]);
  if ($screenCheck->fetchColumn()!==$sellerScreen) { http_response_code(404); exit('Verkoper niet gevonden.'); }
  if (($_POST['action']??'')==='artist-publication' && $sellerScreen!=='kunstenaar') {
    http_response_code(403); exit('Deze actie is enkel beschikbaar bij kunstenaars.');
  }
  try {
    catalog_lock_for_request($pdo);
    if (!seller_can_manage(effective_admin_role())) { http_response_code(403); exit('Geen toegang.'); }
    if (($_POST['action']??'')==='artist-publication') {
      $status=(string)($_POST['artist_status']??'');
      if (!in_array($status,['new','published','unpublished'],true)) throw new InvalidArgumentException('Ongeldige publicatiestatus.');
      seller_set_artist_publication($pdo,(int)$sellerId,['unpublished'=>0,'published'=>1,'new'=>2][$status],effective_admin_role(),(int)$_SESSION['admin_id']);
      if (($_SERVER['HTTP_X_REQUESTED_WITH']??'')==='XMLHttpRequest') {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok'=>true,'status'=>$status],JSON_UNESCAPED_UNICODE);
        exit;
      }
      flash('Publicatiestatus kunstenaar aangepast.');
      $returnStatus=in_array(($_GET['status']??''),['new','published','unpublished'],true)?(string)$_GET['status']:'';
      $returnSearch=mb_substr(trim((string)($_GET['q']??'')),0,100);
      $returnParams=['status'=>$returnStatus,'q'=>$returnSearch,'per_page'=>in_array((int)($_GET['per_page']??20),[20,50,100],true)?(int)$_GET['per_page']:20];
      foreach (['kunstenaar','particulier'] as $returnGroup) {
        $key='pagina_'.$returnGroup;
        $returnParams[$key]=max(1,(int)($_GET[$key]??1));
      }
      header('Location: '.url($screenUrl,$returnParams).'#seller-row-'.(int)$sellerId,true,303);
      exit;
    }
    seller_require_edit($pdo,(int)$sellerId,effective_admin_role(),(int)$_SESSION['admin_id']);
    seller_delete($pdo,(int)$sellerId,effective_admin_role(),(int)$_SESSION['admin_id']);
    flash('Verkoper verwijderd.');
    redirect($screenUrl);
  } catch (InvalidArgumentException $ex) {
    $error=$ex->getMessage();
  } catch (PDOException $ex) {
    error_log('[Verkopersbeheer] Wijziging mislukt.');
    $error='Wijziging mislukt. Controleer de database.';
  }
  if (($_POST['action']??'')==='artist-publication' && ($_SERVER['HTTP_X_REQUESTED_WITH']??'')==='XMLHttpRequest' && $error!=='') {
    http_response_code(422);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok'=>false,'error'=>$error],JSON_UNESCAPED_UNICODE);
    exit;
  }
}
try {
  $rows=seller_list($pdo);
  $portraitPaths=$pdo->query("SELECT seller_id,path FROM seller_images WHERE image_kind='portrait' AND slot=1")->fetchAll(PDO::FETCH_KEY_PAIR);

} catch (PDOException $e) {
  error_log('[Verkopersbeheer] Laden mislukt.');
  http_response_code(503);
  admin_top($screenTitle);
  echo '<div class="alert warning" role="alert">Verkopersbeheer is nog niet beschikbaar. Controleer of de afzonderlijke databasemigratie in de lokale testomgeving is toegepast.</div>';
  admin_bottom();
  exit;
}
admin_top($screenTitle);
?>
<?php
$filterStatus=in_array(($_GET['status']??''),['new','published','unpublished'],true)?(string)$_GET['status']:'';
$search=mb_substr(trim((string)($_GET['q']??'')),0,100);
$perPage=in_array((int)($_GET['per_page']??20),[20,50,100],true)?(int)($_GET['per_page']??20):20;
function seller_page_url(string $groupType,int $page): string {
  $params=array_intersect_key($_GET,array_flip(['q','status','per_page','pagina_kunstenaar','pagina_particulier']));
  $params['pagina_'.$groupType]=$page;
  return url($GLOBALS['sellerScreen']==='kunstenaar'?'admin/kunstenaars.php':'admin/verkopers.php',$params).'#seller-list-'.$groupType.'-heading';
}
$artistCount=count(array_filter($rows,static fn($row)=>$row['seller_type']==='kunstenaar'));
$privateCount=count($rows)-$artistCount;
$filteredRows=array_values(array_filter($rows,static function($row) use ($sellerScreen,$filterStatus,$search):bool {
  if ($row['seller_type']!==$sellerScreen) return false;
  if ($filterStatus!=='' && $row['seller_type']==='kunstenaar'
      && (int)$row['artist_published']!==['unpublished'=>0,'published'=>1,'new'=>2][$filterStatus]) return false;
  return $search==='' || mb_stripos((string)$row['name'],$search)!==false
    || mb_stripos((string)$row['artist_name'],$search)!==false;
}));
?>
<div class="dashboard-modern seller-manager">
  <p class="dashboard-subtitle"><?=$sellerScreen==='kunstenaar'?'Beheer kunstenaars, portretten en publicatiestatus. Contact- en bankgegevens blijven privé.':'Beheer particuliere verkopers en hun contact- en bankgegevens op één afgeschermde plaats.'?></p>
  <?php if ($error): ?><div class="alert error" role="alert"><?=e($error)?></div><?php endif; ?>
  <section class="dashboard-panel" aria-labelledby="seller-filter-heading">
    <div class="dashboard-panel-heading">
      <div><h2 id="seller-filter-heading"><?=e($screenTitle)?></h2><p>Zoek binnen <?=e($sellerScreen==='kunstenaar'?'kunstenaars':'particuliere verkopers')?>.</p></div>
      <a class="primary" href="<?=e(url($editorUrl))?>">+ Nieuwe <?=e($screenSingular)?></a>
    </div>
    <form class="dashboard-filters seller-filters <?=$sellerScreen==='kunstenaar'?'':'seller-filters-private'?>" method="get" role="search">
      <label class="dashboard-search"><span class="sr-only">Zoek verkoper of kunstenaarsnaam</span><input type="search" name="q" placeholder="Zoek naam of kunstenaarsnaam…" value="<?=e($search)?>"></label>
      <?php if($sellerScreen==='kunstenaar'): ?><label><span class="sr-only">Publicatiestatus kunstenaars</span><select name="status" aria-label="Publicatiestatus kunstenaars"><option value="">Alle kunstenaarsstatussen</option><option value="new" <?=$filterStatus==='new'?'selected':''?>>Nieuw</option><option value="published" <?=$filterStatus==='published'?'selected':''?>>Gepubliceerd</option><option value="unpublished" <?=$filterStatus==='unpublished'?'selected':''?>>Niet gepubliceerd</option></select></label><?php endif; ?>
      <label><span class="sr-only">Aantal verkopers per pagina</span><select name="per_page" aria-label="Verkopers per pagina"><?php foreach([20,50,100] as $limit): ?><option value="<?=$limit?>" <?=$perPage===$limit?'selected':''?>><?=$limit?> per pagina</option><?php endforeach; ?></select></label>
      <button class="primary" type="submit">Zoeken</button>
    </form>
  </section>
  <?php foreach ([$sellerScreen=>($sellerScreen==='kunstenaar'?'Kunstenaars':'Particuliere verkopers')] as $groupType=>$groupLabel): ?>
    <?php
      $allGroupRows=array_values(array_filter($filteredRows,static fn($row)=>$row['seller_type']===$groupType));
      $groupTotal=count($allGroupRows);
      $groupPages=max(1,(int)ceil($groupTotal/$perPage));
      $groupPage=max(1,min($groupPages,(int)($_GET['pagina_'.$groupType]??1)));
      $groupRows=array_slice($allGroupRows,($groupPage-1)*$perPage,$perPage);
    ?>
  <section class="dashboard-panel" aria-labelledby="seller-list-<?=e($groupType)?>-heading">
    <div class="dashboard-panel-heading">
      <div><h2 id="seller-list-<?=e($groupType)?>-heading"><?=e($groupLabel)?></h2><p><?=$groupTotal?> resultaten</p></div>
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
            <tr id="seller-row-<?=(int)$r['id']?>">
              <?php $canEditSeller=seller_can_edit(effective_admin_role(),(int)$_SESSION['admin_id'],$r); ?>
              <?php if ($groupType==='kunstenaar'): ?>
                <?php
                  $portrait=(string)($portraitPaths[(int)$r['id']]??'');
                  $safePortrait=seller_artist_has_portrait_path($portrait);
                ?>
                <td class="seller-portrait-cell">
                  <?php if ($safePortrait): ?>
                    <img class="seller-portrait-thumb" src="<?=e(url($portrait))?>" alt="Portret van <?=e((string)$r['artist_name'])?>" loading="lazy" decoding="async" width="48" height="48">
                  <?php else: ?>
                    <span class="seller-portrait-placeholder" role="img" aria-label="Geen portret beschikbaar">👤</span>
                  <?php endif; ?>
                </td>
                <td class="dashboard-title"><?php if($canEditSeller): ?><a href="<?=e(url($editorUrl,['id'=>(int)$r['id']]))?>"><?=e((string)$r['artist_name'])?></a><?php else: ?><?=e((string)$r['artist_name'])?><?php endif; ?></td>
                <td><?php $artistStatus=(int)$r['artist_published']; ?><span class="seller-publication-status <?=($artistStatus===1?'is-published':($artistStatus===2?'is-new':'is-unpublished'))?>"><?=($artistStatus===1?'Gepubliceerd':($artistStatus===2?'Nieuw':'Niet gepubliceerd'))?></span></td>
              <?php else: ?>
                <td class="dashboard-title"><?php if($canEditSeller): ?><a href="<?=e(url('admin/verkoper.php',['id'=>(int)$r['id']]))?>"><?=e((string)$r['name'])?></a><?php else: ?><?=e((string)$r['name'])?><?php endif; ?></td>
              <?php endif; ?>
              <td><span class="seller-lot-count"><?=(int)$r['lot_count']?></span></td>
              <td><div class="seller-row-actions">
                <?php if($r['seller_type']==='kunstenaar' && seller_can_publish_artist(effective_admin_role())): ?>
                  <form method="post" class="seller-status-form" action="<?=e(url($screenUrl,array_merge(['status'=>$filterStatus,'q'=>$search,'per_page'=>$perPage],array_intersect_key($_GET,array_flip(['pagina_kunstenaar','pagina_particulier'])))))?>">
                    <?=csrf_field()?>
                    <input type="hidden" name="action" value="artist-publication">
                    <input type="hidden" name="seller_id" value="<?=(int)$r['id']?>">
                    <label class="sr-only" for="artist-status-<?=(int)$r['id']?>">Status van <?=e((string)$r['artist_name'])?></label>
                    <select id="artist-status-<?=(int)$r['id']?>" name="artist_status" onchange="this.form.requestSubmit()" aria-label="Publicatiestatus van <?=e((string)$r['artist_name'])?>">
                      <?php if($artistStatus===1 && !$safePortrait): ?>
                        <option value="" selected disabled>Gepubliceerd (portret ontbreekt)</option>
                      <?php endif; ?>
                      <option value="new" <?=$artistStatus===2?'selected':''?>>Nieuw</option>
                      <?php if($safePortrait): ?>
                        <option value="published" <?=$artistStatus===1?'selected':''?>>Gepubliceerd</option>
                      <?php endif; ?>
                      <option value="unpublished" <?=$artistStatus===0?'selected':''?>>Niet gepubliceerd</option>
                    </select>
                  </form>
                <?php endif; ?>
                <?php if($canEditSeller): ?><a class="dashboard-edit" href="<?=e(url($editorUrl,['id'=>(int)$r['id']]))?>">✎ Bewerken</a><?php else: ?><span class="seller-readonly" title="Enkel de maker, veilingmeester of administrator mag dit beheren">Alleen bekijken</span><?php endif; ?>
                <?php if($canEditSeller): ?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="seller_id" value="<?=(int)$r['id']?>"><button class="secondary seller-delete" type="submit" <?=(int)$r['lot_count']>0?'disabled title="Verkoper is gekoppeld aan een kavel"':''?>>Verwijderen</button></form><?php endif; ?>
              </div></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$groupRows): ?><tr><td colspan="<?=$groupType==='kunstenaar'?5:3?>">Geen <?=e(mb_strtolower($groupLabel))?> gevonden. <a href="<?=e(url($screenUrl))?>">Wis filters</a>.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <nav class="dashboard-pagination" aria-label="Paginering <?=e(mb_strtolower($groupLabel))?>">
      <span><?=($groupTotal ? (($groupPage-1)*$perPage+1) : 0)?>–<?=min($groupTotal,$groupPage*$perPage)?> van <?=$groupTotal?></span>
      <?php if($groupPage>1): ?><a href="<?=e(seller_page_url($groupType,$groupPage-1))?>">← Vorige</a><?php endif; ?>
      <span>Pagina <?=$groupPage?> / <?=$groupPages?></span>
      <?php if($groupPage<$groupPages): ?><a href="<?=e(seller_page_url($groupType,$groupPage+1))?>">Volgende →</a><?php endif; ?>
    </nav>
  </section>
  <?php endforeach; ?>

</div>
<script>
(() => {
  const statusLabels={new:'Nieuw',published:'Gepubliceerd',unpublished:'Niet gepubliceerd'};
  const statusClasses={new:'is-new',published:'is-published',unpublished:'is-unpublished'};
  document.querySelectorAll('form.seller-status-form').forEach(form => {
    const select=form.querySelector('select[name="artist_status"]');
    if (!select) return;
    select.dataset.savedValue=select.value;
    const notice=document.createElement('span');
    notice.className='sr-only';
    notice.setAttribute('role','status');
    notice.setAttribute('aria-live','polite');
    form.appendChild(notice);
    form.addEventListener('submit',async event => {
      event.preventDefault();
      if (select.disabled || select.value===select.dataset.savedValue) return;
      const previous=select.dataset.savedValue;
      const payload=new FormData(form);
      select.disabled=true;
      notice.textContent='Publicatiestatus opslaan…';
      try {
        const response=await fetch(form.getAttribute('action'),{
          method:'POST',
          body:payload,
          headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'},
          credentials:'same-origin'
        });
        const contentType=response.headers.get('content-type') || '';
        if (response.redirected || !contentType.toLowerCase().includes('application/json')) {
          throw new Error('De server gaf geen geldig antwoord. Controleer of je nog bent aangemeld in de lokale administratie en vernieuw de pagina.');
        }
        const data=await response.json();
        if (!response.ok || !data.ok || data.status!==select.value) {
          throw new Error(data.error || 'Status kon niet worden opgeslagen.');
        }
        select.dataset.savedValue=select.value;
        const row=form.closest('tr');
        const badge=row?.querySelector('.seller-publication-status');
        if (badge) {
          badge.textContent=statusLabels[data.status];
          badge.classList.remove('is-new','is-published','is-unpublished');
          badge.classList.add(statusClasses[data.status]);
        }
        notice.textContent='Publicatiestatus opgeslagen.';
      } catch (error) {
        select.value=previous;
        notice.textContent='Opslaan mislukt.';
        window.alert(error instanceof Error ? error.message : 'Status kon niet worden opgeslagen.');
      } finally {
        select.disabled=false;
      }
    });
  });
})();
</script>
<?php admin_bottom(); ?>
