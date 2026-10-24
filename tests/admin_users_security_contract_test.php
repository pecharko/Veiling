<?php
declare(strict_types=1);
// Offline contract test: no database or production access.
$root = dirname(__DIR__);
$screen = file_get_contents($root . '/catalogus-public/admin/gebruikers.php');
$login = file_get_contents($root . '/catalogus-public/admin/login.php');
$boot = file_get_contents($root . '/private/bootstrap.php');
foreach (['require_auth();', 'check_csrf();', 'password_verify(', 'password_hash(', 'FOR UPDATE', 'DELETE FROM admins', 'target === $currentId', 'current_password'] as $needle) {
    if (!str_contains($screen, $needle)) throw new RuntimeException('Missing management safeguard: ' . $needle);
}
if (!str_contains($login, "admin_credential_hash") || !str_contains($boot, "admin_credential_hash")) {
    throw new RuntimeException('Missing admin session credential revocation');
}
if (!str_contains($boot, 'Gebruikersbeheer')) throw new RuntimeException('Missing admin menu');
echo "PASS admin management security contracts\n";
