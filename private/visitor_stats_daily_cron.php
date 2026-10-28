<?php
declare(strict_types=1);
// Private CLI-only cron. Logging must never change the archival transaction.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/visitor_stats_archive_one_day_experimental.php';
require_once __DIR__ . '/visitor_stats_cron_log.php';
$started = gmdate('Y-m-d\TH:i:s\Z');
$cutoff = gmdate('Y-m-d', strtotime('-7 days'));
$maxDays = 14;
$totalDays = 0;
$totalRows = 0;
$totalViews = 0;
$status = 'ok';
$errorType = null;
$exitCode = 0;
try {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $stmt->execute(['visitor_stats_daily_cron']);
    $acquired = (int)$stmt->fetchColumn() === 1;
    $stmt->closeCursor();
    if (!$acquired) throw new RuntimeException('Archive runner already active');
    try {
        for ($n = 0; $n < $maxDays; ++$n) {
            $result = archive_visitor_stats_one_day($pdo, $cutoff);
            if ($result['days'] === 0) break;
            $totalDays += $result['days'];
            $totalRows += $result['rows'];
            $totalViews += $result['views'];
        }
    } finally {
        $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute(['visitor_stats_daily_cron']);
        $stmt->closeCursor();
    }
    fwrite(STDOUT, "archive_ok days=$totalDays rows=$totalRows views=$totalViews cutoff=$cutoff\n");
    if ($totalDays === $maxDays) {
        $status = 'limit';
        $exitCode = 2;
        fwrite(STDERR, "archive_catchup_limit_reached days=$totalDays cutoff=$cutoff\n");
    }
} catch (Throwable $e) {
    $status = 'error';
    $exitCode = 1;
    $errorType = get_class($e); // Do not persist DB exceptions, SQL, paths or visitor data.
    error_log('Archive cron failed; error type=' . $errorType);
    fwrite(STDERR, "archive_failed days_completed=$totalDays\n");
}
$logged = visitor_stats_cron_log_append([
    'started_utc' => $started,
    'finished_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    'status' => $status,
    'days_archived' => $totalDays,
    'rows_deleted' => $totalRows,
    'views_archived' => $totalViews,
    'error_type' => $errorType,
]);
if (!$logged) {
    error_log('Archive cron: unable to persist JSON execution log.');
    fwrite(STDERR, "archive_log_write_failed\n");
    if ($exitCode === 0) $exitCode = 1;
}
exit($exitCode);
