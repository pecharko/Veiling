<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/private/bootstrap.php';
require dirname(__DIR__, 2) . '/private/admin_password_reset.php';
require_no_index();
admin_session();
$message = '';
if (is_post()) {
    check_csrf();
    reset_request(db(), (string)($_POST['email'] ?? ''));
    $message = 'Als dit e-mailadres aan een actief account gekoppeld is, ontvang je een e-mail met verdere instructies.';
}
?><!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Wachtwoord vergeten · 41 Lokeren</title><link rel="stylesheet" href="<?=e(url('admin/admin.css'))?>"></head><body class="login-body"><main class="login-card"><p class="eyebrow">41 LOKEREN · BEHEER</p><h1>Wachtwoord vergeten?</h1><p>Vul het e-mailadres van je beheeraccount in. Je ontvangt, als het account actief is, een link die 30 minuten geldig is.</p><?php if($message): ?><div class="alert success" role="status"><?=e($message)?></div><?php endif; ?><form method="post"><?=csrf_field()?><label>E-mailadres<input type="email" name="email" required autocomplete="email" maxlength="190"></label><button class="primary" type="submit">Resetlink aanvragen →</button></form><p><a href="<?=e(url('admin/login.php'))?>">← Terug naar inloggen</a></p></main></body></html>
