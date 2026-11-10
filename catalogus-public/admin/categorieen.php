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
<div class="category-manager">
  <div class="category-manager-toolbar">
    <div>
      <a class="lot-editor-back" href="<?=e(url('admin/index.php'))?>">← Terug naar kavels</a>
      <p class="category-manager-intro">Beheer de vaste categorieën voor de veilingcatalogus. Wijzigingen zijn meteen beschikbaar bij het bewerken van kavels.</p>
    </div>
    <div class="category-manager-total" aria-label="<?=count($categories)?> categorieën">
      <strong><?=count($categories)?></strong><span>Categorieën</span>
    </div>
  </div>
  <?php if ($error): ?><div class="alert error" role="alert"><?=e($error)?></div><?php endif; ?>
  <div class="category-manager-grid">
    <section class="lot-editor-card category-manager-card" aria-labelledby="category-add-heading">
      <div class="lot-editor-card-header">
        <span class="lot-editor-step" aria-hidden="true">01</span>
        <div><h2 id="category-add-heading">Categorie toevoegen</h2><p>Voeg een nieuwe categorie toe aan de keuzelijst voor kavels.</p></div>
      </div>
      <form class="category-manager-create-form" method="post">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="add">
        <label for="category-new-name">Naam categorie</label>
        <input id="category-new-name" name="name" type="text" maxlength="120" required placeholder="Bijvoorbeeld: Fotografie">
        <button class="primary" type="submit">Categorie toevoegen</button>
      </form>
    </section>
    <section class="lot-editor-card category-manager-card" aria-labelledby="category-list-heading">
      <div class="lot-editor-card-header">
        <span class="lot-editor-step" aria-hidden="true">02</span>
        <div><h2 id="category-list-heading">Bestaande categorieën</h2><p>Wijzig een naam of verwijder een ongebruikte categorie.</p></div>
      </div>
      <?php if (!$categories): ?>
        <p class="category-manager-empty">Er zijn nog geen categorieën. Voeg hiernaast je eerste categorie toe.</p>
      <?php else: ?>
        <div class="category-manager-list">
          <?php foreach ($categories as $category): ?>
            <?php $categoryUsed = (int)$category['lot_count'] > 0; ?>
            <div class="category-manager-item">
              <div class="category-manager-item-header">
                <strong class="category-manager-name"><?=e($category['name'])?></strong>
                <span class="category-manager-usage <?=$categoryUsed?'is-used':'is-unused'?>"><?= (int)$category['lot_count'] ?> kavel(s)</span>
              </div>
              <div class="category-manager-item-actions">
                <form method="post" class="category-manager-rename">
                  <?=csrf_field()?>
                  <input type="hidden" name="action" value="rename">
                  <input type="hidden" name="category_id" value="<?=(int)$category['id']?>">
                  <label for="category-name-<?=(int)$category['id']?>">Naam wijzigen</label>
                  <div class="category-manager-rename-controls">
                    <input id="category-name-<?=(int)$category['id']?>" name="name" type="text" maxlength="120" required value="<?=e($category['name'])?>">
                    <button class="secondary" type="submit">Opslaan</button>
                  </div>
                </form>
                <form method="post" class="category-manager-delete">
                  <?=csrf_field()?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="category_id" value="<?=(int)$category['id']?>">
                  <button class="secondary category-manager-delete-button" type="submit" <?=$categoryUsed?'disabled title="Categorie is in gebruik"':''?>>Verwijderen</button>
                </form>
              </div>
              <?php if ($categoryUsed): ?><p class="category-manager-item-note">Verwijderen is niet mogelijk zolang een kavel deze categorie gebruikt.</p><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </div>
  <p class="category-manager-footnote">Werk je nog in een ander tabblad aan een kavel? Vernieuw dat tabblad om nieuwe categorienamen in de keuzelijst te zien.</p>
</div>
<?php admin_bottom(); ?>
