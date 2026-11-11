<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/private/bootstrap.php';
require_once dirname(__DIR__,2).'/private/lot_sellers.php';
require_once dirname(__DIR__,2).'/private/seller_images.php';
require_once dirname(__DIR__,2).'/private/catalog_mutex.php';
require_auth();
$pdo=db();
$error='';
$fromLot=($_GET['from_lot']??'')==='1';
$returnLotId=filter_var($_GET['lot_id']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$returnLotId=$returnLotId===false?0:(int)$returnLotId;
$returnLotUrl=url('admin/kavel.php',$returnLotId>0?['id'=>$returnLotId]:[]);
$defaults=['seller_type'=>'particulier','name'=>'','email'=>'','mobile_number'=>'','street'=>'','house_number'=>'','postal_code'=>'','municipality'=>'','bank_account_name'=>'','bank_account_iban'=>'','artist_name'=>'','artist_url'=>'','artist_intro'=>''];
$editId=null;
if (isset($_GET['id'])) {
  $editId=filter_var($_GET['id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
  if ($editId===false) { http_response_code(404); exit('Verkoper niet gevonden.'); }
}
$values=$defaults;
if (is_post()) {
  check_csrf();
  $action=(string)($_POST['action']??'');
  if (!in_array($action,['save','upload-photo','delete-photo'],true)) { http_response_code(400); exit('Ongeldige actie.'); }
  $rawId=$_POST['seller_id']??'';
  $postId=$rawId==='' ? null : filter_var($rawId,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
  if ($postId===false || $postId!==$editId) { http_response_code(400); exit('Ongeldige verkoperselectie.'); }
  $values=array_merge($defaults,array_intersect_key($_POST,$defaults));
  try {
    catalog_lock_for_request($pdo);
    if ($action==='upload-photo' || $action==='delete-photo') {
      if ($editId===null) throw new InvalidArgumentException('Bewaar eerst de verkoper.');
      $kind=(string)($_POST['image_kind']??'');
      $slot=filter_var($_POST['slot']??null,FILTER_VALIDATE_INT);
      if ($slot===false || $slot===null) throw new InvalidArgumentException('Ongeldige fotopositie.');
      if ($action==='upload-photo') seller_image_upload($pdo,$editId,$kind,(int)$slot,$_FILES['image']??[]);
      else seller_image_delete($pdo,$editId,$kind,(int)$slot);
      flash($action==='upload-photo'?'Foto bewaard.':'Foto verwijderd.');
      redirect('admin/verkoper.php',['id'=>$editId]+($fromLot?['from_lot'=>1,'lot_id'=>$returnLotId]:[]));
    }
    $savedId=seller_save($pdo,$editId,$_POST,(int)$_SESSION['admin_id']);
    if ($values['seller_type']!=='kunstenaar') seller_image_clear($pdo,$savedId);
    // Accept photo uploads together with the seller form, including creation.
    $failed=[];
    if ($values['seller_type']==='kunstenaar') {
      foreach (['work'=>3,'portrait'=>1] as $kind=>$maxSlot) {
        for ($slot=1;$slot<=$maxSlot;$slot++) {
          $key='photo_'.$kind.'_'.$slot;
          $file=$_FILES[$key]??null;
          if (!is_array($file) || (int)($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE) continue;
          try { seller_image_upload($pdo,$savedId,$kind,$slot,$file); }
          catch (Throwable $photoError) { $failed[]=$kind.' '.$slot.': '.$photoError->getMessage(); }
        }
      }
    }
    if ($failed) {
      flash('Verkoper bewaard, maar niet alle foto’s lukten: '.implode('; ',$failed),'warning');
      redirect('admin/verkoper.php',['id'=>$savedId]+($fromLot?['from_lot'=>1,'lot_id'=>$returnLotId]:[]));
    }
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
  } catch (RuntimeException $ex) {
    $error=$ex->getMessage();
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
// On a failed photo action, redisplay the stored seller rather than an empty form.
if (is_post() && $editId!==null && ($_POST['action']??'')!=='save' && $error!=='') {
  $st=$pdo->prepare('SELECT * FROM lot_sellers WHERE id=?');
  $st->execute([$editId]);
  $saved=$st->fetch(PDO::FETCH_ASSOC);
  if ($saved) $values=array_merge($defaults,$saved);
}
$audit=null;
if ($editId!==null) {
  $auditStmt=$pdo->prepare("SELECT s.created_at,s.updated_at,COALESCE(NULLIF(creator.name,''),creator.email) AS creator_name,COALESCE(NULLIF(editor.name,''),editor.email) AS editor_name FROM lot_sellers s LEFT JOIN admins creator ON creator.id=s.created_by_admin_id LEFT JOIN admins editor ON editor.id=s.updated_by_admin_id WHERE s.id=?");
  $auditStmt->execute([$editId]);
  $audit=$auditStmt->fetch(PDO::FETCH_ASSOC);
}
$photos=$editId!==null?seller_image_rows($pdo,$editId):[];
$photoByKey=[];
foreach($photos as $photo)$photoByKey[$photo['image_kind'].'_'.$photo['slot']]=$photo;
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
    <form method="post" enctype="multipart/form-data" class="seller-manager-form">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="seller_id" value="<?=$editId!==null?(int)$editId:''?>">
      <div class="seller-fields-section">
        <h3>Algemene gegevens</h3>
        <div class="seller-fields-grid">
          <label class="seller-field-wide">Type verkoper<select name="seller_type" id="seller-type" required><option value="particulier" <?=$values['seller_type']==='particulier'?'selected':''?>>Particulier</option><option value="kunstenaar" <?=$values['seller_type']==='kunstenaar'?'selected':''?>>Kunstenaar</option></select></label>
          <label class="seller-field-wide">Naam <span class="lot-required">*</span><input name="name" maxlength="150" required value="<?=e((string)$values['name'])?>"></label>
          <label class="seller-field-wide">E-mail <span class="lot-required">*</span><input required name="email" type="email" maxlength="254" value="<?=e((string)$values['email'])?>"></label>
          <label class="seller-field-wide">Mobiel nummer <span class="seller-optional">(optioneel)</span><input type="tel" name="mobile_number" maxlength="30" autocomplete="tel" inputmode="tel" value="<?=e((string)$values['mobile_number'])?>" placeholder="0470 12 34 56 of +32 470 12 34 56" aria-describedby="seller-mobile-help"></label>
          <p id="seller-mobile-help" class="lot-editor-help seller-field-wide">Belgisch gsm-nummer of internationaal nummer met landcode. Bij het opslaan wordt het formaat gecontroleerd.</p>
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
        <div class="seller-introduction">
          <label for="artist-intro">Korte voorstelling van de kunstenaar (optioneel)</label>
          <textarea id="artist-intro" name="artist_intro" rows="4" maxlength="500" placeholder="Vertel kort wie de kunstenaar is..."><?=e((string)$values['artist_intro'])?></textarea>
          <p class="lot-editor-help">Maximaal 500 tekens.</p>
        </div>
        <div class="seller-gallery">
          <h4>Foto’s van werken <span class="seller-optional">(maximaal 3)</span></h4>
          <div class="seller-photo-grid">
            <?php for($slot=1;$slot<=3;$slot++): $photo=$photoByKey['work_'.$slot]??null; ?>
              <div class="seller-photo-slot">
                <?php if($photo): ?><img src="<?=e(url($photo['path']))?>" alt="Werk van kunstenaar, foto <?=$slot?>" loading="lazy"><?php endif; ?>
                <label>Werk <?=$slot?><input type="file" name="photo_work_<?=$slot?>" accept="image/jpeg,image/png,image/webp"></label>
              </div>
            <?php endfor; ?>
          </div>
          <h4>Portretfoto van de kunstenaar <span class="seller-optional">(optioneel, maximaal 1)</span></h4>
          <?php $portrait=$photoByKey['portrait_1']??null; ?>
          <div class="seller-photo-grid">
            <div class="seller-photo-slot">
              <?php if($portrait): ?><img src="<?=e(url($portrait['path']))?>" alt="Portretfoto van kunstenaar" loading="lazy"><?php endif; ?>
              <label>Portret<input type="file" name="photo_portrait_1" accept="image/jpeg,image/png,image/webp"></label>
            </div>
          </div>
          <p class="lot-editor-help">JPG, PNG of WebP, maximaal 16 MB en 48 megapixel per foto. Een nieuwe foto vervangt de foto in dezelfde positie.</p>
        </div>
      </div>
      <div class="seller-form-actions">
        <a class="secondary" href="<?=e($fromLot?$returnLotUrl:url('admin/verkopers.php'))?>" <?=$fromLot?'data-return-to-lot="1"':''?>>Annuleren</a>
        <button class="primary" type="submit">Verkoper opslaan</button>
      </div>
    </form>
    <?php if($audit): ?><div class="seller-audit-summary"><strong>Aangemaakt door:</strong> <?=e($audit['creator_name'] ?: 'Onbekend')?> · <?=e($audit['created_at'] ? gmdate('d/m/Y H:i',strtotime($audit['created_at'])).' UTC' : 'Niet geregistreerd')?><div><strong>Laatste wijziging:</strong> <?=e($audit['editor_name'] ?: 'Onbekend')?> · <?=e($audit['updated_at'] ? gmdate('d/m/Y H:i',strtotime($audit['updated_at'])).' UTC' : 'Niet geregistreerd')?></div></div><?php endif; ?>
    <?php if($editId!==null && $values['seller_type']==='kunstenaar'): ?>
    <div class="seller-photo-delete-grid">
      <?php foreach($photos as $photo): ?>
      <form method="post" class="seller-photo-delete">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="delete-photo">
        <input type="hidden" name="seller_id" value="<?=$editId?>">
        <input type="hidden" name="image_kind" value="<?=e($photo['image_kind'])?>">
        <input type="hidden" name="slot" value="<?=(int)$photo['slot']?>">
        <button class="secondary" type="submit">Verwijder <?=e($photo['image_kind']==='portrait'?'portret':'werkfoto '.(int)$photo['slot'])?></button>
      </form>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
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
    fields.querySelectorAll('input,textarea').forEach(input => {
      input.required=artist && (input.name==='artist_name' || input.name==='artist_url');
      input.disabled=!artist;
    });
  }
  type.addEventListener('change', sync);
  sync();
}());
</script>
<?php admin_bottom(); ?>
