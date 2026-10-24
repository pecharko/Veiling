<?php
declare(strict_types=1);

require_once __DIR__ . '/analytics_control.php';

function track_catalog_view(?int $lotId = null): void
{
    if (!analytics_collection_enabled()) return;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
    if (($_SERVER['HTTP_DNT'] ?? '') === '1') return;
    $agent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($agent === '' || preg_match('/bot|crawl|spider|preview|headless/i', $agent)) return;
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
