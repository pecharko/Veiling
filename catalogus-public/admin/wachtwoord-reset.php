<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/private/bootstrap.php';
require dirname(__DIR__, 2) . '/private/admin_password_reset.php';
require_no_index();
header('Referrer-Policy: no-referrer');
admin_session();
$token = (string)($_POST['token'] ?? $_GET['token'] ?? '');
$validFormat = (bool)preg_match('/\\A[a-f0-9]{64}\\z/D', $token);
$error = '';
if (is_post()) {
    check_csrf();
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm'] ?? '');
    if ($password !== $confirm) $error = 'De wachtwoorden komen niet overeen.';
    elseif (strlen($password) < 12 || strlen($password) > 1024) $error = 'Gebruik minimaal 12 tekens.';
    elseif (!$validFormat || !reset_commit_password(db(), $token, $password)) $error = 'Deze link is ongeldig, verlopen of al gebruikt. Vraag een nieuwe link aan.';
    else {
        $_SESSION = [];
        session_regenerate_id(true);
        flash('Wachtwoord aangepast. Je kunt opnieuw inloggen.');
        redirect('admin/login.php');
    }
}
?><!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer"><title>Nieuw wachtwoord · 41 Lokeren</title><link rel="stylesheet" href="<?=e(url('admin/admin.css'))?>"></head><body class="login-body"><main class="login-card"><p class="eyebrow">41 LOKEREN · BEHEER</p><h1>Nieuw wachtwoord</h1><?php if($error): ?><div class="alert error" role="alert"><?=e($error)?></div><?php endif; ?><?php if($validFormat): ?><form method="post"><?=csrf_field()?><input type="hidden" name="token" value="<?=e($token)?>"><label>Nieuw wachtwoord<input type="password" name="password" required minlength="12" autocomplete="new-password"></label><label>Herhaal het wachtwoord<input type="password" name="confirm" required minlength="12" autocomplete="new-password"></label><button class="primary" type="submit">Wachtwoord opslaan →</button></form><?php else: ?><p>Deze link is ongeldig. Vraag een nieuwe resetlink aan.</p><?php endif; ?><p><a href="<?=e(url('admin/wachtwoord-vergeten.php'))?>">Nieuwe resetlink aanvragen</a></p></main></body></html>
