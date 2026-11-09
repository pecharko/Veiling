<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/private/bootstrap.php';
require dirname(__DIR__, 2) . '/private/images.php';
require_once dirname(__DIR__, 2) . '/private/photo_delete_safety.php';
require_once dirname(__DIR__, 2) . '/private/catalog_mutex.php';
require dirname(__DIR__, 2) . '/private/lot_form.php';
require_once dirname(__DIR__, 2) . '/private/lot_artist.php';
require_once dirname(__DIR__, 2) . '/private/admin_names.php';
require_once dirname(__DIR__, 2) . '/private/lot_permissions.php';
require_auth();
$pdo=db();
$id=max(0,(int)($_GET['id']??$_POST['id']??0));
$lot = null;
if($id){$st=$pdo->prepare('SELECT * FROM lots WHERE id=?');$st->execute([$id]);$lot=$st->fetch();if(!$lot){http_response_code(404);exit('Kavel niet gevonden.');}}
$error='';
if(is_post()){
  check_csrf();
  // Hold until request shutdown, including every DB and photo-file mutation.
  catalog_lock_for_request($pdo);
  // Re-read from storage after taking the catalog mutex; POST cannot dictate
  // authorization, current publication status or ownership.
  if ($id) {
    $authSt = $pdo->prepare('SELECT * FROM lots WHERE id=?');
    $authSt->execute([$id]);
    $lot = $authSt->fetch();
    if (!$lot) { http_response_code(404); exit('Kavel niet gevonden.'); }
    if (!lot_can_edit(effective_admin_role(), (int)$_SESSION['admin_id'], $lot)) {
      http_response_code(403);
      exit('Geen bewerkingsrechten voor dit kavel.');
    }
  } elseif (!lot_can_create(effective_admin_role())) {
    http_response_code(403);
    exit('Geen rechten om kavels aan te maken.');
  }
  $action=(string)($_POST['action']??'save');
  if($action==='add-photo' && $lot){
    $ajax = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    $uploaded = 0;
    $uploadError = '';
    try {
      $photos=photo_files($_FILES['photos']??[]);
      if (count($photos) !== 1 && $ajax) {
        $uploadError='Selecteer precies één foto.';
      } elseif (!$photos) {
        $uploadError='Kies minstens één foto om toe te voegen.';
      } else {
        $countSt=$pdo->prepare('SELECT COUNT(*) FROM lot_images WHERE lot_id=?');
        $countSt->execute([$id]);
        $available=max(0,3-(int)$countSt->fetchColumn());
        if (count($photos)>$available) {
          $uploadError='Maximaal 3 foto’s per kavel.';
        } else {
          foreach($photos as $i=>$photo){
            try {
              add_lot_image($id,$photo['tmp'],$photo['name'],100+$i);
              $uploaded++;
            } catch(Throwable $ex) {
              error_log('[Kavel foto toevoegen] '.$ex->getMessage());
              $uploadError='Foto kon niet worden verwerkt. Controleer het bestand en de lokale serverlogs.';
              break;
            }
          }
        }
      }
    } catch (RuntimeException $ex) {
      error_log('[Kavel foto-upload] '.$ex->getMessage());
      $uploadError='Foto-upload mislukt. Controleer het bestand en de lokale serverlogs.';
    }
    if ($ajax) {
      // The response reports actual DB state: never infer success from a redirect.
      header('Content-Type: application/json; charset=utf-8');
      header('Cache-Control: no-store, private');
      header('X-Content-Type-Options: nosniff');
      if ($uploaded !== 1 || $uploadError !== '') {
        http_response_code(422);
        echo json_encode(['ok'=>false,'error'=>$uploadError ?: 'Foto kon niet worden opgeslagen.'], JSON_UNESCAPED_UNICODE);
      } else {
        $lastPhotoSt=$pdo->prepare('SELECT id,path FROM lot_images WHERE lot_id=? ORDER BY id DESC LIMIT 1');
        $lastPhotoSt->execute([$id]);
        $savedPhoto=$lastPhotoSt->fetch();
        if (!$savedPhoto) {
          http_response_code(500);
          echo json_encode(['ok'=>false,'error'=>'Foto opgeslagen, maar kon niet worden opgehaald. Vernieuw de pagina.'], JSON_UNESCAPED_UNICODE);
        } else {
          $countSt=$pdo->prepare('SELECT COUNT(*) FROM lot_images WHERE lot_id=?');
          $countSt->execute([$id]);
          echo json_encode(['ok'=>true,'photo_id'=>(int)$savedPhoto['id'],'image_url'=>url((string)$savedPhoto['path']),'count'=>(int)$countSt->fetchColumn()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
      }
      exit;
    }
    flash($uploadError !== '' ? $uploadError : $uploaded.' foto(’s) toegevoegd.', $uploadError !== '' ? 'warning' : 'success');
    redirect('admin/kavel.php',['id'=>$id]);
  }
  if($action==='remove-photo'){
    $photoId=(int)($_POST['photo_id']??0);
    $get=$pdo->prepare('SELECT path FROM lot_images WHERE id=? AND lot_id=?');$get->execute([$photoId,$id]);$photo=$get->fetch();
    if($photo){
      $pdo->prepare('DELETE FROM lot_images WHERE id=? AND lot_id=?')->execute([$photoId,$id]);
      if(str_starts_with($photo['path'],'uploads/') && preg_match('~^uploads/[0-9]{4}/[0-9]{2}/[a-f0-9]{32}\.(webp|jpg|png)$~',$photo['path']))@unlink(dirname(__DIR__).'/'.$photo['path']);
      flash('Foto verwijderd.');
    }
    redirect('admin/kavel.php',['id'=>$id]);
  }
  if($action==='delete-lot' && $lot){
    // Deletion and gap-free auction numbering are one atomic DB operation.
    // The request-wide catalog mutex also serializes number allocation.
    try {
      $pdo->beginTransaction();
      $st=$pdo->prepare('SELECT path FROM lot_images WHERE lot_id=?');$st->execute([$id]);$paths=$st->fetchAll(PDO::FETCH_COLUMN);
      $pdo->prepare('DELETE FROM lots WHERE id=?')->execute([$id]);
      if ($lot['auction_number'] !== null) compact_auction_numbers($pdo);
      $pdo->commit();
    } catch (Throwable $ex) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      error_log('Kavel verwijderen/hernummeren mislukt: '.$ex->getMessage());
      flash('Kavel kon niet verwijderd worden. Er zijn geen veilingsnummers gewijzigd.', 'warning');
      redirect('admin/kavel.php', ['id' => $id]);
    }
    // File removal only after successful DB commit; never on rollback.
    foreach($paths as $path)if(preg_match('~^uploads/[0-9]{4}/[0-9]{2}/[a-f0-9]{32}\.(webp|jpg|png)$~',$path))@unlink(dirname(__DIR__).'/'.$path);
    flash('Kavel verwijderd. Veilingsnummers zijn indien nodig herberekend.');redirect('admin/index.php');
  }
  if($action==='save'){
    $nr=(int)($_POST['lot_number']??0);
    $title=mb_substr(trim((string)($_POST['title']??'')),0,250);
    $subtitle=mb_substr(trim((string)($_POST['subtitle']??'')),0,250);
    try {
      if (!is_string($_POST['artist_name'] ?? '') || !is_string($_POST['artist_url'] ?? '')) {
        throw new InvalidArgumentException('Ongeldige invoer voor kunstenaar.');
      }
      [$artistName, $artistUrl] = lot_artist_validate($_POST['artist_name'] ?? '', $_POST['artist_url'] ?? '');
    } catch (InvalidArgumentException $ex) {
      $error = $ex->getMessage();
    }
    $category=mb_substr(trim((string)($_POST['category']??'Overige')),0,120);
    $description=mb_substr(trim((string)($_POST['description']??'')),0,30000);
    $details=mb_substr(trim((string)($_POST['details']??'')),0,10000);
    $marketPrice=trim((string)($_POST['market_price']??''));
    $startingBid=trim((string)($_POST['starting_bid']??''));
    $submittedStatus = in_array(($_POST['status'] ?? ''), ['draft','published','sold','canceled'], true) ? (string)$_POST['status'] : 'draft';
    // Assistants cannot publish, unpublish or use a fabricated POST status.
    $role = effective_admin_role();
    $status = lot_can_change_publication($role)
      ? $submittedStatus : (string)($lot['status'] ?? 'draft');
    $auctionOnly = !empty($_POST['auction_only']);
    $saleLocation=(string)($_POST['sale_location']??'markt');
    if($error!=='') { /* Existing validation error: no DB or photo writes. */ }
    elseif(mb_strlen($title)>50)$error='De titel mag maximaal 50 tekens bevatten.';
    elseif(mb_strlen($subtitle)>50)$error='De ondertitel mag maximaal 50 tekens bevatten.';
    elseif(mb_strlen($description)>250)$error='De beschrijving mag maximaal 250 tekens bevatten.';
    elseif(mb_strlen($details)>100)$error='Details, afmetingen en staat mogen maximaal 100 tekens bevatten.';
    elseif($nr<1 || $nr>999999)$error='Vul een geldig lotnummer in.';
    elseif(!in_array($saleLocation,['markt','veiling'],true))$error='Kies een geldige verkoopplaats.';
    elseif($status==='canceled' && ($saleLocation==='veiling' || (($lot['sale_location'] ?? '')==='veiling')))$error='Zet dit kavel eerst afzonderlijk over naar Markt en sla op voordat je het kunt annuleren.';
    elseif($title==='')$error='Een titel is verplicht.';
    elseif (lot_publication_conflict($status, $saleLocation, $auctionOnly)) $error='Dit kavel is uitsluitend voor de veiling en mag niet op Markt gepubliceerd worden. Publicatie afgebroken.';
    elseif($marketPrice!=='' && (!is_numeric($marketPrice)|| (float)$marketPrice<0))$error='Ongeldige marktprijs.';
    elseif($startingBid!=='' && (!is_numeric($startingBid)|| (float)$startingBid<0))$error='Ongeldig startbod.';
    else{
      // Valideer alle uploadstatussen voordat er een kavel wordt aangemaakt of gewijzigd.
      try{
        $photos=photo_files($_FILES['photos']??[]);
      }catch(RuntimeException $ex){
        error_log('[Kavel foto-upload] '.$ex->getMessage());
        $error='Foto-upload mislukt; het kavel is niet opgeslagen.';
      }
      if (!$error && $photos) {
        $existingPhotoCount = 0;
        if ($id) {
          $photoCountSt = $pdo->prepare('SELECT COUNT(*) FROM lot_images WHERE lot_id=?');
          $photoCountSt->execute([$id]);
          $existingPhotoCount = (int)$photoCountSt->fetchColumn();
        }
        if ($existingPhotoCount + count($photos) > 3) {
          $error = 'Maximaal 3 foto’s per kavel. Het kavel is niet opgeslagen.';
        }
      }
      if(!$error){
        try{
          // The catalog mutex serializes allocation and gap-free resequencing.
          // All lot changes and shifts must succeed together.
          $pdo->beginTransaction();
          // A newly assigned ordinary lot number is never reusable, even after delete.
          // The permanent registry is part of this same transaction.
          if (!$id || (int)$lot['lot_number'] !== $nr) {
            $reserve = $pdo->prepare('INSERT INTO issued_lot_numbers (lot_number) VALUES (?)');
            $reserve->execute([$nr]);
          }
          $wasAuction = ($lot['auction_number'] ?? null) !== null;
          $auctionNumber = $saleLocation === 'markt' ? null : ($lot['auction_number'] ?? null);
          if ($saleLocation === 'veiling' && $auctionNumber === null) {
            $auctionNumber = next_auction_number($pdo);
          }
          $values=[$nr,$title,$subtitle,$artistName,$artistUrl,$category,$description,$details,$marketPrice===''?null:$marketPrice,$startingBid===''?null:$startingBid,(int)$auctionOnly,$status,$saleLocation,$auctionNumber];
          if($id){$values[]=$id;$st=$pdo->prepare('UPDATE lots SET lot_number=?,title=?,subtitle=?,artist_name=?,artist_url=?,category=?,description=?,details=?,market_price=?,starting_bid=?,auction_only=?,status=?,sale_location=?,auction_number=? WHERE id=?');}
          else{$values[]=(int)$_SESSION['admin_id'];$st=$pdo->prepare('INSERT INTO lots (lot_number,title,subtitle,artist_name,artist_url,category,description,details,market_price,starting_bid,auction_only,status,sale_location,auction_number,created_by_admin_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');}
          $st->execute($values);
          if(!$id)$id=(int)$pdo->lastInsertId();
          if ($saleLocation === 'markt' && $wasAuction) compact_auction_numbers($pdo);
          $pdo->commit();
          $failed=[];
          foreach($photos as $i=>$photo){
            try{add_lot_image($id,$photo['tmp'],$photo['name'],100+$i);}
            catch(Throwable $ex){error_log('[Kavel foto] '.$ex->getMessage());$failed[]=$photo['name'].': verwerking mislukt';}
          }
          flash($failed?'Kavel bewaard, maar sommige foto’s mislukten: '.implode('; ',$failed):"Kavel en foto's bewaard.", $failed?'warning':'success');
          redirect('admin/kavel.php',['id'=>$id]);
        }catch(PDOException $ex){
          if ($pdo->inTransaction()) $pdo->rollBack();
          if($ex->getCode()==='23000'){
            $driverCode=(int)($ex->errorInfo[1] ?? 0);
            $detail=(string)($ex->errorInfo[2] ?? '');
            if($driverCode===1062 && str_contains($detail,'uq_lots_auction_number'))$error='Dit veilingsnummer bestaat al. Het kavel is niet opgeslagen.';
            elseif($driverCode===1062 && (str_contains($detail,'lot_number') || str_contains($detail,'PRIMARY'))) $error='Dit lotnummer is al uitgegeven en kan niet worden hergebruikt.';
            else $error='Een unieke waarde bestaat al. Het kavel is niet opgeslagen.';
          }
          else{$error='Kon kavel niet opslaan. Controleer de invoer.';error_log($ex->getMessage());}
        }catch(Throwable $ex){if ($pdo->inTransaction()) $pdo->rollBack();error_log('[Kavel bewaren] '.$ex->getMessage());$error='Er ging iets mis met het bewaren.';}
      }
    }
  }
}
if($id){$st=$pdo->prepare('SELECT * FROM lots WHERE id=?');$st->execute([$id]);$lot=$st->fetch();}
$images=[];if($lot){$st=$pdo->prepare('SELECT * FROM lot_images WHERE lot_id=? ORDER BY sort_order,id');$st->execute([$id]);$images=$st->fetchAll();}
$creatorLabel = 'Onbekend (bestaand kavel)';
if ($lot && !empty($lot['created_by_admin_id'])) {
  $creatorSt = $pdo->prepare('SELECT name FROM admins WHERE id=?');
  $creatorSt->execute([(int)$lot['created_by_admin_id']]);
  $creatorName = $creatorSt->fetchColumn();
  $creatorLabel = $creatorName === false ? 'Account niet meer aanwezig' : admin_name_label((string)$creatorName);
}
$suggestedNumber = '';
if (str_starts_with($error, 'Dit lotnummer is al uitgegeven en kan niet worden hergebruikt.')) {
  $highestNumber = $pdo->query('SELECT MAX(lot_number) FROM issued_lot_numbers')->fetchColumn();
  $nextNumber = first_available_lot_number(($highestNumber === null || $highestNumber === false) ? [] : [$highestNumber]);
  if ($nextNumber !== null) {
    $suggestedNumber = $nextNumber;
    $error = 'Dit lotnummer is al uitgegeven en kan niet worden hergebruikt. Voorgesteld nieuw lotnummer: ' . $nextNumber . '. Je kunt dit aanpassen.';
  } else {
    $error = 'Dit lotnummer is al uitgegeven en kan niet worden hergebruikt. Er is geen volgend lotnummer beschikbaar binnen de toegestane reeks.';
  }
}
if (!$lot && !is_post()) {
  $existingNumbers = $pdo->query('SELECT MAX(lot_number) FROM issued_lot_numbers')->fetchColumn();
  $suggestedNumber = first_available_lot_number(($existingNumbers === null || $existingNumbers === false) ? [] : [$existingNumbers]) ?? '';
}
$defaults=['lot_number'=>$suggestedNumber,'title'=>'','subtitle'=>'','artist_name'=>'','artist_url'=>'','category'=>'Overige','description'=>'','details'=>'','market_price'=>'','starting_bid'=>'','auction_only'=>0,'status'=>'draft','sale_location'=>'markt','auction_number'=>''];
$submitted = ($error !== '' && is_post() && ($_POST['action'] ?? 'save') === 'save') ? $_POST : null;
$d=lot_form_values($defaults,$lot?:null,$submitted);
if ($submitted !== null && $suggestedNumber !== '') $d['lot_number'] = (string)$suggestedNumber;
$canEditLot = !$lot || lot_can_edit(effective_admin_role(), (int)$_SESSION['admin_id'], $lot);
admin_top($lot?'Kavel '.str_pad((string)$lot['lot_number'],3,'0',STR_PAD_LEFT).' bewerken':'Nieuw kavel');
if (!$canEditLot) echo '<div class="alert warning" role="alert">Alleen de maker van een eigen conceptkavel, de Veilingmeester of de Administrator mag dit kavel wijzigen. Dit kavel is alleen-lezen.</div>';
$cancelAuctionWarning = 'Zet dit kavel eerst afzonderlijk over naar Markt en sla op voordat je het kunt annuleren.';
if ($error === $cancelAuctionWarning) {
  // Progressive enhancement: warning remains readable when JavaScript is disabled.
  echo '<div class="alert error lot-cancel-fallback" role="alert">'.e($error).'</div>';
} elseif ($error) {
  echo '<div class="alert error" role="alert">'.e($error).'</div>';
}
?>
<?php if ($error === $cancelAuctionWarning): ?>
<dialog id="lot-cancel-warning" class="lot-cancel-dialog" aria-labelledby="lot-cancel-title" aria-describedby="lot-cancel-description">
  <div class="lot-cancel-dialog-content">
    <div class="lot-cancel-dialog-icon" aria-hidden="true">!</div>
    <h2 id="lot-cancel-title">Annuleren niet mogelijk</h2>
    <p id="lot-cancel-description"><?=e($cancelAuctionWarning)?></p>
    <form method="dialog"><button class="primary" type="submit">Begrepen</button></form>
  </div>
</dialog>
<script>
  (() => {
    const dialog = document.getElementById('lot-cancel-warning');
    if (dialog && typeof dialog.showModal === 'function') {
      dialog.showModal();
      const fallback = document.querySelector('.lot-cancel-fallback');
      if (fallback) fallback.hidden = true;
    }
  })();
</script>
<?php endif; ?>
<div class="lot-editor">
  <div class="lot-editor-toolbar">
    <div><a class="lot-editor-back" href="<?=e(url('admin/index.php'))?>">← Terug naar kavels</a><p class="lot-editor-hint">Beheer gegevens, waardering, publicatie en foto's op één plek.</p></div>
    <div class="lot-editor-toolbar-actions">
      <?php if($lot && $d['status']==='published'): ?><a class="secondary" href="<?=e(url('kavel.php',['nr'=>$d['lot_number']]))?>" target="_blank" rel="noopener">Bekijk online ↗</a><?php endif; ?>
      <a class="secondary lot-editor-cancel" href="<?=e(url('admin/index.php'))?>">Annuleren</a>
      <?php if($canEditLot): ?><button form="lot-editor-form" class="primary" type="submit"><?=$lot?'Wijzigingen opslaan':'Kavel opslaan'?></button><?php endif; ?>
    </div>
  </div>
  <form id="lot-editor-form" method="post" enctype="multipart/form-data">
    <?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="save">
    <fieldset <?=!$canEditLot?'disabled':''?> style="border:0;padding:0;margin:0;min-width:0">
        <section class="lot-editor-card lot-editor-publication"><div class="lot-editor-card-header"><div><h2>Publicatie</h2><p>Bepaal of bezoekers dit kavel mogen zien.</p></div></div>
          <div class="lot-editor-fields"><?php if(lot_can_change_publication(effective_admin_role()) && $canEditLot): ?><label>Status<select name="status"><option value="draft" <?=$d['status']==='draft'?'selected':''?>>Concept — niet zichtbaar</option><option value="published" <?=$d['status']==='published'?'selected':''?>>Gepubliceerd</option><option value="sold" <?=$d['status']==='sold'?'selected':''?>>Verkocht</option><option value="canceled" <?=$d['status']==='canceled'?'selected':''?>>Canceled</option></select></label><?php else: ?><p class="lot-editor-help"><strong>Status:</strong> <?=(['draft'=>'Concept — niet zichtbaar','published'=>'Gepubliceerd','sold'=>'Verkocht','canceled'=>'Canceled'][$d['status']] ?? 'Onbekend')?>. Alleen Veilingmeester en Administrator mogen publicatie wijzigen.</p><?php endif; ?><p class="lot-editor-help">Een gepubliceerd kavel wordt pas zichtbaar als de veilingwebsite zelf openbaar staat.</p></div>
        </section>
    <div class="lot-editor-layout lot-editor-layout--single">
      <div class="lot-editor-main">
        <section class="lot-editor-card"><div class="lot-editor-card-header"><span class="lot-editor-step">01</span><div><h2>Basisinformatie</h2><p>De belangrijkste gegevens die bezoekers in de catalogus zien.</p></div></div>
          <div class="lot-editor-fields lot-editor-two">
            <label class="lot-editor-lot-number"><span class="lot-editor-label">Lotnummer <span class="lot-required">*</span></span><input type="number" name="lot_number" min="1" max="999999" value="<?=e($d['lot_number'])?>" required></label>
            <label>Verkoopplaats <select name="sale_location"><option value="markt" <?=$d['sale_location']==='markt'?'selected':''?>>Markt</option><option value="veiling" <?=$d['sale_location']==='veiling'?'selected':''?>>Veiling</option></select></label>
            <label class="lot-editor-checkbox"><input type="checkbox" name="auction_only" value="1" <?=!empty($d['auction_only'])?'checked':''?>> Uitsluitend veiling (niet op Markt publiceren)</label>
            <label class="lot-editor-category">Categorie<input type="text" name="category" value="<?=e($d['category'])?>" maxlength="120" placeholder="Kunst, lifestyle, vintage …"></label>
            <label class="lot-full"><span class="lot-editor-label">Titel <span class="lot-required">*</span></span><input type="text" name="title" value="<?=e($d['title'])?>" maxlength="50" required placeholder="Bijvoorbeeld: Champagnepakket" aria-describedby="lot-title-error"><span id="lot-title-error" class="lot-editor-help" role="status" aria-live="polite" hidden>De titel mag maximaal 50 tekens bevatten.</span></label>
            <label class="lot-full">Ondertitel<input type="text" name="subtitle" value="<?=e($d['subtitle'])?>" maxlength="50" placeholder="Aanvullende informatie" aria-describedby="lot-subtitle-error"><span id="lot-subtitle-error" class="lot-editor-help" role="status" aria-live="polite" hidden>De ondertitel mag maximaal 50 tekens bevatten.</span></label>
            <label>Kunstenaar<input type="text" name="artist_name" value="<?=e($d['artist_name'])?>" maxlength="50" placeholder="Naam van de kunstenaar" aria-describedby="lot-artist-error"><span id="lot-artist-error" class="lot-editor-help" role="status" aria-live="polite" hidden>De naam van de kunstenaar mag maximaal 50 tekens bevatten.</span></label>
            <label>Hyperlink kunstenaar<input type="text" inputmode="url" name="artist_url" value="<?=e($d['artist_url'])?>" maxlength="2048" placeholder="https://website-van-kunstenaar.be/" aria-describedby="artist-link-help"></label>
            <p id="artist-link-help" class="lot-full">Optioneel. Ook www.marf.be mag: dit wordt automatisch https://www.marf.be. Vul ook de kunstenaarsnaam in.</p>
          </div>
        </section>
        <section class="lot-editor-card"><div class="lot-editor-card-header"><span class="lot-editor-step">02</span><div><h2>Beschrijving</h2><p>Beschrijf het object zo helder mogelijk voor geïnteresseerde bezoekers.</p></div></div>
          <div class="lot-editor-fields"><label>Beschrijving<textarea name="description" rows="6" maxlength="250" placeholder="Wat maakt dit kavel bijzonder?"><?=e($d['description'])?></textarea></label><label>Details, afmetingen en staat<textarea name="details" rows="4" maxlength="100" placeholder="Afmetingen, technische informatie, eventuele gebruikssporen …"><?=e($d['details'])?></textarea></label></div>
        </section>
        <section class="lot-editor-card"><div class="lot-editor-card-header"><span class="lot-editor-step">03</span><div><h2>Waardebepaling</h2><p>Marktprijs en startbod in euro.</p></div></div>
          <div class="lot-editor-fields lot-editor-two"><label>Marktprijs (€)<input type="number" min="0" step="0.01" name="market_price" value="<?=e(lot_price_input_value($d['market_price'], $submitted !== null))?>" placeholder="0"></label><label>Startbod (€)<input type="number" min="0" step="0.01" name="starting_bid" value="<?=e(lot_price_input_value($d['starting_bid'], $submitted !== null))?>" placeholder="0"></label></div>
        </section>
      </div>
    </div>
    </fieldset>
  </form>
  <?php if(!$lot): ?>
  <section class="lot-editor-card lot-editor-photos">
    <div class="lot-editor-card-header"><div><h2>Foto's bij dit kavel</h2><p>Selecteer maximaal 3 foto's. Ze verschijnen meteen als voorbeeld en worden opgeslagen zodra je het nieuwe kavel bewaart.</p></div></div>
    <div class="lot-editor-photo-grid" id="lot-new-photo-grid" data-max-photos="3">
      <div class="lot-editor-photo lot-editor-photo-add">
        <label class="lot-editor-photo-add-picker">
          <span class="lot-editor-upload-icon" aria-hidden="true">＋</span>
          <strong>Foto toevoegen</strong>
          <span class="lot-editor-photo-add-help">JPG, PNG of WebP · max. 16 MB</span>
          <input type="file" name="photos[]" form="lot-editor-form" accept="image/jpeg,image/png,image/webp" aria-label="Foto selecteren">
        </label>
      </div>
    </div>
    <div class="lot-editor-photo-upload-actions">
      <p class="lot-editor-help" id="lot-new-photo-status" role="status" aria-live="polite">Foto's worden opgeslagen wanneer je op Kavel opslaan klikt.</p>
    </div>
    <template id="lot-new-photo-picker-template">
      <div class="lot-editor-photo lot-editor-photo-add">
        <label class="lot-editor-photo-add-picker">
          <span class="lot-editor-upload-icon" aria-hidden="true">＋</span>
          <strong>Foto toevoegen</strong>
          <span class="lot-editor-photo-add-help">JPG, PNG of WebP · max. 16 MB</span>
          <input type="file" name="photos[]" form="lot-editor-form" accept="image/jpeg,image/png,image/webp" aria-label="Foto selecteren">
        </label>
      </div>
    </template>
    <script src="<?=e(url('admin/lot-new-photo-preview.js', ['v'=>substr((string)hash_file('sha256', __DIR__ . '/lot-new-photo-preview.js'), 0, 12)]))?>" defer></script>
  </section>
  <div class="lot-editor-bottom"><a class="secondary" href="<?=e(url('admin/index.php'))?>">Annuleren</a><?php if($canEditLot): ?><button form="lot-editor-form" class="primary" type="submit">Kavel opslaan</button><?php endif; ?></div>
  <?php endif; ?>
  <?php if($lot): ?>
  <section class="lot-editor-card lot-editor-photos"><div class="lot-editor-card-header"><div><h2>Foto's bij dit kavel</h2><p>De foto met het laagste volgnummer verschijnt als hoofdfoto. Voeg hier foto's toe of verwijder bestaande foto's.</p></div></div>
    <?php if($canEditLot && count($images)<3): ?>
    <form id="lot-photo-upload-form" method="post" enctype="multipart/form-data">
      <?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="add-photo">
    </form>
    <?php endif; ?>
    <div class="lot-editor-photo-grid" id="lot-photo-grid" data-existing-count="<?=count($images)?>" data-max-photos="3">
      <?php foreach($images as $photo): ?>
      <div class="lot-editor-photo">
        <img src="<?=e(url($photo['path']))?>" alt="Kavelfoto">
        <?php if($canEditLot): ?><form method="post" onsubmit="return confirm('Deze foto verwijderen?')"><?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="remove-photo"><input type="hidden" name="photo_id" value="<?=(int)$photo['id']?>"><button class="lot-editor-remove-photo" type="submit">Foto verwijderen</button></form><?php endif; ?>
      </div>
      <?php endforeach; ?>
      <?php if($canEditLot && count($images)<3): ?>
      <div class="lot-editor-photo lot-editor-photo-add">
        <label class="lot-editor-photo-add-picker">
          <span class="lot-editor-upload-icon" aria-hidden="true">＋</span>
          <strong>Foto toevoegen</strong>
          <span class="lot-editor-photo-add-help">JPG, PNG of WebP · max. 16 MB</span>
          <input type="file" name="photos[]" form="lot-photo-upload-form" accept="image/jpeg,image/png,image/webp" aria-label="Foto selecteren">
        </label>
      </div>
      <?php endif; ?>
    </div>
    <?php if($canEditLot && count($images)<3): ?>
    <div class="lot-editor-photo-upload-actions">
      <p class="lot-editor-help" id="lot-photo-upload-status" role="status" aria-live="polite"></p>
    </div>
    <template id="lot-photo-picker-template">
      <div class="lot-editor-photo lot-editor-photo-add">
        <label class="lot-editor-photo-add-picker">
          <span class="lot-editor-upload-icon" aria-hidden="true">＋</span>
          <strong>Foto toevoegen</strong>
          <span class="lot-editor-photo-add-help">JPG, PNG of WebP · max. 16 MB</span>
          <input type="file" name="photos[]" form="lot-photo-upload-form" accept="image/jpeg,image/png,image/webp" aria-label="Foto selecteren">
        </label>
      </div>
    </template>
    <script src="<?=e(url('admin/lot-photo-preview.js', ['v'=>substr((string)hash_file('sha256', __DIR__ . '/lot-photo-preview.js'), 0, 12)]))?>" defer></script>
    <?php endif; ?>
  </section>
  <div class="lot-editor-bottom"><a class="secondary" href="<?=e(url('admin/index.php'))?>">Annuleren</a><?php if($canEditLot): ?><button form="lot-editor-form" class="primary" type="submit">Wijzigingen opslaan</button><?php endif; ?></div>
  <?php if($canEditLot): ?><details class="lot-editor-danger"><summary>Gevarenzone — kavel definitief verwijderen</summary><p>Dit verwijdert het kavel en alle gekoppelde foto's. Deze actie kan niet ongedaan gemaakt worden.</p><form method="post" onsubmit="return confirm('Dit kavel en alle foto\'s definitief verwijderen?')"><?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="delete-lot"><button class="danger" type="submit">Kavel definitief verwijderen</button></form></details><?php endif; ?>
  <?php endif; ?>
</div>
<script src="<?=e(url('admin/lot-unsaved-warning.js', ['v'=>substr((string)hash_file('sha256', __DIR__ . '/lot-unsaved-warning.js'), 0, 12)]))?>" defer></script>
<script>
(function () {
  'use strict';
  const form = document.getElementById('lot-editor-form');
  const title = form && form.elements.namedItem('title');
  const warning = document.getElementById('lot-title-error');
  if (!(title instanceof HTMLInputElement) || !warning) return;
  const message = 'De titel mag maximaal 50 tekens bevatten.';
  function validateTitle() {
    const tooLong = Array.from(title.value.trim()).length > 50;
    warning.hidden = !tooLong;
    title.setCustomValidity(tooLong ? message : '');
    title.setAttribute('aria-invalid', tooLong ? 'true' : 'false');
    return !tooLong;
  }
  title.addEventListener('input', validateTitle);
  form.addEventListener('submit', function (event) {
    if (!validateTitle()) {
      event.preventDefault();
      title.reportValidity();
      title.focus();
    }
  });
  validateTitle();
  const subtitle = form.elements.namedItem('subtitle');
  const subtitleWarning = document.getElementById('lot-subtitle-error');
  if (subtitle instanceof HTMLInputElement && subtitleWarning) {
    const subtitleMessage = 'De ondertitel mag maximaal 50 tekens bevatten.';
    function validateSubtitle() {
      const tooLong = Array.from(subtitle.value.trim()).length > 50;
      subtitleWarning.hidden = !tooLong;
      subtitle.setCustomValidity(tooLong ? subtitleMessage : '');
      subtitle.setAttribute('aria-invalid', tooLong ? 'true' : 'false');
      return !tooLong;
    }
    subtitle.addEventListener('input', validateSubtitle);
    form.addEventListener('submit', function (event) {
      if (!validateSubtitle()) {
        event.preventDefault();
        subtitle.reportValidity();
        subtitle.focus();
      }
    });
    validateSubtitle();
  }
  const artist = form.elements.namedItem('artist_name');
  const artistWarning = document.getElementById('lot-artist-error');
  if (artist instanceof HTMLInputElement && artistWarning) {
    const artistMessage = 'De naam van de kunstenaar mag maximaal 50 tekens bevatten.';
    function validateArtistName() {
      const tooLong = Array.from(artist.value.trim()).length > 50;
      artistWarning.hidden = !tooLong;
      artist.setCustomValidity(tooLong ? artistMessage : '');
      artist.setAttribute('aria-invalid', tooLong ? 'true' : 'false');
      return !tooLong;
    }
    artist.addEventListener('input', validateArtistName);
    form.addEventListener('submit', function (event) {
      if (!validateArtistName()) {
        event.preventDefault();
        artist.reportValidity();
        artist.focus();
      }
    });
    validateArtistName();
  }
})();
</script>
<script>
(function () {
  'use strict';
  const form = document.getElementById('lot-editor-form');
  if (!form) return;
  const name = form.elements.namedItem('artist_name');
  const link = form.elements.namedItem('artist_url');
  if (!(name instanceof HTMLInputElement) || !(link instanceof HTMLInputElement)) return;

  function normalizedArtistLink(value) {
    const trimmed = value.trim();
    // Only a domain beginning with www. gets an implicit HTTPS prefix.
    if (/^www\.[a-z0-9-]+(?:\.[a-z0-9-]+)+(?::[0-9]{1,5})?(?:[/?#][^\s]*)?$/i.test(trimmed)) {
      return 'https://' + trimmed;
    }
    return trimmed;
  }

  function validateArtistLink() {
    const value = normalizedArtistLink(link.value);
    let message = '';
    if (value && !name.value.trim()) {
      message = 'Vul eerst de naam van de kunstenaar in.';
    } else if (value) {
      try {
        const url = new URL(value);
        if (!/^https?:\/\//i.test(value) ||
            !['http:', 'https:'].includes(url.protocol) ||
            !url.hostname || url.username || url.password ||
            /[\s\x00-\x1f\x7f]/.test(value) ||
            value.length > 2048) {
          message = 'Gebruik een geldige hyperlink, bijvoorbeeld www.marf.be of https://www.marf.be.';
        }
      } catch (_) {
        message = 'Gebruik een geldige hyperlink, bijvoorbeeld www.marf.be of https://www.marf.be.';
      }
    }
    link.setCustomValidity(message);
    link.setAttribute('aria-invalid', message ? 'true' : 'false');
    return !message;
  }

  function completeArtistLink() {
    if (validateArtistLink() && link.value.trim()) {
      link.value = normalizedArtistLink(link.value);
    }
  }

  name.addEventListener('input', validateArtistLink);
  link.addEventListener('input', validateArtistLink);
  link.addEventListener('blur', function () {
    completeArtistLink();
    if (!validateArtistLink()) link.reportValidity();
  });
  form.addEventListener('submit', function (event) {
    completeArtistLink();
    if (!validateArtistLink()) {
      event.preventDefault();
      link.reportValidity();
      link.focus();
    }
  });
  validateArtistLink();
})();
</script>
<?php admin_bottom(); ?>
