<?php
declare(strict_types=1);

// Static regression contract: the public admin menu must not bypass OVH auth.
$root = dirname(__DIR__);
$bootstrap = file_get_contents($root . '/private/bootstrap.php');
$roles = file_get_contents($root . '/catalogus-public/admin/rollen.php');
if (!is_string($bootstrap) || !is_string($roles)) {
    throw new RuntimeException('Missing admin navigation sources');
}
$start = strpos($bootstrap, 'function admin_top(string $title): void {');
$end = strpos($bootstrap, 'function admin_bottom(): void {', $start === false ? 0 : $start);
if ($start === false || $end === false || $end <= $start) {
    throw new RuntimeException('Cannot isolate the shared admin navigation');
}
$menu = substr($bootstrap, $start, $end - $start);
$checks = [
    "\$activeRole = effective_admin_role();",
    "in_array(\$activeRole, ['administrator', 'veilingmeester'], true)",
    "https://webmail.mail.ovh.ca/",
    'target="_blank" rel="noopener noreferrer"',
    'aria-label="Mail openen via OVH (nieuw tabblad)"',
    'data-mail-unread-url=',
    'admin-mail-badge',
    'Mail ↗',
    "\$publicPreview . \$outlookNav .",
    ": '';",
];
foreach ($checks as $check) {
    if (!str_contains($menu, $check)) {
        throw new RuntimeException('Outlook navigation contract missing: ' . $check);
    }
}
if (substr_count($menu, 'https://webmail.mail.ovh.ca/') !== 1) {
    throw new RuntimeException('Expected one exact external OWA destination');
}
if (str_contains($menu, "veiling@41lokeren.be")
    || str_contains($menu, 'veiling@fortiwanloceren.be')
    || str_contains($menu, 'password=')
    || str_contains($menu, 'token=')) {
    throw new RuntimeException('No credentials or mailbox account should be passed to OWA');
}
foreach ([
    'Outlook Web App (OVH) openen</td><td>Ja</td><td>Nee</td><td>Ja</td>',
    'veiling@41lokeren.be',
    'Toegang tot de mailbox wordt afzonderlijk door OVH gecontroleerd',
] as $check) {
    if (!str_contains($roles, $check)) {
        throw new RuntimeException('Outlook role guidance missing: ' . $check);
    }
}
echo "PASS Outlook OWA role gating, browser navigation and independent authentication contract\n";
