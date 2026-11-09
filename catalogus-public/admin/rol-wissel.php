<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/private/bootstrap.php';
require_auth();
require_no_index();
$storedRole = stored_admin_role();
$allowedRoles = admin_role_switch_targets($storedRole);
if ($allowedRoles === []) {
  http_response_code(403);
  exit('Je account mag niet van rol wisselen.');
}
if (is_post()) {
  check_csrf();
  $role = (string)($_POST['role'] ?? '');
  if (!in_array($role, $allowedRoles, true)) {
    http_response_code(403);
    exit('Deze rol is niet toegestaan voor je account.');
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
  <p>Je oorspronkelijke rol blijft <?=e($storedRole)?>. De geselecteerde rol geldt alleen voor deze sessie; na opnieuw inloggen wordt je oorspronkelijke rol hersteld. Een Veilingmeester kan uitsluitend naar Assistent wisselen en terug.</p>
  <form method="post">
    <?=csrf_field()?>
    <label>Actieve rol
      <select name="role" required>
        <?php foreach ($allowedRoles as $key): $name = ['administrator'=>'Administrator', 'assistant'=>'Assistent', 'veilingmeester'=>'Veilingmeester'][$key]; ?>
          <option value="<?=e($key)?>" <?=effective_admin_role()===$key?'selected':''?>><?=e($name)?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="primary" type="submit">Rol toepassen</button>
  </form>
</section>
<?php admin_bottom(); ?>
