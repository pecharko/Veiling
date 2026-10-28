<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/private/bootstrap.php';
require dirname(__DIR__, 2) . '/private/images.php';
require_once dirname(__DIR__, 2) . '/private/catalog_mutex.php';
require dirname(__DIR__, 2) . '/private/lot_form.php';
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
    if($nr<1 || $nr>999999)$error='Vul een geldig lotnummer in.';
    elseif(!in_array($saleLocation,['markt','veiling'],true))$error='Kies een geldige verkoopplaats.';
    elseif($title==='')$error='Een titel is verplicht.';
    elseif (lot_publication_conflict($status, $saleLocation, $auctionOnly)) $error='Dit kavel is uitsluitend voor de veiling en mag niet op Markt gepubliceerd worden. Publicatie afgebroken.';
    elseif($marketPrice!=='' && (!is_numeric($marketPrice)|| (float)$marketPrice<0))$error='Ongeldige marktprijs.';
    elseif($startingBid!=='' && (!is_numeric($startingBid)|| (float)$startingBid<0))$error='Ongeldig startbod.';
    else{
      // Valideer alle uploadstatussen voordat er een kavel wordt aangemaakt of gewijzigd.
      try{
        $photos=photo_files($_FILES['photos']??[]);
      }catch(RuntimeException $ex){
        $error='Foto-upload mislukt; het kavel is niet opgeslagen: '.$ex->getMessage();
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
          $values=[$nr,$title,$subtitle,$category,$description,$details,$marketPrice===''?null:$marketPrice,$startingBid===''?null:$startingBid,(int)$auctionOnly,$status,$saleLocation,$auctionNumber];
          if($id){$values[]=$id;$st=$pdo->prepare('UPDATE lots SET lot_number=?,title=?,subtitle=?,category=?,description=?,details=?,market_price=?,starting_bid=?,auction_only=?,status=?,sale_location=?,auction_number=? WHERE id=?');}
          else{$values[]=(int)$_SESSION['admin_id'];$st=$pdo->prepare('INSERT INTO lots (lot_number,title,subtitle,category,description,details,market_price,starting_bid,auction_only,status,sale_location,auction_number,created_by_admin_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');}
          $st->execute($values);
          if(!$id)$id=(int)$pdo->lastInsertId();
          if ($saleLocation === 'markt' && $wasAuction) compact_auction_numbers($pdo);
          $pdo->commit();
          $failed=[];
          foreach($photos as $i=>$photo){
            try{add_lot_image($id,$photo['tmp'],$photo['name'],100+$i);}
            catch(Throwable $ex){$failed[]=$photo['name'].': '.$ex->getMessage();}
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
        }catch(Throwable $ex){if ($pdo->inTransaction()) $pdo->rollBack();$error='Er ging iets mis met het bewaren: '.$ex->getMessage();}
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
$defaults=['lot_number'=>$suggestedNumber,'title'=>'','subtitle'=>'','category'=>'Overige','description'=>'','details'=>'','market_price'=>'','starting_bid'=>'','auction_only'=>0,'status'=>'draft','sale_location'=>'markt','auction_number'=>''];
$submitted = ($error !== '' && is_post() && ($_POST['action'] ?? 'save') === 'save') ? $_POST : null;
$d=lot_form_values($defaults,$lot?:null,$submitted);
if ($submitted !== null && $suggestedNumber !== '') $d['lot_number'] = (string)$suggestedNumber;
$canEditLot = !$lot || lot_can_edit(effective_admin_role(), (int)$_SESSION['admin_id'], $lot);
admin_top($lot?'Kavel '.str_pad((string)$lot['lot_number'],3,'0',STR_PAD_LEFT).' bewerken':'Nieuw kavel');
if (!$canEditLot) echo '<div class="alert warning" role="alert">Alleen de maker van een eigen conceptkavel, de Veilingmeester of de Administrator mag dit kavel wijzigen. Dit kavel is alleen-lezen.</div>';
if($error)echo '<div class="alert error" role="alert">'.e($error).'</div>';
?>
<div class="lot-editor">
  <div class="lot-editor-toolbar">
    <div><a class="lot-editor-back" href="<?=e(url('admin/index.php'))?>">← Terug naar kavels</a><p class="lot-editor-hint">Beheer gegevens, waardering, publicatie en foto's op één plek.</p></div>
    <div class="lot-editor-toolbar-actions">
      <?php if($lot && $d['status']==='published'): ?><a class="secondary" href="<?=e(url('kavel.php',['nr'=>$d['lot_number']]))?>" target="_blank" rel="noopener">Bekijk online ↗</a><?php endif; ?>
      <?php if($canEditLot): ?><button form="lot-editor-form" class="primary" type="submit">Kavel opslaan</button><?php endif; ?>
    </div>
  </div>
  <form id="lot-editor-form" method="post" enctype="multipart/form-data">
    <?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="save">
    <fieldset <?=!$canEditLot?'disabled':''?> style="border:0;padding:0;margin:0;min-width:0">
    <div class="lot-editor-layout">
      <div class="lot-editor-main">
        <section class="lot-editor-card"><div class="lot-editor-card-header"><span class="lot-editor-step">01</span><div><h2>Basisinformatie</h2><p>De belangrijkste gegevens die bezoekers in de catalogus zien.</p></div></div>
          <div class="lot-editor-fields lot-editor-two">
            <label>Lotnummer <span class="lot-required">*</span><input type="number" name="lot_number" min="1" max="999999" value="<?=e($d['lot_number'])?>" required></label>
            <label>Verkoopplaats <select name="sale_location"><option value="markt" <?=$d['sale_location']==='markt'?'selected':''?>>Markt</option><option value="veiling" <?=$d['sale_location']==='veiling'?'selected':''?>>Veiling</option></select></label>
            <label class="lot-full"><input type="checkbox" name="auction_only" value="1" <?=!empty($d['auction_only'])?'checked':''?>> Uitsluitend veiling (niet op Markt publiceren)</label>
            <label>Categorie<input type="text" name="category" value="<?=e($d['category'])?>" maxlength="120" placeholder="Kunst, lifestyle, vintage …"></label>
            <label class="lot-full">Titel <span class="lot-required">*</span><input type="text" name="title" value="<?=e($d['title'])?>" maxlength="250" required placeholder="Bijvoorbeeld: Champagnepakket"></label>
            <label class="lot-full">Ondertitel / maker / materiaal<input type="text" name="subtitle" value="<?=e($d['subtitle'])?>" maxlength="250" placeholder="Aanvullende informatie"></label>
          </div>
        </section>
        <section class="lot-editor-card"><div class="lot-editor-card-header"><span class="lot-editor-step">02</span><div><h2>Beschrijving</h2><p>Beschrijf het object zo helder mogelijk voor geïnteresseerde bezoekers.</p></div></div>
          <div class="lot-editor-fields"><label>Beschrijving<textarea name="description" rows="6" placeholder="Wat maakt dit kavel bijzonder?"><?=e($d['description'])?></textarea></label><label>Details, afmetingen en staat<textarea name="details" rows="4" placeholder="Afmetingen, technische informatie, eventuele gebruikssporen …"><?=e($d['details'])?></textarea></label></div>
        </section>
        <section class="lot-editor-card"><div class="lot-editor-card-header"><span class="lot-editor-step">03</span><div><h2>Waardebepaling</h2><p>Marktprijs en startbod in euro.</p></div></div>
          <div class="lot-editor-fields lot-editor-two"><label>Marktprijs (€)<input type="number" min="0" step="0.01" name="market_price" value="<?=e($d['market_price'])?>" placeholder="0"></label><label>Startbod (€)<input type="number" min="0" step="0.01" name="starting_bid" value="<?=e($d['starting_bid'])?>" placeholder="0"></label></div>
        </section>
      </div>
      <aside class="lot-editor-side">
        <section class="lot-editor-card"><div class="lot-editor-card-header"><div><h2>Publicatie</h2><p>Bepaal of bezoekers dit kavel mogen zien.</p></div></div>
          <div class="lot-editor-fields"><?php if(lot_can_change_publication(effective_admin_role()) && $canEditLot): ?><label>Status<select name="status"><option value="draft" <?=$d['status']==='draft'?'selected':''?>>Concept — niet zichtbaar</option><option value="published" <?=$d['status']==='published'?'selected':''?>>Gepubliceerd</option><option value="sold" <?=$d['status']==='sold'?'selected':''?>>Verkocht</option><option value="canceled" <?=$d['status']==='canceled'?'selected':''?>>Canceled</option></select></label><?php else: ?><p class="lot-editor-help"><strong>Status:</strong> <?=(['draft'=>'Concept — niet zichtbaar','published'=>'Gepubliceerd','sold'=>'Verkocht','canceled'=>'Canceled'][$d['status']] ?? 'Onbekend')?>. Alleen Veilingmeester en Administrator mogen publicatie wijzigen.</p><?php endif; ?><p class="lot-editor-help">Een gepubliceerd kavel wordt pas zichtbaar als de veilingwebsite zelf openbaar staat.</p></div>
        </section>
        <section class="lot-editor-card"><div class="lot-editor-card-header"><div><h2>Foto's toevoegen</h2><p>Voeg meerdere beelden tegelijk toe.</p></div></div>
          <label class="lot-editor-uploader"><span class="lot-editor-upload-icon">＋</span><strong>Kies je foto's</strong><span>JPG, PNG of WebP · maximaal 16 MB en 48 MP per foto · automatische verkleining tot 1800 px · 16 per kavel</span><input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple></label>
          <p class="lot-editor-help">Foto's worden toegevoegd wanneer je het kavel opslaat.</p>
        </section>
        <?php if($lot): ?><section class="lot-editor-card"><h2>Kavelinformatie</h2><dl class="lot-editor-facts"><dt>Lotnummer</dt><dd><?=e(str_pad((string)$d['lot_number'],3,'0',STR_PAD_LEFT))?></dd><dt>Verkoopplaats</dt><dd><?=e($d['sale_location'])?></dd><?php if($d['sale_location']==='veiling'): ?><dt>Veilingsnummer</dt><dd><?=e((string)($d['auction_number'] ?: 'Automatisch bij opslaan'))?></dd><?php endif; ?><dt>Aantal foto's</dt><dd><?=count($images)?></dd><dt>Aangemaakt door</dt><dd><?=e($creatorLabel)?></dd><dt>Huidige status</dt><dd><?=e(['draft'=>'Concept','published'=>'Gepubliceerd','sold'=>'Verkocht','canceled'=>'Canceled'][$d['status']] ?? 'Onbekend')?></dd></dl></section><?php endif; ?>
      </aside>
    </div>
    </fieldset>
    <div class="lot-editor-bottom"><a class="secondary" href="<?=e(url('admin/index.php'))?>">Annuleren</a><?php if($canEditLot): ?><button class="primary" type="submit">Wijzigingen opslaan →</button><?php endif; ?></div>
  </form>
  <?php if($lot): ?>
  <section class="lot-editor-card lot-editor-photos"><div class="lot-editor-card-header"><div><h2>Foto's bij dit kavel</h2><p>De foto met het laagste volgnummer verschijnt als hoofdfoto. Voeg extra foto’s hierboven toe en sla het kavel opnieuw op.</p></div></div>
    <div class="lot-editor-photo-grid"><?php foreach($images as $photo): ?><div class="lot-editor-photo"><img src="<?=e(url($photo['path']))?>" alt="Kavelfoto"><?php if($canEditLot): ?><form method="post" onsubmit="return confirm('Deze foto verwijderen?')"><?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="remove-photo"><input type="hidden" name="photo_id" value="<?=(int)$photo['id']?>"><button class="lot-editor-remove-photo" type="submit">Foto verwijderen</button></form><?php endif; ?></div><?php endforeach; ?><?php if(!$images): ?><p class="lot-editor-empty">Nog geen foto's toegevoegd. Upload foto's via het paneel hierboven.</p><?php endif; ?></div>
  </section>
  <?php if($canEditLot): ?><details class="lot-editor-danger"><summary>Gevarenzone — kavel definitief verwijderen</summary><p>Dit verwijdert het kavel en alle gekoppelde foto's. Deze actie kan niet ongedaan gemaakt worden.</p><form method="post" onsubmit="return confirm('Dit kavel en alle foto\'s definitief verwijderen?')"><?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="delete-lot"><button class="danger" type="submit">Kavel definitief verwijderen</button></form></details><?php endif; ?>
  <?php endif; ?>
</div>
<?php admin_bottom(); ?>
