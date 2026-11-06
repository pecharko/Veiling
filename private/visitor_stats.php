<?php
declare(strict_types=1);

require_once __DIR__ . '/analytics_control.php';
require_once __DIR__ . '/catalog_mutex.php';
require_once __DIR__ . '/restore_journal.php';
require_once __DIR__ . '/visitor_geo_state.php';
require_once __DIR__ . '/visitor_geo_record.php';
require_once __DIR__ . '/visitor_geo_lookup.php';

/**
 * Exclude known US-origin traffic from every visitor and lot statistic.
 * Unresolved GeoIP countries (ZZ) are not guessed to be US.
 */
function visitor_stats_exclude_country(array $geo): bool
{
    return ($geo['country_code'] ?? null) === 'US';
}

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
    $locked = false;
    $started = false;
    try {
        $pdo = db();
        if ($pdo->inTransaction()) return; // Never commit or roll back a caller's transaction.
        catalog_data_lock($pdo, 0);
        $locked = true;
        restore_journal_require_clear();
        if (analytics_ip_is_excluded($pdo)) return; // Before HMAC/geo/INSERT, for every session at this IP.
        // Resolve even if geographic reporting is disabled: the US exclusion applies
        // to both general visitor counts and lot views, not just geo counters.
        $resolvedGeo = visitor_geo_resolve($ip);
        if (visitor_stats_exclude_country($resolvedGeo)) return; // Nothing is inserted or aggregated.
        $identifier = hash_hmac('sha256', $day . ':' . $ip . ':' . $agent, $secret, true);
        $state = visitor_geo_state($pdo);
        $geo = visitor_geo_collection_enabled($state) && visitor_geo_covers_day($state, $day) ? $resolvedGeo : null;
        $pdo->beginTransaction();
        $started = true;
        $stmt = $pdo->prepare('INSERT INTO visitor_stats_daily (visit_date, visitor_id, page_kind, lot_id, views) VALUES (?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE views = views + 1');
        $stmt->execute([$day, $identifier, $lotId === null ? 'home' : 'lot', $lotId ?? 0]);
        if ($geo !== null) visitor_geo_record_view($pdo, $day, $identifier, $geo);
        $pdo->commit();
        $started = false;
    } catch (Throwable $exception) {
        try { if ($started && $pdo->inTransaction()) $pdo->rollBack(); }
        catch (Throwable $rollbackError) { error_log('Analytics rollback could not be confirmed.'); }
        error_log('Analytics unavailable (no visitor details logged).');
    } finally {
        if ($locked) {
            try { catalog_data_unlock($pdo); }
            catch (Throwable $e) { error_log('Analytics lock release failed.'); }
        }
    }
}
