<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/private/bootstrap.php';
require_once dirname(__DIR__,2).'/private/lot_sellers.php';
require_once dirname(__DIR__,2).'/private/seller_images.php';
require_once dirname(__DIR__,2).'/private/catalog_mutex.php';
require_auth();
$pdo=db();
$error='';
$actorId=(int)$_SESSION['admin_id'];
$actorRole=effective_admin_role();
$fromLot=($_GET['from_lot']??'')==='1';
$returnLotId=filter_var($_GET['lot_id']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$returnLotId=$returnLotId===false?0:(int)$returnLotId;
$returnLotUrl=url('admin/kavel.php',$returnLotId>0?['id'=>$returnLotId]:[]);
$defaults=['seller_type'=>'particulier','name'=>'','email'=>'','mobile_number'=>'','street'=>'','house_number'=>'','postal_code'=>'','municipality'=>'','bank_account_name'=>'','bank_account_iban'=>'','artist_name'=>'','artist_url'=>'','artist_intro'=>'','artist_slogan'=>''];
$editId=null;
if (isset($_GET['id'])) {
  $editId=filter_var($_GET['id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
  if ($editId===false) { http_response_code(404); exit('Verkoper niet gevonden.'); }
}
$values=$defaults;
// Check ownership on every edit view and before any write, including photo actions.
if ($editId!==null) seller_require_edit($pdo,$editId,$actorRole,$actorId);
$ajaxPhoto=is_post() && ($_SERVER['HTTP_X_REQUESTED_WITH']??'')==='XMLHttpRequest' && in_array(($_POST['action']??''),['upload-photo','delete-photo'],true);
$ajaxSave=is_post() && ($_SERVER['HTTP_X_REQUESTED_WITH']??'')==='XMLHttpRequest' && ($_POST['action']??'')==='save';
$ajaxReply=static function(int $code,array $body): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
};
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
      if ($action==='upload-photo') seller_image_upload($pdo,$editId,$kind,(int)$slot,$_FILES['image']??[],(int)$_SESSION['admin_id']);
      else seller_image_delete($pdo,$editId,$kind,(int)$slot,(int)$_SESSION['admin_id']);
      if ($ajaxPhoto && $action==='delete-photo') $ajaxReply(200,['ok'=>true,'slot'=>(int)$slot,'kind'=>$kind,'deleted'=>true]);
      if ($ajaxPhoto) {
        $rows=seller_image_rows($pdo,$editId);
        $uploaded=null;
        foreach ($rows as $row) if ($row['image_kind']===$kind && (int)$row['slot']===(int)$slot) $uploaded=$row;
        if (!$uploaded) throw new RuntimeException('Foto opgeslagen maar niet teruggevonden.');
        $ajaxReply(200,['ok'=>true,'image_url'=>url((string)$uploaded['path']),'image_id'=>(int)$uploaded['id'],'slot'=>(int)$slot,'kind'=>$kind]);
      }
      flash($action==='upload-photo'?'Foto bewaard.':'Foto verwijderd.');
      redirect('admin/verkoper.php',['id'=>$editId]+($fromLot?['from_lot'=>1,'lot_id'=>$returnLotId]:[]));
    }
    $savedId=seller_save($pdo,$editId,$_POST,$actorId,$actorRole);
    if ($values['seller_type']!=='kunstenaar') seller_image_clear($pdo,$savedId);
    // Accept photo uploads together with the seller form, including creation.
    $failed=[];
    if ($values['seller_type']==='kunstenaar') {
      foreach (['work'=>3,'portrait'=>1] as $kind=>$maxSlot) {
        for ($slot=1;$slot<=$maxSlot;$slot++) {
          $key='photo_'.$kind.'_'.$slot;
          $file=$_FILES[$key]??null;
          if (!is_array($file) || (int)($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE) continue;
          try { seller_image_upload($pdo,$savedId,$kind,$slot,$file,(int)$_SESSION['admin_id']); }
          catch (Throwable $photoError) { $failed[]=$kind.' '.$slot.': '.$photoError->getMessage(); }
        }
      }
    }
    if ($failed) {
      flash('Verkoper bewaard, maar niet alle foto’s lukten: '.implode('; ',$failed),'warning');
      if ($ajaxSave) $ajaxReply(200,['redirect'=>url('admin/verkoper.php',['id'=>$savedId]+($fromLot?['from_lot'=>1,'lot_id'=>$returnLotId]:[]))]);
      redirect('admin/verkoper.php',['id'=>$savedId]+($fromLot?['from_lot'=>1,'lot_id'=>$returnLotId]:[]));
    }
    if ($fromLot) {
      if ($ajaxSave) $ajaxReply(200,['redirect'=>url('admin/verkoper.php',['from_lot'=>1,'lot_id'=>$returnLotId,'completed'=>1,'seller_id'=>$savedId])]);
      redirect('admin/verkoper.php', ['from_lot'=>1,'lot_id'=>$returnLotId,'completed'=>1,'seller_id'=>$savedId]);
    }
    flash('Verkoper opgeslagen.');
    if ($ajaxSave) $ajaxReply(200,['redirect'=>url('admin/verkopers.php')]);
    redirect('admin/verkopers.php');
  } catch (InvalidArgumentException $ex) {
    $error=$ex->getMessage();
  } catch (PDOException $ex) {
    error_log('[Verkoper opslaan] Databasefout.');
    $error='Bewaren mislukt. Controleer de gegevens en de database.';
  } catch (RuntimeException $ex) {
    $error=$ex->getMessage();
  }
  if (($ajaxSave || $ajaxPhoto) && $error!=='') $ajaxReply(422,['ok'=>false,'error'=>$error]);
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
  $auditStmt=$pdo->prepare("SELECT s.created_at,s.updated_at,COALESCE(NULLIF(s.created_by_name,''),NULLIF(creator.name,''),creator.email) AS creator_name,COALESCE(NULLIF(s.updated_by_name,''),NULLIF(editor.name,''),editor.email) AS editor_name FROM lot_sellers s LEFT JOIN admins creator ON creator.id=s.created_by_admin_id LEFT JOIN admins editor ON editor.id=s.updated_by_admin_id WHERE s.id=?");
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
    <div class="lot-editor-toolbar-actions">
      <a class="secondary seller-editor-cancel" href="<?=e($fromLot?$returnLotUrl:url('admin/verkopers.php'))?>" <?=$fromLot?'data-return-to-lot="1"':''?>>Annuleren</a>
      <button class="primary seller-save-trigger" type="submit" form="seller-manager-form"><?=$editId!==null?'Wijzigingen opslaan':'Verkoper opslaan'?></button>
    </div>
  </div>
  <?php if ($error): ?><div class="alert error" role="alert"><?=e($error)?></div><?php endif; ?>
  <section class="lot-editor-card seller-editor-card" id="seller-form" aria-labelledby="seller-editor-heading">
    <div class="lot-editor-card-header"><span class="lot-editor-step" aria-hidden="true">01</span><div><h2 id="seller-editor-heading"><?=$editId!==null?'Verkoper bewerken':'Nieuwe verkoper'?></h2><p>Alle algemene gegevens zijn verplicht. Bankgegevens zijn voorlopig optioneel. Voor kunstenaars gelden ook de extra kunstenaarsvelden.</p></div></div>
    <?php if ($editId!==null && $values['seller_type']==='kunstenaar'): ?>
      <p class="dashboard-subtitle"><strong>Publicatiestatus:</strong> <?=((int)($values['artist_published']??0)===1?'Gepubliceerd':((int)($values['artist_published']??0)===2?'Nieuw':'Niet gepubliceerd'))?>. De status wordt uitsluitend door de Veilingmeester of Administrator gewijzigd via Verkopers beheren.</p>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="seller-manager-form" id="seller-manager-form" data-seller-upload="1">
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
          <label for="artist-slogan">Slogan van de kunstenaar (optioneel)</label>
          <input id="artist-slogan" name="artist_slogan" type="text" maxlength="250" value="<?=e((string)$values['artist_slogan'])?>" placeholder="Een korte slogan van de kunstenaar">
          <p class="lot-editor-help">Maximaal 250 tekens.</p>
        </div>
        <div class="seller-introduction">
          <label for="artist-intro">Korte voorstelling van de kunstenaar (optioneel)</label>
          <textarea id="artist-intro" name="artist_intro" rows="4" maxlength="500" placeholder="Vertel kort wie de kunstenaar is..."><?=e((string)$values['artist_intro'])?></textarea>
          <p class="lot-editor-help">Maximaal 500 tekens.</p>
        </div>
        <div class="seller-gallery">
          <h4>Foto’s van werken <span class="seller-optional">(maximaal 3)</span></h4>
          <div class="lot-editor-photo-grid seller-lot-photo-grid" data-seller-gallery="work" data-max-photos="3" data-existing="<?=count(array_filter($photos,static fn($p)=>$p['image_kind']==='work'))?>" data-seller-id="<?=$editId!==null?(int)$editId:''?>">
            <?php foreach($photos as $photo): if($photo['image_kind']!=='work') continue; ?>
            <div class="lot-editor-photo seller-saved-photo" data-seller-slot="<?=(int)$photo['slot']?>">
              <img src="<?=e(url($photo['path']))?>" alt="Foto van werk">
              <button type="button" class="lot-editor-remove-photo seller-remove-saved" data-kind="work" data-slot="<?=(int)$photo['slot']?>">Foto verwijderen</button>
            </div>
            <?php endforeach; ?>
            <div class="lot-editor-photo lot-editor-photo-add" data-seller-picker>
              <label class="lot-editor-photo-add-picker">
                <span class="lot-editor-upload-icon" aria-hidden="true">＋</span>
                <strong>Foto toevoegen</strong>
                <span class="lot-editor-photo-add-help">JPG, PNG of WebP · max. 16 MB</span>
                <input type="file" accept="image/jpeg,image/png,image/webp" data-seller-new-file aria-label="Foto van werk toevoegen">
              </label>
            </div>
          </div>
          <p class="lot-editor-help seller-photo-group-status" data-seller-status="work" role="status" aria-live="polite"></p>
          <h4>Portretfoto van de kunstenaar <span class="seller-optional">(optioneel, maximaal 1)</span></h4>
          <div class="lot-editor-photo-grid seller-lot-photo-grid" data-seller-gallery="portrait" data-max-photos="1" data-existing="<?=isset($photoByKey['portrait_1'])?1:0?>" data-seller-id="<?=$editId!==null?(int)$editId:''?>">
            <?php if(isset($photoByKey['portrait_1'])): ?>
            <div class="lot-editor-photo seller-saved-photo" data-seller-slot="1">
              <img src="<?=e(url($photoByKey['portrait_1']['path']))?>" alt="Portret van kunstenaar">
              <button type="button" class="lot-editor-remove-photo seller-remove-saved" data-kind="portrait" data-slot="1">Foto verwijderen</button>
            </div>
            <?php endif; ?>
            <div class="lot-editor-photo lot-editor-photo-add" data-seller-picker>
              <label class="lot-editor-photo-add-picker">
                <span class="lot-editor-upload-icon" aria-hidden="true">＋</span>
                <strong>Foto toevoegen</strong>
                <span class="lot-editor-photo-add-help">JPG, PNG of WebP · max. 16 MB</span>
                <input type="file" accept="image/jpeg,image/png,image/webp" data-seller-new-file aria-label="Portretfoto toevoegen">
              </label>
            </div>
          </div>
          <p class="lot-editor-help seller-photo-group-status" data-seller-status="portrait" role="status" aria-live="polite"></p>
          <p class="lot-editor-help">JPG, PNG of WebP · maximaal 16 MB en 48 megapixel per foto. Foto’s worden automatisch verkleind tot maximaal 1800 pixels en als WebP bewaard. Bij een nieuwe verkoper verschijnen foto's meteen als voorbeeld en worden ze pas bij Verkoper opslaan bewaard. Bij een bestaande verkoper worden ze meteen opgeslagen.</p>
        </div>
      </div>
      <div class="seller-form-actions">
        <a class="secondary" href="<?=e($fromLot?$returnLotUrl:url('admin/verkopers.php'))?>" <?=$fromLot?'data-return-to-lot="1"':''?>>Annuleren</a>
        <button class="primary seller-save-trigger" type="submit" id="seller-save-button"><?=$editId!==null?'Wijzigingen opslaan':'Verkoper opslaan'?></button>
      </div>
      <div id="seller-save-progress" class="seller-save-progress" role="status" aria-live="polite" hidden>
        <div class="seller-save-progress-heading"><strong id="seller-save-status">Gegevens uploaden…</strong><span id="seller-save-percent" hidden></span></div>
        <progress id="seller-upload-progress" max="100" aria-label="Voortgang upload"></progress>
        <p>Sluit deze pagina niet zolang het opslaan bezig is.</p>
      </div>
      <div id="seller-save-error" class="alert error" role="alert" hidden></div>
    </form>

  </section>
    <?php if($audit): ?><div class="seller-audit-summary"><strong>Aangemaakt door:</strong> <?=e($audit['creator_name'] ?: 'Onbekend')?> · <?=e($audit['created_at'] ? seller_audit_time((string)$audit['created_at']).' (Brussel)' : 'Niet geregistreerd')?><div><strong>Laatste wijziging:</strong> <?=e($audit['editor_name'] ?: 'Onbekend')?> · <?=e($audit['updated_at'] ? seller_audit_time((string)$audit['updated_at']).' (Brussel)' : 'Niet geregistreerd')?></div></div><?php endif; ?>
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
  const form=document.getElementById('seller-manager-form');
  const submits=[...document.querySelectorAll('.seller-save-trigger')];
  const submit=form?.querySelector('#seller-save-button');
  const panel=document.getElementById('seller-save-progress');
  const bar=document.getElementById('seller-upload-progress');
  const pct=document.getElementById('seller-save-percent');
  const status=document.getElementById('seller-save-status');
  const error=document.getElementById('seller-save-error');
  let saving=false;
  if (form && submit && panel && bar && pct && status && error && window.XMLHttpRequest && window.FormData) {
    form.addEventListener('submit', event => {
      event.preventDefault();
      if (saving || !form.reportValidity()) return;
      saving=true;
      submits.forEach(button=>{button.disabled=true;button.dataset.originalText=button.textContent;button.textContent='Bezig met opslaan…';});
      error.hidden=true;
      error.textContent='';
      panel.hidden=false;
      pct.hidden=true;
      bar.removeAttribute('value');
      status.textContent="Gegevens en foto's uploaden…";
      const xhr=new XMLHttpRequest();
      xhr.open('POST',form.getAttribute('action') || window.location.href);
      xhr.setRequestHeader('X-Requested-With','XMLHttpRequest');
      xhr.responseType='json';
      const fail=message => {
        saving=false;
        submits.forEach(button=>{button.disabled=false;button.textContent=button.dataset.originalText||'Verkoper opslaan';});
        panel.hidden=true;
        error.textContent=message;
        error.hidden=false;
      };
      xhr.upload.addEventListener('progress', e => {
        if (!saving || !e.lengthComputable || !e.total) return;
        bar.value=Math.min(100,Math.round(e.loaded*100/e.total));
        pct.textContent=bar.value+'%';
        pct.hidden=false;
      });
      xhr.upload.addEventListener('load', () => {
        if (!saving) return;
        status.textContent="Gegevens en foto's verwerken…";
        bar.removeAttribute('value');
        pct.hidden=true;
      });
      xhr.addEventListener('load', () => {
        const body=xhr.response;
        if (xhr.status===200 && body && typeof body.redirect==='string') {
          const target=new URL(body.redirect,window.location.href);
          if (target.origin===window.location.origin) {
            saving=false;
            status.textContent='Verkoper opgeslagen. Pagina openen…';
            window.location.assign(target.href);
            return;
          }
        }
        if (xhr.status===422 && body && typeof body.error==='string') {
          fail(body.error);
          return;
        }
        fail('Opslaan kon niet worden bevestigd. Controleer eerst of de verkoper al bewaard is voordat je opnieuw probeert.');
      });
      xhr.addEventListener('error', () => fail('Verbinding verbroken. Controleer eerst of de verkoper al bewaard is voordat je opnieuw probeert.'));
      xhr.addEventListener('abort', () => fail('Upload afgebroken. Controleer eerst of de verkoper al bewaard is voordat je opnieuw probeert.'));
      try { xhr.send(new FormData(form)); }
      catch (_) { fail('Upload niet gestart. Controleer je verbinding.'); }
    });
    window.addEventListener('beforeunload', event => {
      if (saving) { event.preventDefault(); event.returnValue=''; }
    });
  }
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
<script src="<?=e(url('admin/seller-photo-preview.js', ['v'=>substr((string)hash_file('sha256', __DIR__ . '/seller-photo-preview.js'), 0, 12)]))?>" defer></script>
<?php admin_bottom(); ?>
