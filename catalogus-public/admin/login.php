<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/private/bootstrap.php';
require_once dirname(__DIR__, 2) . '/private/analytics_control.php';
require_no_index();
admin_session();
if (isset($_SESSION['admin_id'])) redirect('admin/index.php');
$error = '';
$pdo = db();
// An empty administrator table is a provisioning incident, never an invitation
// to expose a public bootstrap or setup form. Recovery is an operator-only task.
if ((int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn() === 0) {
  http_response_code(503);
  header('Cache-Control: no-store');
  exit('Beheer tijdelijk niet beschikbaar. Contacteer de websitebeheerder.');
}
$ip = hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'onbekend'));
if (is_post()) {
  check_csrf();
  // Catalog-only v8 snapshots exclude admins, login_attempts and IP exclusions.
  // Serialize the attempt check and update per IP, independently of catalog writes.
  $lockName = '41lokeren_login_' . substr($ip, 0, 48);
  $lock = $pdo->prepare('SELECT GET_LOCK(?, 2)');
  $lock->execute([$lockName]);
  if ((int)$lock->fetchColumn() !== 1) {
    http_response_code(503);
    header('Retry-After: 2');
    exit('Inloggen is tijdelijk bezet. Probeer het opnieuw.');
  }
  $authenticated = false;
  try {
    $pdo->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
    $st = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip_hash=? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
    $st->execute([$ip]);
    if ((int)$st->fetchColumn() >= 8) {
      $error = 'Te veel mislukte pogingen. Probeer later opnieuw.';
    } else {
      $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
      $password = (string)($_POST['password'] ?? '');
      $u = $pdo->prepare('SELECT id,password_hash,is_active FROM admins WHERE email=? LIMIT 1');
      $u->execute([$email]);
      $admin = $u->fetch();
      $authenticated = $admin && (int)$admin['is_active'] === 1 && password_verify($password, $admin['password_hash']);
      if ($authenticated) {
        $pdo->prepare('DELETE FROM login_attempts WHERE ip_hash=?')->execute([$ip]);
      } else {
        $pdo->prepare('INSERT INTO login_attempts (ip_hash) VALUES (?)')->execute([$ip]);
        $error = 'Ongeldige inloggegevens.';
      }
    }
  } finally {
    $pdo->prepare('DO RELEASE_LOCK(?)')->execute([$lockName]);
  }
  if ($authenticated) {
    session_regenerate_id(true);
    unset($_SESSION['admin_view_role']);
    $_SESSION['admin_id'] = (int)$admin['id'];
    $_SESSION['admin_last_activity_at'] = time();
    $_SESSION['admin_credential_hash'] = hash('sha256', (string)$admin['password_hash']);
    $pdo->prepare('UPDATE admins SET last_login_at=UTC_TIMESTAMP() WHERE id=? AND is_active=1')->execute([(int)$admin['id']]);
    try {
      analytics_ip_register_login($pdo, (int)$admin['id']);
    } catch (Throwable $e) {
      // Analytics failure never locks an authenticated administrator out.
      flash('IP-registratie is tijdelijk niet beschikbaar. Controleer de IP-uitsluiting bij Statistieken.', 'error');
    }
    redirect('admin/index.php');
  }
}
$flash = take_flash();
?><!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Inloggen · Veilingcatalogus</title><link rel="icon" type="image/png" href="<?=e(url('favicon.png'))?>"><link rel="stylesheet" href="<?=e(url('admin/admin.css'))?>"></head><body class="login-body"><main class="login-card"><span class="brand-symbol">V<span>•</span></span><p class="eyebrow">BEHEERDERSTOEGANG</p><h1>Welkom terug</h1><p>Log in om kavels te beheren en de veiling te publiceren.</p><?php if($error): ?><div class="alert error" role="alert"><?=e($error)?></div><?php endif; ?><?php if($flash): ?><div class="alert <?=e($flash['type'])?>"><?=e($flash['message'])?></div><?php endif; ?><form method="post"><?=csrf_field()?><label>E-mailadres<input type="email" name="email" required autocomplete="username"></label><label>Wachtwoord<input type="password" name="password" required autocomplete="current-password"></label><button class="primary" type="submit">Inloggen →</button></form><p><a href="<?=e(url('admin/wachtwoord-vergeten.php'))?>">Wachtwoord vergeten?</a></p><p><a href="<?=e(url('index.php'))?>">← Publieke catalogus</a></p></main></body></html>
