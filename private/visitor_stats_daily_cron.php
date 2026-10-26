<?php
declare(strict_types=1);
// Issue #224: private CLI-only OVH cron script. NOT activated by this commit.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/visitor_stats_archive_one_day_experimental.php';
$cutoff = gmdate('Y-m-d', strtotime('-7 days'));
$maxDays = 14;
$totalDays = 0;
$totalRows = 0;
$totalViews = 0;
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
        fwrite(STDERR, "archive_catchup_limit_reached days=$totalDays cutoff=$cutoff\n");
    }
} catch (Throwable $e) {
    error_log('Archive cron failed; error type=' . get_class($e));
    fwrite(STDERR, "archive_failed days_completed=$totalDays\n");
    exit(1);
}
