<?php
declare(strict_types=1);

// No schema migration or changes to the existing statistics are needed.
// The absence of this marker retains the current, enabled-by-default behavior.
function analytics_disabled_marker(): string {
    return __DIR__ . '/analytics.disabled';
}
function analytics_collection_enabled(): bool {
    return !is_file(analytics_disabled_marker());
}
function set_analytics_collection_enabled(bool $enabled): void {
    $marker = analytics_disabled_marker();
    if ($enabled) {
        if (is_file($marker) && !unlink($marker)) {
            throw new RuntimeException('Kon bezoekersregistratie niet inschakelen.');
        }
        if (is_file($marker)) throw new RuntimeException('Status kon niet worden bevestigd.');
    } else {
        if (!is_file($marker)) {
            $file = @fopen($marker, 'x'); // PHP x is exclusive, writable creation.
            if ($file === false) throw new RuntimeException('Kon bezoekersregistratie niet uitschakelen.');
            fclose($file);
        }
        if (!is_file($marker)) throw new RuntimeException('Status kon niet worden bevestigd.');
    }
}

/** Canonical binary IP; never trust client-controlled forwarding headers. */
function analytics_ip_key(string $ip): string {
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) throw new RuntimeException('Ongeldig clientadres.');
    $key = inet_pton($ip);
    if ($key === false) throw new RuntimeException('Ongeldig clientadres.');
    if (strlen($key) === 16 && substr($key, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
        $key = substr($key, 12); // IPv4-mapped IPv6 has the same exclusion as IPv4.
    }
    return $key;
}

function analytics_ip_exclusion_enabled(PDO $pdo): bool {
    $rows = $pdo->query('SELECT id,enabled FROM visitor_stats_ip_exclusion_state')->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) !== 1 || (int)$rows[0]['id'] !== 1
        || !in_array((string)$rows[0]['enabled'], ['0','1'], true)) {
        throw new RuntimeException('IP-uitsluiting tijdelijk niet beschikbaar.');
    }
    return (int)$rows[0]['enabled'] === 1;
}

/** Called only after successful authentication; records even when exclusion is OFF. */
function analytics_ip_register_login(PDO $pdo, int $adminId): void {
    if ($adminId <= 0) throw new RuntimeException('Ongeldige beheerder.');
    $key = analytics_ip_key((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $stmt = $pdo->prepare('INSERT IGNORE INTO visitor_stats_ip_exclusions (ip_address,admin_id) VALUES (?,?)');
    $stmt->execute([$key,$adminId]);
}

function analytics_ip_is_excluded(PDO $pdo): bool {
    if (!analytics_ip_exclusion_enabled($pdo)) return false;
    $key = analytics_ip_key((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $stmt = $pdo->prepare('SELECT 1 FROM visitor_stats_ip_exclusions WHERE ip_address=? LIMIT 1');
    $stmt->execute([$key]);
    return $stmt->fetchColumn() !== false;
}

function analytics_ip_set_exclusion(PDO $pdo, bool $enabled): void {
    analytics_ip_exclusion_enabled($pdo); // Missing/invalid state never silently initializes.
    $stmt = $pdo->prepare('UPDATE visitor_stats_ip_exclusion_state SET enabled=? WHERE id=1');
    $stmt->execute([$enabled ? 1 : 0]);
}
