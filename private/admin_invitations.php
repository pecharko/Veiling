<?php
declare(strict_types=1);
/** Administrator-invited account creation. No invitation token is logged or stored in cleartext. */
const ADMIN_INVITE_FROM = 'noreply@41lokeren.be';

function invitation_email(string $value): string {
    $email = mb_strtolower(trim($value));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
        throw new InvalidArgumentException('Vul een geldig e-mailadres in.');
    }
    return $email;
}
function invitation_role(string $value): string {
    if (!in_array($value, ['administrator', 'assistant', 'veilingmeester'], true)) {
        throw new InvalidArgumentException('Ongeldige rol.');
    }
    return $value;
}
function invitation_issue(PDO $pdo, string $name, string $address, string $role, int $creator): bool {
    $email = invitation_email($address);
    $role = invitation_role($role);
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $pdo->beginTransaction();
    try {
        $existing = $pdo->prepare('SELECT id FROM admins WHERE email=? LIMIT 1');
        $existing->execute([$email]);
        if ($existing->fetch()) throw new InvalidArgumentException('Dit e-mailadres heeft al een account.');
        // The unique email constraint serializes concurrent invitations.
        $st = $pdo->prepare("INSERT INTO admin_invitations
          (name,email,role,token_hash,created_at,expires_at,created_by)
          VALUES (?,?,?,?,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(), INTERVAL 48 HOUR),?)
          ON DUPLICATE KEY UPDATE name=VALUES(name),role=VALUES(role),
          token_hash=VALUES(token_hash),created_at=UTC_TIMESTAMP(),
          expires_at=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 48 HOUR),
          accepted_at=NULL,revoked_at=NULL,created_by=VALUES(created_by)");
        $st->execute([$name,$email,$role,$hash,$creator]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    $link = 'https://41lokeren.be' . url('admin/uitnodiging.php', ['token'=>$token]);
    $subject = '41 Lokeren - uitnodiging beheeromgeving';
    $message = "Je bent uitgenodigd als beheerder van 41 Lokeren.\n\n"
        . "Maak binnen 48 uur zelf je wachtwoord aan via deze eenmalige link:\n"
        . $link . "\n\nHeb je dit niet verwacht? Negeer dit bericht.\n";
    $headers = [
        'From' => '41 Lokeren <' . ADMIN_INVITE_FROM . '>',
        'Reply-To' => ADMIN_INVITE_FROM,
        'Content-Type' => 'text/plain; charset=UTF-8',
        'X-Auto-Response-Suppress' => 'All',
    ];
    try { $sent = mail($email, $subject, $message, $headers); }
    catch (Throwable $e) { $sent = false; }
    if (!$sent) {
        // Revoke only this exact token, never an invitation reissued concurrently.
        $st = $pdo->prepare('UPDATE admin_invitations SET revoked_at=UTC_TIMESTAMP(),token_hash=NULL WHERE email=? AND token_hash=? AND accepted_at IS NULL');
        $st->execute([$email,$hash]);
        error_log('Admin invitation email delivery failed');
        return false;
    }
    return true;
}
function invitation_revoke(PDO $pdo, int $id): bool {
    if ($id < 1) return false;
    $st = $pdo->prepare('UPDATE admin_invitations SET revoked_at=UTC_TIMESTAMP(),token_hash=NULL WHERE id=? AND accepted_at IS NULL AND revoked_at IS NULL');
    $st->execute([$id]);
    return $st->rowCount() === 1;
}
function invitation_accept(PDO $pdo, string $token, string $password): bool {
    if (!preg_match('/\\A[a-f0-9]{64}\\z/D', $token)
        || strlen($password) < 12 || strlen($password) > 1024) return false;
    $hash = hash('sha256', $token);
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id,name,email,role FROM admin_invitations WHERE token_hash=? AND accepted_at IS NULL AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP() FOR UPDATE');
        $st->execute([$hash]);
        $invite = $st->fetch();
        if (!$invite) { $pdo->rollBack(); return false; }
        // Fail closed on an email already registered by another path.
        $exists = $pdo->prepare('SELECT id FROM admins WHERE email=? LIMIT 1');
        $exists->execute([$invite['email']]);
        if ($exists->fetch()) { $pdo->rollBack(); return false; }
        $st = $pdo->prepare('INSERT INTO admins (name,email,password_hash,role,is_active) VALUES (?,?,?,?,1)');
        $st->execute([$invite['name'],$invite['email'],password_hash($password,PASSWORD_DEFAULT),$invite['role']]);
        $st = $pdo->prepare('UPDATE admin_invitations SET accepted_at=UTC_TIMESTAMP(),token_hash=NULL WHERE id=? AND token_hash=? AND revoked_at IS NULL');
        $st->execute([(int)$invite['id'],$hash]);
        if ($st->rowCount() !== 1) throw new RuntimeException('Uitnodiging niet meer geldig.');
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Admin invitation acceptance failed');
        return false;
    }
}
function invitation_brussels(?string $utc): string {
    if (!$utc) return 'Nog nooit';
    try {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Europe/Brussels'))->format('d/m/Y H:i');
    } catch (Throwable $e) {
        return 'Onbekend';
    }
}
