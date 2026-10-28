<?php
declare(strict_types=1);
require dirname(__DIR__) . '/private/admin_invitations.php';
function check_invite(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: " . $message . "\n"); exit(1); }
}
check_invite(invitation_email(' Test@Example.com ') === 'test@example.com', 'normalize email');
try { invitation_email('invalid'); check_invite(false, 'invalid email must fail'); }
catch (InvalidArgumentException $expected) {}
check_invite(invitation_role('assistant') === 'assistant', 'assistant accepted');
check_invite(invitation_role('administrator') === 'administrator', 'administrator accepted');
try { invitation_role('superuser'); check_invite(false, 'unexpected role must fail'); }
catch (InvalidArgumentException $expected) {}
check_invite(invitation_brussels(null) === 'Nog nooit', 'missing login');
check_invite(invitation_brussels('2026-01-15 12:00:00') === '15/01/2026 13:00', 'winter CET');
check_invite(invitation_brussels('2026-07-15 12:00:00') === '15/07/2026 14:00', 'summer CEST');
echo "PASS admin invitations normalization and Brussels time conversion\n";
