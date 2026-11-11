<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/private/bootstrap.php';
require_once dirname(__DIR__,2).'/private/lot_sellers.php';
require_once dirname(__DIR__,2).'/private/catalog_mutex.php';
require_administrator();
$pdo=db();
$error='';
$defaults=['seller_type'=>'particulier','name'=>'','email'=>'','street'=>'','house_number'=>'','postal_code'=>'','municipality'=>'','bank_account_name'=>'','bank_account_iban'=>'','artist_name'=>'','artist_url'=>''];
$editId=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$editId=$editId===false ? null : $editId;
$values=$defaults;
if (is_post()) {
  check_csrf();
  $action=$_POST['action']??'';
  $postedId=filter_var($_POST['seller_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
  $postedId=$postedId===false?null:$postedId;
  try {
    catalog_lock_for_request($pdo);
    if ($action==='save') {
      $values=array_merge($defaults,array_intersect_key($_POST,$defaults));
      $savedId=seller_save($pdo,$postedId,$_POST);
      flash('Verkoper opgeslagen.');
      redirect('admin/verkopers.php',['id'=>$savedId]);
    } elseif ($action==='delete' && $postedId!==null) {
      seller_delete($pdo,$postedId);
      flash('Verkoper verwijderd.');
      redirect('admin/verkopers.php');
    } else {
      throw new InvalidArgumentException('Ongeldige actie.');
    }
  } catch (InvalidArgumentException $e) {
    $error=$e->getMessage();
    $editId=$postedId;
  } catch (PDOException $e) {
    error_log('[Verkopersbeheer] Databasefout.');
    $error='Bewaren mislukt. Controleer de gegevens en de database.';
    $editId=$postedId;
  }
}
try {
  $rows=seller_list($pdo);
  if (!is_post() && $editId!==null) {
    $found=array_values(array_filter($rows,static fn($r)=>(int)$r['id']===$editId));
    if (!$found) { http_response_code(404); throw new InvalidArgumentException('Verkoper niet gevonden.'); }
    $values=array_merge($defaults,$found[0]);
  }
} catch (PDOException $e) {
  error_log('[Verkopersbeheer] Laden mislukt.');
  http_response_code(503);
  admin_top('Verkopers beheren');
  echo '<div class="alert warning" role="alert">Verkopersbeheer is nog niet beschikbaar. Controleer of de afzonderlijke databasemigratie in de lokale testomgeving is toegepast.</div>';
  admin_bottom();
  exit;
} catch (InvalidArgumentException $e) {
  $error=$e->getMessage();
  $rows=[];
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
      <a class="primary" href="<?=e(url('admin/verkopers.php',['new'=>1]))?>#seller-form">+ Nieuwe verkoper</a>
    </div>
    <form class="dashboard-filters seller-filters" method="get" role="search">
      <label class="dashboard-search"><span class="sr-only">Zoek verkoper of kunstenaarsnaam</span><input type="search" name="q" placeholder="Zoek naam of kunstenaarsnaam…" value="<?=e($search)?>"></label>
      <label><span class="sr-only">Type verkoper</span><select name="type"><option value="">Alle verkopers</option><option value="kunstenaar" <?=$filterType==='kunstenaar'?'selected':''?>>Kunstenaars</option><option value="particulier" <?=$filterType==='particulier'?'selected':''?>>Particulieren</option></select></label>
      <button class="primary" type="submit">Zoeken</button>
    </form>
    <div class="dashboard-table-scroll">
      <table class="dashboard-table seller-table">
        <thead><tr><th scope="col">Naam</th><th scope="col">Type</th><th scope="col">Kunstenaarsnaam</th><th scope="col">Kavels</th><th scope="col">Acties</th></tr></thead>
        <tbody>
          <?php foreach($filteredRows as $r): ?>
            <tr>
              <td class="dashboard-title"><a href="<?=e(url('admin/verkopers.php',['id'=>(int)$r['id']]))?>#seller-form"><?=e($r['name'])?></a></td>
              <td><span class="dashboard-category"><?=e($r['seller_type']==='kunstenaar'?'Kunstenaar':'Particulier')?></span></td>
              <td><?=e($r['seller_type']==='kunstenaar'?(string)$r['artist_name']:'—')?></td>
              <td><span class="seller-lot-count"><?=(int)$r['lot_count']?></span></td>
              <td><div class="seller-row-actions">
                <a class="dashboard-edit" href="<?=e(url('admin/verkopers.php',['id'=>(int)$r['id']]))?>#seller-form">✎ Bewerken</a>
                <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="seller_id" value="<?=(int)$r['id']?>"><button class="secondary seller-delete" type="submit" <?=(int)$r['lot_count']>0?'disabled title="Verkoper is gekoppeld aan een kavel"':''?>>Verwijderen</button></form>
              </div></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$filteredRows): ?><tr><td colspan="5">Geen verkopers gevonden. <a href="<?=e(url('admin/verkopers.php'))?>">Wis filters</a>.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
  <section class="lot-editor-card seller-editor-card" id="seller-form" aria-labelledby="seller-editor-heading">
    <div class="lot-editor-card-header"><span class="lot-editor-step" aria-hidden="true">01</span><div><h2 id="seller-editor-heading"><?=$editId!==null?'Verkoper bewerken':'Nieuwe verkoper'?></h2><p>Vul de gegevens van een kunstenaar of particulier in.</p></div></div>
    <form method="post" class="seller-manager-form">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="seller_id" value="<?=$editId!==null?(int)$editId:''?>">
      <div class="seller-fields-section">
        <h3>Algemene gegevens</h3>
        <div class="seller-fields-grid">
          <label>Type verkoper<select name="seller_type" id="seller-type" required><option value="particulier" <?=$values['seller_type']==='particulier'?'selected':''?>>Particulier</option><option value="kunstenaar" <?=$values['seller_type']==='kunstenaar'?'selected':''?>>Kunstenaar</option></select></label>
          <label>Naam <span class="lot-required">*</span><input name="name" maxlength="150" required value="<?=e((string)$values['name'])?>"></label>
          <label class="seller-field-wide">E-mail<input name="email" type="email" maxlength="254" value="<?=e((string)$values['email'])?>"></label>
          <label>Straat<input name="street" maxlength="150" value="<?=e((string)$values['street'])?>"></label>
          <label>Huisnummer<input name="house_number" maxlength="20" value="<?=e((string)$values['house_number'])?>"></label>
          <label>Postcode<input name="postal_code" maxlength="20" value="<?=e((string)$values['postal_code'])?>"></label>
          <label>Gemeente<input name="municipality" maxlength="120" value="<?=e((string)$values['municipality'])?>"></label>
        </div>
      </div>
      <div class="seller-fields-section">
        <h3>Bankgegevens <span class="seller-private-label">Privé</span></h3>
        <div class="seller-fields-grid">
          <label>Naam bankrekeningnummer<input name="bank_account_name" maxlength="100" autocomplete="off" value="<?=e((string)$values['bank_account_name'])?>"></label>
          <label>IBAN<input name="bank_account_iban" maxlength="42" autocomplete="off" spellcheck="false" value="<?=e((string)$values['bank_account_iban'])?>" placeholder="BE68 5390 0754 7034"></label>
        </div>
        <p class="lot-editor-help">Het IBAN wordt bij het opslaan gecontroleerd en verschijnt niet in de publieke catalogus.</p>
      </div>
      <div class="seller-fields-section seller-artist-fields" id="seller-artist-fields" <?=$values['seller_type']==='kunstenaar'?'':'hidden'?>>
        <h3>Kunstenaarsgegevens</h3>
        <div class="seller-fields-grid">
          <label>Kunstenaarsnaam<input name="artist_name" maxlength="50" value="<?=e((string)$values['artist_name'])?>"></label>
          <label>Website kunstenaar<input name="artist_url" maxlength="2048" inputmode="url" value="<?=e((string)$values['artist_url'])?>" placeholder="https://..."></label>
        </div>
      </div>
      <div class="seller-form-actions">
        <a class="secondary" href="<?=e(url('admin/verkopers.php'))?>">Annuleren</a>
        <button class="primary" type="submit">Verkoper opslaan</button>
      </div>
    </form>
  </section>
</div>
<script>
(function () {
  const type=document.getElementById('seller-type');
  const fields=document.getElementById('seller-artist-fields');
  if (!type || !fields) return;
  function sync() { fields.hidden=type.value!=='kunstenaar'; }
  type.addEventListener('change', sync);
  sync();
}());
</script>
<?php admin_bottom(); ?>
