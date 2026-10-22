<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/private/bootstrap.php';
require dirname(__DIR__, 2) . '/private/images.php';
require_once dirname(__DIR__, 2) . '/private/catalog_mutex.php';
require dirname(__DIR__, 2) . '/private/lot_form.php';
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
    $st=$pdo->prepare('SELECT path FROM lot_images WHERE lot_id=?');$st->execute([$id]);$paths=$st->fetchAll(PDO::FETCH_COLUMN);
    $pdo->prepare('DELETE FROM lots WHERE id=?')->execute([$id]);
    foreach($paths as $path)if(preg_match('~^uploads/[0-9]{4}/[0-9]{2}/[a-f0-9]{32}\.(webp|jpg|png)$~',$path))@unlink(dirname(__DIR__).'/'.$path);
    flash('Kavel verwijderd.');redirect('admin/index.php');
  }
  if($action==='save'){
    $nr=(int)($_POST['lot_number']??0);
    $title=mb_substr(trim((string)($_POST['title']??'')),0,250);
    $subtitle=mb_substr(trim((string)($_POST['subtitle']??'')),0,250);
    $category=mb_substr(trim((string)($_POST['category']??'Overige')),0,120);
    $description=mb_substr(trim((string)($_POST['description']??'')),0,30000);
    $details=mb_substr(trim((string)($_POST['details']??'')),0,10000);
    $min=trim((string)($_POST['estimate_min']??''));
    $max=trim((string)($_POST['estimate_max']??''));
    $status=($_POST['status']??'draft')==='published'?'published':'draft';
    if($nr<1 || $nr>999999)$error='Vul een geldig lotnummer in.';
    elseif($title==='')$error='Een titel is verplicht.';
    elseif($min!=='' && (!is_numeric($min)|| (float)$min<0))$error='Ongeldige minimumschatting.';
    elseif($max!=='' && (!is_numeric($max)|| (float)$max<0))$error='Ongeldige maximumschatting.';
    elseif($min!==''&&$max!==''&&(float)$max<(float)$min)$error='Maximumschatting mag niet lager zijn dan minimumschatting.';
    else{
      // Valideer alle uploadstatussen voordat er een kavel wordt aangemaakt of gewijzigd.
      try{
        $photos=photo_files($_FILES['photos']??[]);
      }catch(RuntimeException $ex){
        $error='Foto-upload mislukt; het kavel is niet opgeslagen: '.$ex->getMessage();
      }
      if(!$error){
        try{
          $values=[$nr,$title,$subtitle,$category,$description,$details,$min===''?null:$min,$max===''?null:$max,$status];
          if($id){$values[]=$id;$st=$pdo->prepare('UPDATE lots SET lot_number=?,title=?,subtitle=?,category=?,description=?,details=?,estimate_min=?,estimate_max=?,status=? WHERE id=?');}
          else{$st=$pdo->prepare('INSERT INTO lots (lot_number,title,subtitle,category,description,details,estimate_min,estimate_max,status) VALUES (?,?,?,?,?,?,?,?,?)');}
          $st->execute($values);
          if(!$id)$id=(int)$pdo->lastInsertId();
          $failed=[];
          foreach($photos as $i=>$photo){
            try{add_lot_image($id,$photo['tmp'],$photo['name'],100+$i);}
            catch(Throwable $ex){$failed[]=$photo['name'].': '.$ex->getMessage();}
          }
          flash($failed?'Kavel bewaard, maar sommige foto’s mislukten: '.implode('; ',$failed):"Kavel en foto's bewaard.", $failed?'warning':'success');
          redirect('admin/kavel.php',['id'=>$id]);
        }catch(PDOException $ex){
          if($ex->getCode()==='23000')$error='Dit lotnummer bestaat al. Kies een uniek nummer.';
          else{$error='Kon kavel niet opslaan. Controleer de invoer.';error_log($ex->getMessage());}
        }catch(Throwable $ex){$error='Er ging iets mis met het bewaren: '.$ex->getMessage();}
      }
    }
  }
}
if($id){$st=$pdo->prepare('SELECT * FROM lots WHERE id=?');$st->execute([$id]);$lot=$st->fetch();}
$images=[];if($lot){$st=$pdo->prepare('SELECT * FROM lot_images WHERE lot_id=? ORDER BY sort_order,id');$st->execute([$id]);$images=$st->fetchAll();}
$defaults=['lot_number'=>'','title'=>'','subtitle'=>'','category'=>'Overige','description'=>'','details'=>'','estimate_min'=>'','estimate_max'=>'','status'=>'draft'];
$submitted = ($error !== '' && is_post() && ($_POST['action'] ?? 'save') === 'save') ? $_POST : null;
$d=lot_form_values($defaults,$lot?:null,$submitted);
admin_top($lot?'Kavel '.str_pad((string)$lot['lot_number'],3,'0',STR_PAD_LEFT).' bewerken':'Nieuw kavel');
if($error)echo '<div class="alert error" role="alert">'.e($error).'</div>';
?>
<div class="lot-editor">
  <div class="lot-editor-toolbar">
    <div><a class="lot-editor-back" href="<?=e(url('admin/index.php'))?>">← Terug naar kavels</a><p class="lot-editor-hint">Beheer gegevens, waardering, publicatie en foto's op één plek.</p></div>
    <div class="lot-editor-toolbar-actions">
      <?php if($lot && $d['status']==='published'): ?><a class="secondary" href="<?=e(url('kavel.php',['nr'=>$d['lot_number']]))?>" target="_blank" rel="noopener">Bekijk online ↗</a><?php endif; ?>
      <button form="lot-editor-form" class="primary" type="submit">Kavel opslaan</button>
    </div>
  </div>
  <form id="lot-editor-form" method="post" enctype="multipart/form-data">
    <?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="save">
    <div class="lot-editor-layout">
      <div class="lot-editor-main">
        <section class="lot-editor-card"><div class="lot-editor-card-header"><span class="lot-editor-step">01</span><div><h2>Basisinformatie</h2><p>De belangrijkste gegevens die bezoekers in de catalogus zien.</p></div></div>
          <div class="lot-editor-fields lot-editor-two">
            <label>Lotnummer <span class="lot-required">*</span><input type="number" name="lot_number" min="1" max="999999" value="<?=e($d['lot_number'])?>" required></label>
            <label>Categorie<input type="text" name="category" value="<?=e($d['category'])?>" maxlength="120" placeholder="Kunst, lifestyle, vintage …"></label>
            <label class="lot-full">Titel <span class="lot-required">*</span><input type="text" name="title" value="<?=e($d['title'])?>" maxlength="250" required placeholder="Bijvoorbeeld: Champagnepakket"></label>
            <label class="lot-full">Ondertitel / maker / materiaal<input type="text" name="subtitle" value="<?=e($d['subtitle'])?>" maxlength="250" placeholder="Aanvullende informatie"></label>
          </div>
        </section>
        <section class="lot-editor-card"><div class="lot-editor-card-header"><span class="lot-editor-step">02</span><div><h2>Beschrijving</h2><p>Beschrijf het object zo helder mogelijk voor geïnteresseerde bezoekers.</p></div></div>
          <div class="lot-editor-fields"><label>Beschrijving<textarea name="description" rows="6" placeholder="Wat maakt dit kavel bijzonder?"><?=e($d['description'])?></textarea></label><label>Details, afmetingen en staat<textarea name="details" rows="4" placeholder="Afmetingen, technische informatie, eventuele gebruikssporen …"><?=e($d['details'])?></textarea></label></div>
        </section>
        <section class="lot-editor-card"><div class="lot-editor-card-header"><span class="lot-editor-step">03</span><div><h2>Waardebepaling</h2><p>Optionele schatting in euro, zichtbaar bij het kavel.</p></div></div>
          <div class="lot-editor-fields lot-editor-two"><label>Minimumschatting (€)<input type="number" min="0" step="0.01" name="estimate_min" value="<?=e($d['estimate_min'])?>" placeholder="0"></label><label>Maximumschatting (€)<input type="number" min="0" step="0.01" name="estimate_max" value="<?=e($d['estimate_max'])?>" placeholder="0"></label></div>
        </section>
      </div>
      <aside class="lot-editor-side">
        <section class="lot-editor-card"><div class="lot-editor-card-header"><div><h2>Publicatie</h2><p>Bepaal of bezoekers dit kavel mogen zien.</p></div></div>
          <div class="lot-editor-fields"><label>Status<select name="status"><option value="draft" <?=$d['status']==='draft'?'selected':''?>>Concept — niet zichtbaar</option><option value="published" <?=$d['status']==='published'?'selected':''?>>Gepubliceerd</option></select></label><p class="lot-editor-help">Een gepubliceerd kavel wordt pas zichtbaar als de veilingwebsite zelf openbaar staat.</p></div>
        </section>
        <section class="lot-editor-card"><div class="lot-editor-card-header"><div><h2>Foto's toevoegen</h2><p>Voeg meerdere beelden tegelijk toe.</p></div></div>
          <label class="lot-editor-uploader"><span class="lot-editor-upload-icon">＋</span><strong>Kies je foto's</strong><span>JPG, PNG of WebP · maximaal 16 MB en 48 MP per foto · automatische verkleining tot 1800 px · 16 per kavel</span><input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple></label>
          <p class="lot-editor-help">Foto's worden toegevoegd wanneer je het kavel opslaat.</p>
        </section>
        <?php if($lot): ?><section class="lot-editor-card"><h2>Kavelinformatie</h2><dl class="lot-editor-facts"><dt>Lotnummer</dt><dd><?=e(str_pad((string)$d['lot_number'],3,'0',STR_PAD_LEFT))?></dd><dt>Aantal foto's</dt><dd><?=count($images)?></dd><dt>Huidige status</dt><dd><?=($d['status']==='published'?'Gepubliceerd':'Concept')?></dd></dl></section><?php endif; ?>
      </aside>
    </div>
    <div class="lot-editor-bottom"><a class="secondary" href="<?=e(url('admin/index.php'))?>">Annuleren</a><button class="primary" type="submit">Wijzigingen opslaan →</button></div>
  </form>
  <?php if($lot): ?>
  <section class="lot-editor-card lot-editor-photos"><div class="lot-editor-card-header"><div><h2>Foto's bij dit kavel</h2><p>De foto met het laagste volgnummer verschijnt als hoofdfoto. Voeg extra foto’s hierboven toe en sla het kavel opnieuw op.</p></div></div>
    <div class="lot-editor-photo-grid"><?php foreach($images as $photo): ?><div class="lot-editor-photo"><img src="<?=e(url($photo['path']))?>" alt="Kavelfoto"><form method="post" onsubmit="return confirm('Deze foto verwijderen?')"><?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="remove-photo"><input type="hidden" name="photo_id" value="<?=(int)$photo['id']?>"><button class="lot-editor-remove-photo" type="submit">Foto verwijderen</button></form></div><?php endforeach; ?><?php if(!$images): ?><p class="lot-editor-empty">Nog geen foto's toegevoegd. Upload foto's via het paneel hierboven.</p><?php endif; ?></div>
  </section>
  <details class="lot-editor-danger"><summary>Gevarenzone — kavel definitief verwijderen</summary><p>Dit verwijdert het kavel en alle gekoppelde foto's. Deze actie kan niet ongedaan gemaakt worden.</p><form method="post" onsubmit="return confirm('Dit kavel en alle foto\'s definitief verwijderen?')"><?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="delete-lot"><button class="danger" type="submit">Kavel definitief verwijderen</button></form></details>
  <?php endif; ?>
</div>
<?php admin_bottom(); ?>
