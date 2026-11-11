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
<div class="seller-manager">
  <p>Beheer kunstenaars en particulieren. Contactgegevens en IBAN blijven uitsluitend beschikbaar binnen het afgeschermde beheer.</p>
  <?php if ($error): ?><div class="alert error" role="alert"><?=e($error)?></div><?php endif; ?>
  <div class="seller-manager-grid">
    <section class="lot-editor-card">
      <h2><?=$editId!==null?'Verkoper bewerken':'Verkoper toevoegen'?></h2>
      <form method="post" class="seller-manager-form">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="seller_id" value="<?=$editId!==null?(int)$editId:''?>">
        <label>Type verkoper <select name="seller_type" id="seller-type" required>
          <option value="particulier" <?=$values['seller_type']==='particulier'?'selected':''?>>Particulier</option>
          <option value="kunstenaar" <?=$values['seller_type']==='kunstenaar'?'selected':''?>>Kunstenaar</option>
        </select></label>
        <label>Naam * <input name="name" maxlength="150" required value="<?=e((string)$values['name'])?>"></label>
        <label>E-mail <input name="email" type="email" maxlength="254" value="<?=e((string)$values['email'])?>"></label>
        <label>Straat <input name="street" maxlength="150" value="<?=e((string)$values['street'])?>"></label>
        <label>Huisnummer <input name="house_number" maxlength="20" value="<?=e((string)$values['house_number'])?>"></label>
        <label>Postcode <input name="postal_code" maxlength="20" value="<?=e((string)$values['postal_code'])?>"></label>
        <label>Gemeente <input name="municipality" maxlength="120" value="<?=e((string)$values['municipality'])?>"></label>
        <label>Naam bankrekeninghouder <input name="bank_account_name" maxlength="100" autocomplete="off" value="<?=e((string)$values['bank_account_name'])?>"></label>
        <label>IBAN <input name="bank_account_iban" maxlength="42" autocomplete="off" spellcheck="false" value="<?=e((string)$values['bank_account_iban'])?>" placeholder="BE68 5390 0754 7034"></label>
        <div class="seller-artist-fields" id="seller-artist-fields" <?=$values['seller_type']==='kunstenaar'?'':'hidden'?>>
          <label>Kunstenaarsnaam <input name="artist_name" maxlength="50" value="<?=e((string)$values['artist_name'])?>"></label>
          <label>Website kunstenaar <input name="artist_url" maxlength="2048" inputmode="url" value="<?=e((string)$values['artist_url'])?>" placeholder="https://..."></label>
        </div>
        <div class="seller-actions">
          <button type="submit" class="primary">Verkoper opslaan</button>
          <?php if ($editId!==null): ?><a class="secondary" href="<?=e(url('admin/verkopers.php'))?>">Nieuwe verkoper</a><?php endif; ?>
        </div>
      </form>
    </section>
    <section class="lot-editor-card">
      <h2>Bestaande verkopers (<?=count($rows)?>)</h2>
      <div class="seller-list">
      <?php foreach($rows as $r): ?>
        <div class="seller-item">
          <div><strong><?=e($r['name'])?></strong><span><?=e($r['seller_type']==='kunstenaar'?'Kunstenaar':'Particulier')?> · <?=(int)$r['lot_count']?> kavel(s)</span></div>
          <div class="seller-actions">
            <a class="secondary" href="<?=e(url('admin/verkopers.php',['id'=>(int)$r['id']]))?>">Bewerken</a>
            <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="seller_id" value="<?=(int)$r['id']?>"><button type="submit" class="secondary" <?=(int)$r['lot_count']>0?'disabled title="Verkoper is gekoppeld aan een kavel"':''?>>Verwijderen</button></form>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (!$rows): ?><p>Er zijn nog geen verkopers.</p><?php endif; ?>
      </div>
    </section>
  </div>
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
