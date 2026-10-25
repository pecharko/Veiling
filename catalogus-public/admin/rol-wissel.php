<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/private/bootstrap.php';
require_auth();
require_no_index();
if (stored_admin_role() !== 'administrator') {
  http_response_code(403);
  exit('Enkel de oorspronkelijke administrator kan van rol wisselen.');
}
if (is_post()) {
  check_csrf();
  $role = (string)($_POST['role'] ?? '');
  if (!in_array($role, ['administrator', 'assistant', 'veilingmeester'], true)) {
    http_response_code(400);
    exit('Ongeldige rol.');
  }
  $_SESSION['admin_view_role'] = $role;
  session_regenerate_id(true);
  flash('Actieve rol ingesteld op ' . $role . '. Deze keuze geldt alleen tijdens deze sessie.');
  redirect('admin/index.php');
}
admin_top('Rol wisselen');
?>
<section class="dashboard-panel">
  <h2>Tijdelijke rol voor deze sessie</h2>
  <p>Je account blijft Administrator. Je ziet de menu's en krijgt de bevoegdheden van de geselecteerde rol. Na uitloggen en opnieuw inloggen ben je weer Administrator.</p>
  <form method="post">
    <?=csrf_field()?>
    <label>Actieve rol
      <select name="role" required>
        <?php foreach (['administrator'=>'Administrator', 'assistant'=>'Assistent', 'veilingmeester'=>'Veilingmeester'] as $key=>$name): ?>
          <option value="<?=e($key)?>" <?=effective_admin_role()===$key?'selected':''?>><?=e($name)?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="primary" type="submit">Rol toepassen</button>
  </form>
</section>
<?php admin_bottom(); ?>
