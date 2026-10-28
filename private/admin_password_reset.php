<?php
declare(strict_types=1);
/** No secrets, addresses or reset links are ever logged. */
const RESET_FROM = 'noreply@41lokeren.be';
function reset_normalize_email(string $email): string {
    return mb_strtolower(trim($email));
}
function reset_ip_fingerprint(): string {
    return hash('sha256', 'admin-reset-ip:' . (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}
function reset_email_fingerprint(string $email): string {
    return hash('sha256', 'admin-reset-email:' . $email);
}
function reset_request(PDO $pdo, string $email): void {
    $email = reset_normalize_email($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) return;
    $ip = reset_ip_fingerprint();
    $fingerprint = reset_email_fingerprint($email);
    $pdo->beginTransaction();
    try {
        // Serialize attempts across workers using the database advisory lock.
        // No advisory lock means no reset mail: fail closed.
        $lock = $pdo->query("SELECT GET_LOCK('veiling_admin_password_reset', 2)")->fetchColumn();
        if ((int)$lock !== 1) { $pdo->rollBack(); return; }
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM admin_password_resets WHERE ip_hash=? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
            $st->execute([$ip]); $ipCount = (int)$st->fetchColumn();
            $st = $pdo->prepare("SELECT COUNT(*) FROM admin_password_resets WHERE email_hash=? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
            $st->execute([$fingerprint]); $emailCount = (int)$st->fetchColumn();
            if ($ipCount >= 5 || $emailCount >= 3) { $pdo->commit(); return; }
            $st = $pdo->prepare('SELECT id,email FROM admins WHERE email=? AND is_active=1 LIMIT 1');
            $st->execute([$email]); $admin = $st->fetch();
            $token = $admin ? bin2hex(random_bytes(32)) : null;
            $pdo->prepare("INSERT INTO admin_password_resets (admin_id,ip_hash,email_hash,token_hash,expires_at) VALUES (?,?,?,?,DATE_ADD(NOW(), INTERVAL 30 MINUTE))")
                ->execute([$admin ? (int)$admin['id'] : null, $ip, $fingerprint, $token ? hash('sha256', $token) : null]);
            $rowId = (int)$pdo->lastInsertId();
            $pdo->commit();
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('veiling_admin_password_reset')");
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Admin password reset request failure');
        return;
    }
    if (!$token) return;
    $link = 'https://41lokeren.be' . url('admin/wachtwoord-reset.php', ['token' => $token]);
    $subject = '41 Lokeren - wachtwoord opnieuw instellen';
    $message = "Er is gevraagd om het wachtwoord van je beheeraccount opnieuw in te stellen.\n\n"
        . "Gebruik deze eenmalige link binnen 30 minuten:\n" . $link
        . "\n\nHeb je dit niet aangevraagd? Negeer dan dit bericht.\n";
    $headers = [
        'From' => '41 Lokeren <' . RESET_FROM . '>',
        'Reply-To' => RESET_FROM,
        'Content-Type' => 'text/plain; charset=UTF-8',
        'X-Auto-Response-Suppress' => 'All',
    ];
    try {
        if (!mail($email, $subject, $message, $headers)) {
            $pdo->prepare('UPDATE admin_password_resets SET used_at=NOW(),token_hash=NULL WHERE id=?')->execute([$rowId]);
            error_log('Admin password reset mail transport failed');
        }
    } catch (Throwable $exception) {
        $pdo->prepare('UPDATE admin_password_resets SET used_at=NOW(),token_hash=NULL WHERE id=?')->execute([$rowId]);
        error_log('Admin password reset mail transport error');
    }
}
function reset_commit_password(PDO $pdo, string $token, string $password): bool {
    if (!preg_match('/\\A[a-f0-9]{64}\\z/D', $token) || strlen($password) < 12 || strlen($password) > 1024) return false;
    $hash = hash('sha256', $token);
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id,admin_id FROM admin_password_resets WHERE token_hash=? AND used_at IS NULL AND expires_at > NOW() FOR UPDATE');
        $st->execute([$hash]); $reset = $st->fetch();
        if (!$reset || !$reset['admin_id']) { $pdo->rollBack(); return false; }
        $st = $pdo->prepare('UPDATE admins SET password_hash=? WHERE id=? AND is_active=1');
        $st->execute([password_hash($password, PASSWORD_DEFAULT), (int)$reset['admin_id']]);
        if ($st->rowCount() !== 1) { $pdo->rollBack(); return false; }
        $pdo->prepare('UPDATE admin_password_resets SET used_at=NOW(),token_hash=NULL WHERE id=?')->execute([(int)$reset['id']]);
        $pdo->prepare('UPDATE admin_password_resets SET used_at=NOW(),token_hash=NULL WHERE admin_id=? AND used_at IS NULL')->execute([(int)$reset['admin_id']]);
        $pdo->commit(); return true;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Admin password reset commit failure');
        return false;
    }
}
