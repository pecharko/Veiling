<?php
declare(strict_types=1);

// Read-only contract: no OVH credentials, auto-login or public mailbox endpoint.
$root = dirname(__DIR__);
$bootstrap = file_get_contents($root . '/private/bootstrap.php');
$roles = file_get_contents($root . '/catalogus-public/admin/rollen.php');
if (!is_string($bootstrap) || !is_string($roles)) {
    throw new RuntimeException('Missing admin navigation sources');
}
$menu = strstr($bootstrap, 'function admin_top(string $title): void {');
if (!is_string($menu)) {
    throw new RuntimeException('Missing shared admin navigation');
}
$checks = [
    "in_array(\$activeRole, ['administrator', 'veilingmeester'], true)",
    "\$activeRole = effective_admin_role();",
    "https://www.ovhcloud.com/en-gb/mail/",
    'target="_blank" rel="noopener noreferrer"',
    "aria-label=\"Outlook Web App van OVH openen (nieuw tabblad)\"",
    "\$publicPreview . \$outlookNav .",
];
foreach ($checks as $check) {
    if (!str_contains($menu, $check)) {
        throw new RuntimeException('Outlook security/role contract missing: ' . $check);
    }
}
if (!str_contains($menu, ": '';")) {
    throw new RuntimeException('Outlook link must be absent for assistants');
}
foreach (['Outlook Web App (OVH) openen</td><td>Ja</td><td>Nee</td><td>Ja</td>',
    'veiling@41lokeren.be',
    'Toegang tot de mailbox wordt afzonderlijk door OVH gecontroleerd'] as $check) {
    if (!str_contains($roles, $check)) {
        throw new RuntimeException('Role documentation missing: ' . $check);
    }
}
if (str_contains($menu, 'veiling@fortiwanloceren.be')
    || str_contains($menu, 'password=')
    || str_contains($menu, 'token=')) {
    throw new RuntimeException('Mailbox credentials or account routing must not appear in URL');
}
echo "PASS OVH OWA external link, effective-role menu gating and no stored credentials\n";
