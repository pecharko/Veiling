<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/private/bootstrap.php';
require_once dirname(__DIR__,2).'/private/lot_sellers.php';
require_once dirname(__DIR__,2).'/private/catalog_mutex.php';
require_administrator();
$pdo=db();
$error='';
$fromLot=($_GET['from_lot']??'')==='1';
$returnLotId=filter_var($_GET['lot_id']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$returnLotId=$returnLotId===false?0:(int)$returnLotId;
$returnLotUrl=url('admin/kavel.php',$returnLotId>0?['id'=>$returnLotId]:[]);
$defaults=['seller_type'=>'particulier','name'=>'','email'=>'','street'=>'','house_number'=>'','postal_code'=>'','municipality'=>'','bank_account_name'=>'','bank_account_iban'=>'','artist_name'=>'','artist_url'=>''];
$editId=null;
if (isset($_GET['id'])) {
  $editId=filter_var($_GET['id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
  if ($editId===false) { http_response_code(404); exit('Verkoper niet gevonden.'); }
}
$values=$defaults;
if (is_post()) {
  check_csrf();
  if (($_POST['action']??'')!=='save') { http_response_code(400); exit('Ongeldige actie.'); }
  $rawId=$_POST['seller_id']??'';
  $postId=$rawId==='' ? null : filter_var($rawId,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
  if ($postId===false || $postId!==$editId) { http_response_code(400); exit('Ongeldige verkoperselectie.'); }
  $values=array_merge($defaults,array_intersect_key($_POST,$defaults));
  try {
    catalog_lock_for_request($pdo);
    $savedId=seller_save($pdo,$editId,$_POST);
    if ($fromLot) {
      redirect('admin/verkoper.php', ['from_lot'=>1,'lot_id'=>$returnLotId,'completed'=>1,'seller_id'=>$savedId]);
    }
    flash('Verkoper opgeslagen.');
    redirect('admin/verkopers.php');
  } catch (InvalidArgumentException $ex) {
    $error=$ex->getMessage();
  } catch (PDOException $ex) {
    error_log('[Verkoper opslaan] Databasefout.');
    $error='Bewaren mislukt. Controleer de gegevens en de database.';
  }
} elseif ($editId!==null) {
  try {
    $st=$pdo->prepare('SELECT * FROM lot_sellers WHERE id=?');
    $st->execute([$editId]);
    $saved=$st->fetch(PDO::FETCH_ASSOC);
    if (!$saved) { http_response_code(404); exit('Verkoper niet gevonden.'); }
    $values=array_merge($defaults,$saved);
  } catch (PDOException $ex) {
    error_log('[Verkoper ophalen] Databasefout.');
    http_response_code(503);
    exit('Verkopersbeheer is momenteel niet beschikbaar.');
  }
}
if ($fromLot && ($_GET['completed']??'')==='1' && filter_var($_GET['seller_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])!==false) {
  $completedSellerId=(int)$_GET['seller_id'];
  admin_top('Verkoper opgeslagen');
  ?>
  <div class="seller-manager seller-editor-page">
    <div class="lot-editor-card seller-editor-card">
      <h2>Verkoper opgeslagen</h2>
      <p>Keer terug naar het kavelformulier om verder te werken. De nieuw aangemaakte verkoper wordt automatisch geselecteerd.</p>
      <a class="primary" href="<?=e($returnLotUrl)?>">Terug naar kavel</a>
    </div>
  </div>
  <script>
  (() => {
    if (window.opener && !window.opener.closed) {
      window.opener.postMessage({type:'lot-seller-created',sellerId:<?= $completedSellerId ?>},window.location.origin);
      window.opener.focus();
      window.close();
    }
  })();
  </script>
  <?php
  admin_bottom();
  exit;
}
admin_top($editId===null?'Nieuwe verkoper':'Verkoper bewerken');
?>
<div class="seller-manager seller-editor-page">
  <div class="seller-editor-toolbar">
    <div><a class="lot-editor-back" href="<?=e($fromLot?$returnLotUrl:url('admin/verkopers.php'))?>" <?=$fromLot?'data-return-to-lot="1"':''?>>← <?=$fromLot?'Terug naar kavel':'Terug naar verkopers'?></a>
      <p class="dashboard-subtitle">Beheer persoonsgegevens en bankgegevens op één afgeschermde plek.</p></div>
  </div>
  <?php if ($error): ?><div class="alert error" role="alert"><?=e($error)?></div><?php endif; ?>
  <section class="lot-editor-card seller-editor-card" id="seller-form" aria-labelledby="seller-editor-heading">
    <div class="lot-editor-card-header"><span class="lot-editor-step" aria-hidden="true">01</span><div><h2 id="seller-editor-heading"><?=$editId!==null?'Verkoper bewerken':'Nieuwe verkoper'?></h2><p>Alle algemene gegevens zijn verplicht. Bankgegevens zijn voorlopig optioneel. Voor kunstenaars gelden ook de extra kunstenaarsvelden.</p></div></div>
    <form method="post" class="seller-manager-form">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="seller_id" value="<?=$editId!==null?(int)$editId:''?>">
      <div class="seller-fields-section">
        <h3>Algemene gegevens</h3>
        <div class="seller-fields-grid">
          <label>Type verkoper<select name="seller_type" id="seller-type" required><option value="particulier" <?=$values['seller_type']==='particulier'?'selected':''?>>Particulier</option><option value="kunstenaar" <?=$values['seller_type']==='kunstenaar'?'selected':''?>>Kunstenaar</option></select></label>
          <label>Naam <span class="lot-required">*</span><input name="name" maxlength="150" required value="<?=e((string)$values['name'])?>"></label>
          <label class="seller-field-wide">E-mail <span class="lot-required">*</span><input required name="email" type="email" maxlength="254" value="<?=e((string)$values['email'])?>"></label>
          <label>Straat <span class="lot-required">*</span><input required name="street" maxlength="150" value="<?=e((string)$values['street'])?>"></label>
          <label>Huisnummer <span class="lot-required">*</span><input required name="house_number" maxlength="20" value="<?=e((string)$values['house_number'])?>"></label>
          <label>Postcode <span class="lot-required">*</span><input required name="postal_code" maxlength="20" value="<?=e((string)$values['postal_code'])?>"></label>
          <label>Gemeente <span class="lot-required">*</span><input required name="municipality" maxlength="120" value="<?=e((string)$values['municipality'])?>"></label>
        </div>
      </div>
      <div class="seller-fields-section">
        <h3>Bankgegevens <span class="seller-private-label">Privé</span></h3>
        <div class="seller-fields-grid">
          <label>Naam bankrekeningnummer <span class="seller-optional">(optioneel)</span><input name="bank_account_name" maxlength="100" autocomplete="off" value="<?=e((string)$values['bank_account_name'])?>"></label>
          <label>IBAN <span class="seller-optional">(optioneel)</span><input name="bank_account_iban" maxlength="42" autocomplete="off" spellcheck="false" value="<?=e((string)$values['bank_account_iban'])?>" placeholder="BE68 5390 0754 7034"></label>
        </div>
        <p class="lot-editor-help">Bankgegevens mogen leeg blijven. Een ingevuld IBAN wordt bij het opslaan gecontroleerd en verschijnt niet in de publieke catalogus.</p>
      </div>
      <div class="seller-fields-section seller-artist-fields" id="seller-artist-fields" <?=$values['seller_type']==='kunstenaar'?'':'hidden'?>>
        <h3>Kunstenaarsgegevens</h3>
        <div class="seller-fields-grid">
          <label>Kunstenaarsnaam <span class="lot-required">*</span><input required name="artist_name" maxlength="50" value="<?=e((string)$values['artist_name'])?>"></label>
          <label>Website kunstenaar <span class="lot-required">*</span><input required name="artist_url" maxlength="2048" inputmode="url" value="<?=e((string)$values['artist_url'])?>" placeholder="https://..."></label>
        </div>
      </div>
      <div class="seller-form-actions">
        <a class="secondary" href="<?=e($fromLot?$returnLotUrl:url('admin/verkopers.php'))?>" <?=$fromLot?'data-return-to-lot="1"':''?>>Annuleren</a>
        <button class="primary" type="submit">Verkoper opslaan</button>
      </div>
    </form>
  </section>
</div>
<script>
(function () {
  document.querySelectorAll('[data-return-to-lot]').forEach(link => {
    link.addEventListener('click', event => {
      if (window.opener && !window.opener.closed && window.opener.location.origin===window.location.origin) {
        event.preventDefault();
        window.opener.focus();
        window.close();
      }
    });
  });
  const type=document.getElementById('seller-type');
  const fields=document.getElementById('seller-artist-fields');
  if (!type || !fields) return;
  function sync() {
    const artist=type.value==='kunstenaar';
    fields.hidden=!artist;
    fields.querySelectorAll('input').forEach(input => {
      input.required=artist;
      input.disabled=!artist;
    });
  }
  type.addEventListener('change', sync);
  sync();
}());
</script>
<?php admin_bottom(); ?>
