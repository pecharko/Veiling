<?php
declare(strict_types=1);

require_once __DIR__ . '/analytics_control.php';

/**
 * Do not count catalog views by users who are authenticated in the admin module.
 * Do not start a PHP session (or set a session cookie) for ordinary visitors.
 * Merely possessing an admin session cookie is not enough: validate the same
 * active-account/password proof used by require_auth().
 */
function visitor_is_authenticated_admin(): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        $cookie = $_COOKIE['catalog_admin'] ?? null;
        if (!is_string($cookie) || $cookie === '') return false;
        admin_session();
    }
    $id = $_SESSION['admin_id'] ?? null;
    $proof = $_SESSION['admin_credential_hash'] ?? null;
    if (!is_int($id) || $id < 1 || !is_string($proof) || $proof === '') return false;
    try {
        $stmt = db()->prepare('SELECT password_hash, is_active FROM admins WHERE id=?');
        $stmt->execute([$id]);
        $account = $stmt->fetch();
        return $account
            && (int)$account['is_active'] === 1
            && is_string($account['password_hash'])
            && hash_equals(hash('sha256', $account['password_hash']), $proof);
    } catch (PDOException $exception) {
        // Do not interrupt the public catalog if auth lookup is unavailable.
        error_log('Analytics admin exclusion check unavailable: ' . $exception->getCode());
        return false;
    }
}

function track_catalog_view(?int $lotId = null): void
{
    if (!analytics_collection_enabled()) return;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
    if (($_SERVER['HTTP_DNT'] ?? '') === '1') return;
    $agent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($agent === '' || preg_match('/bot|crawl|spider|preview|headless/i', $agent)) return;
    if (visitor_is_authenticated_admin()) return;
    $secret = cfg('analytics_secret');
    if (!is_string($secret) || strlen($secret) < 32) return;
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if ($ip === '') return;
    $day = gmdate('Y-m-d');
    $identifier = hash_hmac('sha256', $day . ':' . $ip . ':' . $agent, $secret, true);
    try {
        $pdo = db();
        $stmt = $pdo->prepare('INSERT INTO visitor_stats_daily (visit_date, visitor_id, page_kind, lot_id, views) VALUES (?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE views = views + 1');
        $stmt->execute([$day, $identifier, $lotId === null ? 'home' : 'lot', $lotId ?? 0]);
    } catch (PDOException $exception) {
        error_log('Analytics unavailable: ' . $exception->getCode());
    }
}
