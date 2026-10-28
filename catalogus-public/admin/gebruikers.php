<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/private/bootstrap.php';
require_administrator();
require_no_index();
require_once dirname(__DIR__, 2) . '/private/admin_names.php';
require_once dirname(__DIR__, 2) . '/private/admin_invitations.php';

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
                $name = admin_validated_name((string)($_POST['name'] ?? ''));
                $email = invitation_email((string)($_POST['email'] ?? ''));
                $role = invitation_role((string)($_POST['role'] ?? ''));
                if (!invitation_issue($pdo, $name, $email, $role, $currentId)) {
                    throw new InvalidArgumentException('Uitnodiging kon niet worden verstuurd. Controleer de mailconfiguratie.');
                }
                flash('Uitnodiging verstuurd. Account actief na het instellen van een wachtwoord.');
            } elseif ($action === 'revoke-invitation') {
                $inviteId = filter_var($_POST['invitation_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$inviteId || !invitation_revoke($pdo, (int)$inviteId)) {
                    throw new InvalidArgumentException('Uitnodiging is niet meer openstaand.');
                }
                flash('Uitnodiging ingetrokken.');
            } elseif ($action === 'update') {
                $name = admin_validated_name((string)($_POST['name'] ?? ''));
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
                $locked = $pdo->query("SELECT id, role, is_active FROM admins ORDER BY id FOR UPDATE")->fetchAll();
                $targetRow = null;
                $adminCount = 0;
                foreach ($locked as $row) {
                    if ($row['role'] === 'administrator') $adminCount++;
                    if ((int)$row['id'] === (int)$target) $targetRow = $row;
                }
                if (!$targetRow) throw new InvalidArgumentException('Beheerder bestaat niet.');
                if ($role === 'administrator' && (int)$targetRow['is_active'] !== 1) throw new InvalidArgumentException('Activeer het account voordat je de Administrator-rol toekent.');
                if ($targetRow['role'] === 'administrator' && $role !== 'administrator' && $adminCount <= 1) throw new InvalidArgumentException('Laatste Administrator mag niet worden gewijzigd naar een lagere rol.');
                if ($password === '') {
                    $stmt = $pdo->prepare('UPDATE admins SET name=?, email=?, role=? WHERE id=?');
                    $stmt->execute([$name, $email, $role, $target]);
                } else {
                    $stmt = $pdo->prepare('UPDATE admins SET name=?, email=?, password_hash=?, role=? WHERE id=?');
                    $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $target]);
                }
                if ($stmt->rowCount() === 0) {
                    $exists = $pdo->prepare('SELECT COUNT(*) FROM admins WHERE id=?');
                    $exists->execute([$target]);
                    if (!(int)$exists->fetchColumn()) throw new InvalidArgumentException('Beheerder bestaat niet.');
                }
                $pdo->commit();
                flash('Beheerder bijgewerkt. Een gewijzigd wachtwoord trekt bestaande sessies in.');
            } elseif ($action === 'toggle-active') {
                $target = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
                $activate = (string)($_POST['activate'] ?? '');
                if (!$target || $target < 1 || !in_array($activate, ['0', '1'], true)) throw new InvalidArgumentException('Ongeldige accountactie.');
                $pdo->beginTransaction();
                $st = $pdo->prepare('SELECT role FROM admins WHERE id=? FOR UPDATE');
                $st->execute([$target]);
                $role = $st->fetchColumn();
                if (!$role) throw new InvalidArgumentException('Account bestaat niet.');
                if ($role === 'administrator') throw new InvalidArgumentException('Administratoraccounts kunnen niet gedeactiveerd worden.');
                if ((int)$target === $currentId && $activate === '0') throw new InvalidArgumentException('Je kunt je eigen account niet deactiveren.');
                $st = $pdo->prepare('UPDATE admins SET is_active=? WHERE id=? AND role<>?');
                $st->execute([(int)$activate, $target, 'administrator']);
                $pdo->commit();
                flash($activate === '1' ? 'Account geactiveerd.' : 'Account gedeactiveerd. Bestaande sessies zijn onmiddellijk ongeldig.');
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
$users = $pdo->query('SELECT id,name,email,role,is_active,last_login_at FROM admins ORDER BY name,email')->fetchAll();
$invitations = $pdo->query('SELECT id,name,email,role,created_at,expires_at,accepted_at,revoked_at FROM admin_invitations ORDER BY created_at DESC')->fetchAll();
$pendingCount = count(array_filter($invitations, static fn($i) => !$i['accepted_at'] && !$i['revoked_at'] && strtotime($i['expires_at'] . ' UTC') > time()));
$selectedId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$selectedUser = null;
foreach ($users as $candidate) {
    if ((int)$candidate['id'] === $selectedId) { $selectedUser = $candidate; break; }
}

admin_top('Gebruikersbeheer');
$activeCount = count(array_filter($users, static fn($u) => (int)$u['is_active'] === 1));
$adminCount = count(array_filter($users, static fn($u) => $u['role'] === 'administrator'));
$roleName = static fn(string $role): string => $role === 'administrator' ? 'Administrator' : ($role === 'veilingmeester' ? 'Veilingmeester' : 'Assistent');
?>
<style>
/* Isolated user-management redesign; public site and global admin CSS unchanged. */
.user-admin{--ua-border:#e8ded7;--ua-muted:#718077;--ua-ink:#29473e;--ua-wine:#850015;color:var(--ua-ink)}
.user-admin *{box-sizing:border-box}
.user-admin .ua-intro{color:var(--ua-muted);margin:0 0 23px;max-width:850px;font-size:14px}
.user-admin .ua-toolbar{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:9px}
.user-admin .ua-eyebrow{font-size:11px;text-transform:uppercase;letter-spacing:.13em;font-weight:850;color:var(--ua-wine);margin:0 0 6px}
.user-admin .ua-button{border:1px solid var(--ua-wine);background:var(--ua-wine);color:#fff;border-radius:9px;padding:11px 17px;min-height:44px;font-weight:750;cursor:pointer}
.user-admin .ua-button:hover{background:#650010;color:#fff}
.user-admin .ua-button.light{background:#fff;color:var(--ua-wine);border-color:var(--ua-border)}
.user-admin .ua-button.danger{background:#fff;color:#a32031;border-color:#e7bfc5}
.user-admin .ua-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin:22px 0}
.user-admin .ua-stat{display:flex;align-items:center;gap:13px;background:#fff;border:1px solid var(--ua-border);border-radius:13px;padding:18px;min-width:0}
.user-admin .ua-stat-icon{display:grid;place-items:center;border-radius:11px;background:#f9ebeb;color:var(--ua-wine);width:44px;height:44px;font-size:19px;flex-shrink:0}
.user-admin .ua-stat:nth-child(2) .ua-stat-icon{background:#e9f5ec;color:#286846}
.user-admin .ua-stat:nth-child(3) .ua-stat-icon{background:#f4f1ed;color:#73736c}
.user-admin .ua-stat b{display:block;font-size:25px;line-height:1.2}
.user-admin .ua-stat small{display:block;font-size:12px;white-space:nowrap;color:var(--ua-muted)}
.user-admin .ua-grid{display:grid;grid-template-columns:minmax(0,1.08fr) minmax(0,1fr);gap:20px;align-items:start}
.user-admin .ua-panel{border:1px solid var(--ua-border);background:#fff;border-radius:15px;box-shadow:0 8px 25px rgba(78,32,35,.035);overflow:hidden;min-width:0}
.user-admin .ua-panel-head{padding:22px 23px 17px;border-bottom:1px solid var(--ua-border)}
.user-admin .ua-panel-head h2{margin:0 0 5px;font-size:21px;color:var(--ua-ink)}
.user-admin .ua-panel-head p{font-size:13px;color:var(--ua-muted);margin:0}
.user-admin .ua-filter{display:flex;gap:10px;margin-top:17px}
.user-admin .ua-filter input,.user-admin .ua-filter select{width:100%;min-width:0;border-radius:9px}
.user-admin .ua-filter select{max-width:165px}
.user-admin .ua-user-list{display:block}
.user-admin .ua-user{display:flex;align-items:center;gap:12px;padding:16px 20px;border-bottom:1px solid #f0e9e5;color:inherit;text-decoration:none}
.user-admin .ua-user[hidden]{display:none}
.user-admin .ua-user:hover,.user-admin .ua-user.selected{background:#fcf5f1;text-decoration:none}
.user-admin .ua-user.selected{box-shadow:inset 4px 0 var(--ua-wine)}
.user-admin .ua-avatar{display:grid;place-items:center;background:#f5e6e1;border-radius:11px;width:43px;height:43px;flex-shrink:0;color:var(--ua-wine);font-weight:850}
.user-admin .ua-user-info{min-width:0;flex:1}
.user-admin .ua-user-info strong{display:block;overflow-wrap:anywhere;font-size:13px}
.user-admin .ua-user-info small{font-size:12px;color:var(--ua-muted)}
.user-admin .ua-status{display:inline-flex;align-items:center;gap:5px;border-radius:999px;background:#eaf6ee;color:#286846;padding:5px 10px;font-size:11px;font-weight:800;white-space:nowrap}
.user-admin .ua-status.off{background:#f3ecec;color:#a32031}
.user-admin .ua-status.admin{background:#f9edf1;color:#850015}
.user-admin .ua-chev{color:#9c8d87}
.user-admin .ua-list-note{padding:14px 20px;color:var(--ua-muted);font-size:12px}
.user-admin .ua-detail-top{display:flex;align-items:center;gap:13px;padding:23px;border-bottom:1px solid var(--ua-border)}
.user-admin .ua-detail-top h2{font-size:19px;overflow-wrap:anywhere;color:var(--ua-ink);margin:0 0 5px}
.user-admin .ua-detail-body{padding:22px 23px}
.user-admin .ua-detail-body h3{font-size:16px;color:var(--ua-ink);margin:0 0 8px}
.user-admin .ua-hint{font-size:12px;color:var(--ua-muted);margin:0 0 18px}
.user-admin .ua-form{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.user-admin .ua-form label,.user-admin .ua-security-form label{display:flex;flex-direction:column;gap:6px;font-size:12px;font-weight:750}
.user-admin .ua-form input,.user-admin .ua-form select,.user-admin .ua-security-form input{width:100%;min-width:0;max-width:none}
.user-admin .ua-wide{grid-column:1/-1}
.user-admin .ua-actions{display:flex;align-items:center;flex-wrap:wrap;gap:10px}
.user-admin .ua-security{display:flex;gap:10px;border:1px solid #dce7de;border-radius:10px;background:#f4faf6;padding:13px 15px;margin:3px 0}
.user-admin .ua-security strong{font-size:12px}
.user-admin .ua-security p{font-size:12px;color:var(--ua-muted);margin:3px 0 0}
.user-admin .ua-tabs{display:flex;gap:20px;padding:0 23px;border-bottom:1px solid var(--ua-border)}
.user-admin .ua-tabs a{padding:14px 0;font-size:13px;font-weight:750;color:var(--ua-muted);border-bottom:3px solid transparent}
.user-admin .ua-tabs a.current{color:var(--ua-wine);border-color:var(--ua-wine)}
.user-admin .ua-access-card{display:flex;justify-content:space-between;gap:10px;align-items:center;border:1px solid var(--ua-border);background:#fffcfa;padding:16px;border-radius:10px;margin-bottom:16px}
.user-admin .ua-access-card p{color:var(--ua-muted);font-size:12px;margin:5px 0 0}
.user-admin .ua-security-form{display:grid;gap:12px;margin-top:15px}
.user-admin .ua-danger-zone{margin-top:24px;padding-top:20px;border-top:1px solid var(--ua-border)}
.user-admin dialog{border:1px solid var(--ua-border);border-radius:15px;width:min(94vw,510px);padding:25px;box-shadow:0 24px 70px #38181b30;color:var(--ua-ink)}
.user-admin dialog::backdrop{background:rgba(30,32,32,.53)}
.user-admin dialog h2{margin:0 0 8px}
.user-admin dialog .ua-form{margin:18px 0}
.user-admin .ua-placeholder{text-align:center;padding:60px 24px;color:var(--ua-muted)}
.user-admin .ua-empty{display:none;padding:22px;color:var(--ua-muted);font-size:13px}
@media(max-width:1040px){.user-admin .ua-grid{grid-template-columns:1fr}.user-admin .ua-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:580px){.user-admin .ua-stats{gap:9px}.user-admin .ua-stat{padding:12px;gap:8px}.user-admin .ua-stat-icon{width:35px;height:35px}.user-admin .ua-stat small{white-space:normal}.user-admin .ua-filter,.user-admin .ua-form{grid-template-columns:1fr;display:grid}.user-admin .ua-filter select{max-width:none}.user-admin .ua-wide{grid-column:auto}.user-admin .ua-user{padding:13px}.user-admin .ua-user-info strong{font-size:12px}.user-admin .ua-status{padding:4px 7px}}
</style>
<div class="dashboard-modern user-admin">
  <div class="ua-toolbar"><div><p class="ua-eyebrow">Administratie / Toegangsbeheer</p><p class="ua-intro">Beheer toegang, rollen en accountstatus. Elke wijziging vereist je huidige wachtwoord.</p></div><button class="ua-button" type="button" id="ua-open-new">+ Nieuwe gebruiker</button></div>
  <?php if ($error !== ''): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <div class="ua-stats">
    <div class="ua-stat"><span class="ua-stat-icon">♙</span><span><b><?= count($users) ?></b><small>Totaal gebruikers</small></span></div>
    <div class="ua-stat"><span class="ua-stat-icon">✓</span><span><b><?= $activeCount ?></b><small>Actieve accounts</small></span></div>
    <div class="ua-stat"><span class="ua-stat-icon">◷</span><span><b><?= count($users)-$activeCount ?></b><small>Gedeactiveerd</small></span></div>
    <div class="ua-stat"><span class="ua-stat-icon">✦</span><span><b><?= $adminCount ?></b><small>Administrators</small></span></div>
  </div>
  <div class="ua-grid">
    <section class="ua-panel" aria-label="Gebruikersoverzicht">
      <div class="ua-panel-head"><h2>Alle gebruikers</h2><p>Zoek of filter gebruikers en selecteer een account om te beheren.</p>
        <div class="ua-filter"><input id="ua-search" type="search" placeholder="Zoek op naam of e-mailadres…" aria-label="Zoek gebruikers"><select id="ua-filter" aria-label="Filter op status"><option value="all">Alle accounts</option><option value="active">Actief</option><option value="off">Gedeactiveerd</option><option value="administrator">Administrators</option></select></div>
      </div>
      <div class="ua-user-list" id="ua-list">
        <?php foreach ($users as $user): $id = (int)$user['id']; $isAdmin = $user['role'] === 'administrator'; $active = (int)$user['is_active'] === 1; ?>
          <a class="ua-user<?= $selectedId === $id ? ' selected' : '' ?>" data-search="<?= e(mb_strtolower(admin_name_label($user['name']) . ' ' . $user['email'])) ?>" data-status="<?= $isAdmin ? 'administrator' : ($active ? 'active' : 'off') ?>" href="<?= e(url('admin/gebruikers.php', ['id'=>$id])) ?>#ua-selected" <?= $selectedId === $id ? 'aria-current="true"' : '' ?>>
            <span class="ua-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr(admin_name_label($user['name']),0,2))) ?></span>
            <span class="ua-user-info"><strong><?= e(admin_name_label($user['name'])) ?></strong><small><?= e($user['email']) ?> · <?= e($roleName($user['role'])) ?><?= $id === $currentId ? ' · Jij' : '' ?></small><small style="display:block">Laatste login: <?= e(invitation_brussels($user['last_login_at'])) ?> (Brussel)</small></span>
            <span class="ua-status<?= !$active ? ' off' : ($isAdmin ? ' admin' : '') ?>"><?= $isAdmin ? 'Altijd actief' : ($active ? 'Actief' : 'Inactief') ?></span><span class="ua-chev">›</span>
          </a>
        <?php endforeach; ?>
      </div>
      <p class="ua-empty" id="ua-empty">Geen gebruikers gevonden.</p>
      <div class="ua-list-note">Administratoraccounts kunnen niet gedeactiveerd worden.</div>
    </section>
    <section class="ua-panel" id="ua-selected" aria-label="Accountgegevens">
      <?php if ($selectedUser === null): ?>
        <div class="ua-placeholder"><h2>Selecteer een gebruiker</h2><p>Kies links een account om de gegevens of toegang te beheren.</p></div>
      <?php else: $selectedAdmin = $selectedUser['role'] === 'administrator'; $selectedActive = (int)$selectedUser['is_active'] === 1; $accessTab = ($_GET['tab'] ?? '') === 'access'; ?>
        <div class="ua-detail-top"><span class="ua-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr(admin_name_label($selectedUser['name']),0,2))) ?></span><div><h2><?= e(admin_name_label($selectedUser['name'])) ?></h2><small><?= e($selectedUser['email']) ?></small> <span class="ua-status<?= !$selectedActive ? ' off' : ($selectedAdmin ? ' admin' : '') ?>"><?= $selectedAdmin ? 'Altijd actief' : ($selectedActive ? 'Actief' : 'Inactief') ?></span> <small><?= e($roleName($selectedUser['role'])) ?><?= (int)$selectedUser['id'] === $currentId ? ' · Jij' : '' ?></small></div></div>
        <nav class="ua-tabs" aria-label="Accountinstellingen">
          <a class="<?= !$accessTab ? 'current' : '' ?>" href="<?= e(url('admin/gebruikers.php',['id'=>(int)$selectedUser['id']])) ?>#ua-selected">Gegevens &amp; rol</a>
          <a class="<?= $accessTab ? 'current' : '' ?>" href="<?= e(url('admin/gebruikers.php',['id'=>(int)$selectedUser['id'],'tab'=>'access'])) ?>#ua-selected">Toegang &amp; beveiliging</a>
        </nav>
        <div class="ua-detail-body">
        <?php if (!$accessTab): ?>
          <h3>Accountgegevens</h3><p class="ua-hint">Laatste login: <?= e(invitation_brussels($selectedUser["last_login_at"])) ?> (Brussel)</p><p class="ua-hint">Pas naam, e-mailadres, rol en eventueel het wachtwoord aan.</p>
          <form method="post" class="ua-form" autocomplete="off">
            <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="user_id" value="<?= (int)$selectedUser['id'] ?>">
            <label class="ua-wide">Naam<input type="text" name="name" maxlength="120" required autocomplete="name" value="<?= e($selectedUser['name']) ?>" placeholder="Voor- en achternaam"></label>
            <label class="ua-wide">E-mailadres<input type="email" name="email" maxlength="190" required value="<?= e($selectedUser['email']) ?>"></label>
            <label>Rol<select name="role" required><option value="assistant" <?= $selectedUser['role']==='assistant' ? 'selected' : '' ?>>Assistent</option><option value="veilingmeester" <?= $selectedUser['role']==='veilingmeester' ? 'selected' : '' ?>>Veilingmeester</option><option value="administrator" <?= $selectedUser['role']==='administrator' ? 'selected' : '' ?>>Administrator</option></select></label>
            <label>Nieuw wachtwoord<input type="password" name="new_password" minlength="12" autocomplete="new-password" placeholder="Leeg = ongewijzigd"></label>
            <div class="ua-wide ua-security"><span>⌑</span><div><strong>Beveiligde wijziging</strong><p>Bevestig de aanpassing met jouw huidige wachtwoord.</p></div></div>
            <label class="ua-wide">Jouw huidige wachtwoord<input type="password" name="current_password" required autocomplete="current-password"></label>
            <div class="ua-wide ua-actions"><button class="ua-button" type="submit">Wijzigingen opslaan</button><a class="ua-button light" href="<?= e(url('admin/gebruikers.php')) ?>">Annuleren</a></div>
          </form>
        <?php else: ?>
          <h3>Accounttoegang</h3><p class="ua-hint">Bepaal of deze gebruiker toegang heeft tot de adminomgeving.</p>
          <div class="ua-access-card"><div><strong><?= $selectedAdmin ? 'Administrator-account' : 'Accountstatus' ?></strong><p><?= $selectedAdmin ? 'Administrators blijven altijd actief.' : ($selectedActive ? 'Dit account kan momenteel inloggen.' : 'Dit account kan niet inloggen.') ?></p></div><span class="ua-status<?= !$selectedActive ? ' off' : '' ?>"><?= $selectedActive ? 'Actief' : 'Inactief' ?></span></div>
          <?php if (!$selectedAdmin): ?>
            <form method="post" class="ua-security-form" autocomplete="off">
              <?= csrf_field() ?><input type="hidden" name="action" value="toggle-active"><input type="hidden" name="user_id" value="<?= (int)$selectedUser['id'] ?>"><input type="hidden" name="activate" value="<?= $selectedActive ? '0' : '1' ?>">
              <label>Jouw huidige wachtwoord<input type="password" name="current_password" required autocomplete="current-password"></label>
              <div><button type="submit" class="ua-button<?= $selectedActive ? ' danger' : '' ?>"><?= $selectedActive ? 'Account deactiveren' : 'Account activeren' ?></button></div>
            </form>
          <?php else: ?><div class="ua-security"><span>✓</span><div><strong>Altijd actief</strong><p>Administratoraccounts kunnen niet gedeactiveerd worden.</p></div></div><?php endif; ?>
          <?php if ((int)$selectedUser['id'] !== $currentId): ?>
            <div class="ua-danger-zone"><h3>Account definitief verwijderen</h3><p class="ua-hint">Deze actie is onomkeerbaar.</p><form method="post" class="ua-security-form" onsubmit="return confirm('Deze gebruiker definitief verwijderen?')">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="user_id" value="<?= (int)$selectedUser['id'] ?>">
              <label>Jouw huidige wachtwoord<input type="password" name="current_password" required autocomplete="current-password"></label><div><button class="ua-button danger" type="submit">Gebruiker verwijderen</button></div>
            </form></div>
          <?php endif; ?>
        <?php endif; ?>
        </div>
      <?php endif; ?>
    </section>
  </div>
  <section class="ua-panel" style="margin-top:20px" aria-label="Uitnodigingen">
    <div class="ua-panel-head"><h2>Uitnodigingen</h2><p><?= $pendingCount ?> openstaand · Europe/Brussels</p></div>
    <div class="ua-detail-body">
    <?php if (!$invitations): ?><p class="ua-hint">Nog geen uitnodigingen.</p><?php endif; ?>
    <?php foreach ($invitations as $invitation):
      $pending = !$invitation['accepted_at'] && !$invitation['revoked_at'] && strtotime($invitation['expires_at'] . ' UTC') > time();
      $status = $invitation['accepted_at'] ? 'Geaccepteerd' : ($invitation['revoked_at'] ? 'Ingetrokken' : ($pending ? 'Openstaand' : 'Verlopen'));
    ?>
      <div class="ua-access-card"><div><strong><?= e($invitation['name']) ?> · <?= e($invitation['email']) ?></strong>
        <p><?= e($roleName($invitation['role'])) ?> · <?= e($status) ?></p>
        <p>Verstuurd: <?= e(invitation_brussels($invitation['created_at'])) ?> · Vervalt: <?= e(invitation_brussels($invitation['expires_at'])) ?></p>
      </div>
      <?php if ($pending): ?><form method="post" class="ua-security-form" onsubmit="return confirm('Uitnodiging intrekken?')">
        <?= csrf_field() ?><input type="hidden" name="action" value="revoke-invitation">
        <input type="hidden" name="invitation_id" value="<?= (int)$invitation['id'] ?>">
        <label>Jouw wachtwoord<input type="password" name="current_password" required autocomplete="current-password"></label>
        <button class="ua-button danger" type="submit">Intrekken</button>
      </form><?php endif; ?></div>
    <?php endforeach; ?></div>
  </section>
  <dialog id="ua-new-dialog" aria-labelledby="ua-new-heading"><h2 id="ua-new-heading">Gebruiker uitnodigen</h2><p class="ua-hint">Maak een account aan. Je huidige wachtwoord is verplicht.</p>
    <form method="post" class="ua-form" autocomplete="off">
      <?= csrf_field() ?><input type="hidden" name="action" value="create">
      <label class="ua-wide">Naam<input type="text" name="name" maxlength="120" required autocomplete="name" placeholder="Voor- en achternaam"></label>
      <label class="ua-wide">E-mailadres<input type="email" name="email" maxlength="190" required autocomplete="off"></label>
      <label class="ua-wide">Rol<select name="role" required><option value="assistant">Assistent</option><option value="veilingmeester">Veilingmeester</option><option value="administrator">Administrator</option></select></label>
      <p class="ua-hint ua-wide">Een uitnodigingslink wordt per e-mail verstuurd, 48 uur geldig.</p>
      <label class="ua-wide">Jouw huidige wachtwoord<input type="password" name="current_password" required autocomplete="current-password"></label>
      <div class="ua-wide ua-actions"><button type="button" class="ua-button light" id="ua-close-new">Annuleren</button><button class="ua-button" type="submit">Uitnodiging versturen</button></div>
    </form>
  </dialog>
</div>
<script>
(function(){
  const root=document.querySelector('.user-admin');if(!root)return;
  const modal=root.querySelector('#ua-new-dialog');
  root.querySelector('#ua-open-new').addEventListener('click',()=>modal.showModal());
  root.querySelector('#ua-close-new').addEventListener('click',()=>modal.close());
  const search=root.querySelector('#ua-search'),filter=root.querySelector('#ua-filter'),rows=[...root.querySelectorAll('.ua-user')],empty=root.querySelector('#ua-empty');
  function apply(){let count=0;for(const row of rows){const status=row.dataset.status;const f=filter.value;const visible=row.dataset.search.includes(search.value.trim().toLowerCase())&&(f==='all'||(f==='administrator'?status==='administrator':f==='active'?status==='active'||status==='administrator':status==='off'));row.hidden=!visible;if(visible)count++}empty.style.display=count?'none':'block'}
  search.addEventListener('input',apply);filter.addEventListener('change',apply);
})();
</script>
<?php admin_bottom(); ?>
