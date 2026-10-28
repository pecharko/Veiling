<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/private/bootstrap.php';
require_once dirname(__DIR__, 2) . '/private/admin_invitations.php';
require_no_index();
header('Referrer-Policy: no-referrer');
admin_session();
$token = (string)($_POST['token'] ?? $_GET['token'] ?? '');
$valid = (bool)preg_match('/\\A[a-f0-9]{64}\\z/D', $token);
$error = '';
if (is_post()) {
    check_csrf();
    $password = (string)($_POST['password'] ?? '');
    $repeat = (string)($_POST['confirm'] ?? '');
    if ($password !== $repeat) $error = 'Wachtwoorden komen niet overeen.';
    elseif (strlen($password) < 12 || strlen($password) > 1024) $error = 'Gebruik minimaal 12 tekens.';
    elseif (!$valid || !invitation_accept(db(), $token, $password)) $error = 'Deze uitnodiging is verlopen, ingetrokken of al gebruikt.';
    else {
        $_SESSION = [];
        session_regenerate_id(true);
        flash('Je account is aangemaakt. Log in met je eigen wachtwoord.');
        redirect('admin/login.php');
    }
}
?><!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer"><title>Uitnodiging aanvaarden · 41 Lokeren</title><link rel="stylesheet" href="<?=e(url('admin/admin.css'))?>"></head><body class="login-body"><main class="login-card"><p class="eyebrow">41 LOKEREN · BEHEER</p><h1>Je uitnodiging</h1><p>Stel zelf je wachtwoord in. De uitnodiging kan maar één keer worden gebruikt.</p><?php if ($error): ?><div class="alert error" role="alert"><?=e($error)?></div><?php endif; ?><?php if ($valid): ?><form method="post"><?=csrf_field()?><input type="hidden" name="token" value="<?=e($token)?>"><label>Wachtwoord (minimaal 12 tekens)<input type="password" name="password" required minlength="12" maxlength="1024" autocomplete="new-password"></label><label>Herhaal wachtwoord<input type="password" name="confirm" required minlength="12" autocomplete="new-password"></label><button class="primary" type="submit">Account activeren →</button></form><?php else: ?><p>Deze link is ongeldig.</p><?php endif; ?><p><a href="<?=e(url('admin/login.php'))?>">Terug naar inloggen</a></p></main></body></html>
