<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/private/bootstrap.php';
require_administrator();
require_no_index();

$pdo = db();
$error = '';
$currentId = (int)$_SESSION['admin_id'];

if (is_post()) {
    check_csrf();
    $action = (string)($_POST['action'] ?? '');
    $currentPassword = (string)($_POST['current_password'] ?? '');
    $self = $pdo->prepare('SELECT password_hash FROM admins WHERE id=?');
    $self->execute([$currentId]);
    $currentHash = $self->fetchColumn();

    if (!is_string($currentHash) || !password_verify($currentPassword, $currentHash)) {
        $error = 'Je huidige wachtwoord is niet correct.';
    } else {
        try {
            if ($action === 'create') {
                $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
                $password = (string)($_POST['new_password'] ?? '');
                if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
                    throw new InvalidArgumentException('Vul een geldig e-mailadres in.');
                }
                if (strlen($password) < 12 || strlen($password) > 1024) {
                    throw new InvalidArgumentException('Gebruik een wachtwoord van minimaal 12 tekens.');
                }
                $role = (string)($_POST['role'] ?? '');
                if (!in_array($role, ['administrator', 'assistant', 'veilingmeester'], true)) throw new InvalidArgumentException('Ongeldige rol.');
                $stmt = $pdo->prepare('INSERT INTO admins (email, password_hash, role) VALUES (?, ?, ?)');
                $stmt->execute([$email, password_hash($password, PASSWORD_DEFAULT), $role]);
                flash('Beheerder toegevoegd.');
            } elseif ($action === 'update') {
                $target = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
                $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
                $password = (string)($_POST['new_password'] ?? '');
                if (!$target || $target < 1 || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
                    throw new InvalidArgumentException('Ongeldige beheerder of e-mailadres.');
                }
                if ($password !== '' && (strlen($password) < 12 || strlen($password) > 1024)) {
                    throw new InvalidArgumentException('Een nieuw wachtwoord moet minimaal 12 tekens hebben.');
                }
                $role = (string)($_POST['role'] ?? '');
                if (!in_array($role, ['administrator', 'assistant', 'veilingmeester'], true)) throw new InvalidArgumentException('Ongeldige rol.');
                if ((int)$target === $currentId && $role !== 'administrator') throw new InvalidArgumentException('Je kunt je eigen Administrator-rol niet verlagen.');
                $pdo->beginTransaction();
                $locked = $pdo->query("SELECT id, role FROM admins ORDER BY id FOR UPDATE")->fetchAll();
                $targetRow = null;
                $adminCount = 0;
                foreach ($locked as $row) {
                    if ($row['role'] === 'administrator') $adminCount++;
                    if ((int)$row['id'] === (int)$target) $targetRow = $row;
                }
                if (!$targetRow) throw new InvalidArgumentException('Beheerder bestaat niet.');
                if ($targetRow['role'] === 'administrator' && $role !== 'administrator' && $adminCount <= 1) throw new InvalidArgumentException('Laatste Administrator mag niet worden gewijzigd naar een lagere rol.');
                if ($password === '') {
                    $stmt = $pdo->prepare('UPDATE admins SET email=?, role=? WHERE id=?');
                    $stmt->execute([$email, $role, $target]);
                } else {
                    $stmt = $pdo->prepare('UPDATE admins SET email=?, password_hash=?, role=? WHERE id=?');
                    $stmt->execute([$email, password_hash($password, PASSWORD_DEFAULT), $role, $target]);
                }
                if ($stmt->rowCount() === 0) {
                    $exists = $pdo->prepare('SELECT COUNT(*) FROM admins WHERE id=?');
                    $exists->execute([$target]);
                    if (!(int)$exists->fetchColumn()) throw new InvalidArgumentException('Beheerder bestaat niet.');
                }
                $pdo->commit();
                flash('Beheerder bijgewerkt. Een gewijzigd wachtwoord trekt bestaande sessies in.');
            } elseif ($action === 'delete') {
                $target = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$target || $target < 1 || $target === $currentId) {
                    throw new InvalidArgumentException('Je kunt je eigen account niet verwijderen.');
                }
                $pdo->beginTransaction();
                // Lock all accounts so concurrent requests cannot delete the final account.
                $all = $pdo->query('SELECT id, role FROM admins ORDER BY id FOR UPDATE')->fetchAll();
                if (count($all) < 2 || !in_array((int)$target, array_map(static fn($row) => (int)$row['id'], $all), true)) {
                    throw new InvalidArgumentException('Laatste beheerder of onbekende account kan niet worden verwijderd.');
                }
                $administrators = count(array_filter($all, static fn($row) => $row['role'] === 'administrator'));
                foreach ($all as $row) {
                    if ((int)$row['id'] === (int)$target && $row['role'] === 'administrator' && $administrators <= 1) {
                        throw new InvalidArgumentException('De laatste Administrator kan niet worden verwijderd.');
                    }
                }
                $stmt = $pdo->prepare('DELETE FROM admins WHERE id=?');
                $stmt->execute([$target]);
                $pdo->commit();
                flash('Beheerder verwijderd. Bestaande sessies van dit account zijn niet meer geldig.');
            } else {
                throw new InvalidArgumentException('Onbekende actie.');
            }
            redirect('admin/gebruikers.php');
        } catch (InvalidArgumentException $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $exception->getMessage();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Admin user management query failed: ' . $exception->getCode());
            $error = 'Opslaan mislukt. Controleer of het e-mailadres al bestaat.';
        }
    }
}
$users = $pdo->query('SELECT id,email,role FROM admins ORDER BY email')->fetchAll();
$selectedId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$selectedUser = null;
foreach ($users as $candidate) {
    if ((int)$candidate['id'] === $selectedId) { $selectedUser = $candidate; break; }
}

admin_top('Gebruikersbeheer');
?>
<div class="dashboard-modern">
  <p class="dashboard-subtitle">Beheer wie toegang heeft tot de adminconsole. Bevestig elke wijziging met je eigen wachtwoord. Administrators beheren alles. Assistenten bekijken de veilingorganisatie zonder deze te wijzigen. Veilingmeesters mogen ook de veilingvolgorde wijzigen.</p>
  <?php if ($error !== ''): ?><div class="alert error" role="alert"><?=e($error)?></div><?php endif; ?>
  <section class="dashboard-panel">
    <h2>Nieuwe beheerder</h2>
    <form method="post" autocomplete="off">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="create">
      <label>E-mailadres <input type="email" name="email" maxlength="190" required autocomplete="off"></label>
      <label>Rol <select name="role" required><option value="assistant">Assistent</option><option value="veilingmeester">Veilingmeester</option><option value="administrator">Administrator</option></select></label>
      <label>Nieuw wachtwoord (minimaal 12 tekens) <input type="password" name="new_password" minlength="12" required autocomplete="new-password"></label>
      <label>Jouw huidige wachtwoord <input type="password" name="current_password" required autocomplete="current-password"></label>
      <button class="primary" type="submit">Beheerder toevoegen</button>
    </form>
  </section>
  <section class="dashboard-panel">
    <h2>Bestaande beheerders</h2>
    <div class="dashboard-table-scroll">
      <table class="dashboard-table">
        <thead><tr><th scope="col">E-mailadres</th><th scope="col">Rol</th><th scope="col">Actie</th></tr></thead>
        <tbody>
        <?php foreach ($users as $user): ?>
          <tr>
            <td><strong><?=e($user['email'])?></strong></td>
            <td><?= e($user['role'] === 'administrator' ? 'Administrator' : ($user['role'] === 'veilingmeester' ? 'Veilingmeester' : 'Assistent')) ?><?= (int)$user['id'] === $currentId ? ' (jij)' : '' ?></td>
            <td><a class="dashboard-edit" href="<?=e(url('admin/gebruikers.php', ['id' => (int)$user['id']]))?>#beheerder-bewerken" <?= $selectedId === (int)$user['id'] ? 'aria-current="true"' : '' ?>>Selecteren →</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($selectedUser !== null): ?>
      <div id="beheerder-bewerken" style="border-top:1px solid #ddd;margin-top:24px;padding-top:24px">
        <h3>Beheerder aanpassen: <?=e($selectedUser['email'])?></h3>
        <form method="post" autocomplete="off">
          <?=csrf_field()?>
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="user_id" value="<?=e((string)$selectedUser['id'])?>">
          <label>E-mailadres <input type="email" name="email" maxlength="190" required value="<?=e($selectedUser['email'])?>"></label>
          <label>Rol <select name="role" required><option value="assistant" <?= $selectedUser['role'] === 'assistant' ? 'selected' : '' ?>>Assistent</option><option value="veilingmeester" <?= $selectedUser['role'] === 'veilingmeester' ? 'selected' : '' ?>>Veilingmeester</option><option value="administrator" <?= $selectedUser['role'] === 'administrator' ? 'selected' : '' ?>>Administrator</option></select></label>
          <label>Nieuw wachtwoord (leeg laten = ongewijzigd) <input type="password" name="new_password" minlength="12" autocomplete="new-password"></label>
          <label>Jouw huidige wachtwoord <input type="password" name="current_password" required autocomplete="current-password"></label>
          <button type="submit">Wijzigingen opslaan</button>
          <a style="margin-left:12px" href="<?=e(url('admin/gebruikers.php'))?>">Annuleren</a>
        </form>
        <?php if ((int)$selectedUser['id'] !== $currentId): ?>
          <form method="post" onsubmit="return confirm('Deze beheerder definitief verwijderen?');">
            <?=csrf_field()?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="user_id" value="<?=e((string)$selectedUser['id'])?>">
            <label>Jouw huidige wachtwoord ter bevestiging <input type="password" name="current_password" required autocomplete="current-password"></label>
            <button type="submit">Beheerder verwijderen</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </section>
</div>
<?php admin_bottom(); ?>
