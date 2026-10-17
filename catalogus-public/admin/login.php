<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/private/bootstrap.php';
require_no_index();
admin_session();
if (isset($_SESSION['admin_id'])) redirect('admin/index.php');
$error = '';
$pdo = db();
if ((int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn() === 0) redirect('admin/setup.php');
$ip = hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'onbekend'));
if (is_post()) {
  check_csrf();
  $pdo->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
  $st = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip_hash=? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
  $st->execute([$ip]);
  $attempts = (int)$st->fetchColumn();
  if ($attempts >= 8) $error = 'Te veel mislukte pogingen. Probeer later opnieuw.';
  else {
    $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $u = $pdo->prepare('SELECT id,password_hash FROM admins WHERE email=? LIMIT 1');
    $u->execute([$email]); $admin = $u->fetch();
    if ($admin && password_verify($password, $admin['password_hash'])) {
      session_regenerate_id(true);
      $_SESSION['admin_id'] = (int)$admin['id'];
      $pdo->prepare('DELETE FROM login_attempts WHERE ip_hash=?')->execute([$ip]);
      redirect('admin/index.php');
    }
    $pdo->prepare('INSERT INTO login_attempts (ip_hash) VALUES (?)')->execute([$ip]);
    $error = 'Ongeldige inloggegevens.';
  }
}
$flash = take_flash();
?><!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Inloggen · Veilingcatalogus</title><link rel="stylesheet" href="<?=e(url('admin/admin.css'))?>"></head><body class="login-body"><main class="login-card"><span class="brand-symbol">V<span>•</span></span><p class="eyebrow">BEHEERDERSTOEGANG</p><h1>Welkom terug</h1><p>Log in om kavels te beheren en de veiling te publiceren.</p><?php if($error): ?><div class="alert error" role="alert"><?=e($error)?></div><?php endif; ?><?php if($flash): ?><div class="alert <?=e($flash['type'])?>"><?=e($flash['message'])?></div><?php endif; ?><form method="post"><?=csrf_field()?><label>E-mailadres<input type="email" name="email" required autocomplete="username"></label><label>Wachtwoord<input type="password" name="password" required autocomplete="current-password"></label><button class="primary" type="submit">Inloggen →</button></form><p><a href="<?=e(url('index.php'))?>">← Publieke catalogus</a></p></main></body></html>
