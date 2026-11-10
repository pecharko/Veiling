<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/private/bootstrap.php';
require_once dirname(__DIR__,2).'/private/lot_categories.php';
require_once dirname(__DIR__,2).'/private/catalog_mutex.php';
require_auth();
if (!lot_category_can_manage(effective_admin_role())) {
  http_response_code(403);
  exit('Alleen Veilingmeester en Administrator kunnen categorieën beheren.');
}
$pdo=db();
$error='';
if (is_post()) {
  check_csrf();
  catalog_lock_for_request($pdo);
  try {
    $action=(string)($_POST['action']??'');
    $id=filter_var($_POST['category_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if ($action==='add') {
      lot_category_add($pdo,$_POST['name']??null);
    } elseif (($action==='rename' || $action==='delete') && $id!==false && $id!==null) {
      if ($action==='rename') lot_category_rename($pdo,(int)$id,$_POST['name']??null);
      else lot_category_delete($pdo,(int)$id);
    } else {
      throw new InvalidArgumentException('Ongeldige categorieactie.');
    }
    flash('Categorieën bijgewerkt.');
    redirect('admin/categorieen.php');
  } catch (InvalidArgumentException $ex) {
    $error=$ex->getMessage();
  } catch (PDOException $ex) {
    error_log('[Categoriebeheer] '.$ex->getMessage());
    $error='Bewaren mislukt. Controleer of de categorienaam al bestaat.';
  }
}
try {
  $categories=lot_category_list_with_usage($pdo);
} catch (PDOException $ex) {
  // Do not automatically migrate a database when merely viewing this page.
  error_log('[Categoriebeheer laden] '.$ex->getMessage());
  http_response_code(503);
  admin_top('Categorieën beheren');
  echo '<div class="alert warning" role="alert"><strong>Categoriebeheer is nog niet beschikbaar.</strong> De categoriegegevens ontbreken of zijn niet toegankelijk. In de geïsoleerde lokale preview: voer <code>sh scripts/preview start</code> uit om de testdatabase veilig bij te werken. Er wordt niets op productie gewijzigd.</div>';
  admin_bottom();
  exit;
}
admin_top('Categorieën beheren');
?>
<div class="card category-manager">
  <div class="category-manager-head"><div><h2>Categorieën</h2><p>Hernoemen past ook alle gekoppelde kavels aan. Verwijderen kan alleen wanneer de categorie niet gebruikt wordt.</p></div><a class="secondary" href="<?=e(url('admin/kavel.php'))?>">Terug naar kavels</a></div>
  <?php if ($error): ?><p class="alert error" role="alert"><?=e($error)?></p><?php endif; ?>
  <h3>Nieuwe categorie</h3>
  <form class="category-manager-add" method="post">
    <?=csrf_field()?><input type="hidden" name="action" value="add">
    <label>Naam<input name="name" type="text" maxlength="120" required placeholder="Naam van de categorie"></label>
    <button class="primary" type="submit">Toevoegen</button>
  </form>
  <h3>Bestaande categorieën</h3>
  <?php foreach($categories as $category): ?>
    <div class="category-manager-row">
      <form method="post" class="category-manager-add">
        <?=csrf_field()?><input type="hidden" name="action" value="rename"><input type="hidden" name="category_id" value="<?=(int)$category['id']?>">
        <label>Naam<input name="name" type="text" maxlength="120" required value="<?=e($category['name'])?>"></label>
        <button class="secondary" type="submit">Naam wijzigen</button>
      </form>
      <form method="post" class="category-manager-actions">
        <?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="category_id" value="<?=(int)$category['id']?>">
        <span><?= (int)$category['lot_count'] ?> kavel(s)</span>
        <button class="secondary" type="submit" <?=(int)$category['lot_count']>0?'disabled title="Categorie is in gebruik"':''?>>Verwijderen</button>
      </form>
    </div>
  <?php endforeach; ?>
  <?php if (!$categories): ?><p>Er zijn nog geen categorieën.</p><?php endif; ?>
  <p class="lot-editor-help">Keer terug naar het kavelvenster en vernieuw de pagina om nieuwe categorieën in de keuzelijst te zien.</p>
</div>
<?php admin_bottom(); ?>
