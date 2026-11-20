<?php
declare(strict_types=1);

/**
 * Read-only source contract for administrator-less bootstrap handling.
 * Never creates users, touches OVH, or starts a local database.
 */
$root = dirname(__DIR__);
$login = file_get_contents($root . '/catalogus-public/admin/login.php');
if ($login === false) throw new RuntimeException('Cannot read admin login source');

$required = [
    "SELECT COUNT(*) FROM admins",
    "http_response_code(503);",
    "header('Cache-Control: no-store');",
    "exit('Beheer tijdelijk niet beschikbaar. Contacteer de websitebeheerder.');",
    "if (is_post()) {",
    "check_csrf();",
    "password_verify(",
];
foreach ($required as $needle) {
    if (!str_contains($login, $needle)) {
        throw new RuntimeException('Missing login safety contract: ' . $needle);
    }
}
if (str_contains($login, "redirect('admin/setup.php')")) {
    throw new RuntimeException('Public setup redirect must not be available');
}
if (file_exists($root . '/catalogus-public/admin/setup.php')) {
    throw new RuntimeException('Unexpected public administrator setup endpoint');
}
$emptyTableGuard = strpos($login, "SELECT COUNT(*) FROM admins");
$loginPostGuard = strpos($login, 'if (is_post()) {');
if ($emptyTableGuard === false || $loginPostGuard === false || $emptyTableGuard >= $loginPostGuard) {
    throw new RuntimeException('Administrator-less failure must precede login POST');
}
$systemcheck = file_get_contents($root . '/catalogus-public/admin/systeemcheck.php');
if (!is_string($systemcheck) || str_contains($systemcheck, 'sql/install.sql')
    || !str_contains($systemcheck, 'Contacteer de websitebeheerder')) {
    throw new RuntimeException('System check must not advise missing install.sql');
}
$procedure = file_get_contents($root . '/docs/ADMIN_RECOVERY.md');
if (!is_string($procedure) || !str_contains($procedure, 'SHOW COLUMNS FROM admins')
    || !str_contains($procedure, 'geen adminaccounts')
    || !str_contains($procedure, 'eigenaarautorisatie')) {
    throw new RuntimeException('Private no-admin recovery procedure is absent or incomplete');
}
if (!is_file($root . '/catalogus-public/assets/placeholder.svg')) {
    throw new RuntimeException('Photo-less lot placeholder missing');
}
$csvRoute = file_get_contents($root . '/catalogus-public/admin/import.php');
if (!is_string($csvRoute) || !str_contains($csvRoute, 'http_response_code(404)')) {
    throw new RuntimeException('Retired CSV import route must still fail closed');
}
echo "PASS missing-administrator fail-closed contract\n";
