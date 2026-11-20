<?php
declare(strict_types=1);
// Offline regression guard: cron, archive validation, manual preview, privacy,
// runbook and static compatibility checks must use the same three-day UTC threshold.
$root = dirname(__DIR__);
$checks = [
 'private/visitor_stats_daily_cron.php' => 'visitor_stats_utc_cutoff()',
 'private/visitor_stats_archive_one_day_experimental.php' => 'visitor_stats_utc_cutoff()',
 'catalogus-public/admin/statistieken.php' => 'visitor_stats_utc_cutoff()',
 'catalogus-public/privacy.php' => 'drie volledige UTC-dagen',
 'docs/VISITOR_STATS_DAILY_CRON_RUNBOOK.md' => 'drie volledige afgesloten UTC-dagen',
 'tests/visitor_stats_cron_static_compat_test.py' => '3 UTC days retention',
];
foreach ($checks as $path => $expected) {
    $code = file_get_contents($root . '/' . $path);
    if (!is_string($code) || !str_contains($code, $expected)) throw new RuntimeException("Missing retention policy: $path");
    if (str_contains($code, "strtotime('-7 days')") || str_contains($code, 'zeven volledige UTC-dagen')) {
        throw new RuntimeException("Outdated seven-day archive policy: $path");
    }
}
require_once $root . '/private/visitor_stats_archive_one_day_experimental.php';
$cases = [
    ['2026-03-29T00:30:00Z', '2026-03-26'],
    ['2026-03-29T23:30:00Z', '2026-03-26'],
    ['2026-10-25T00:30:00Z', '2026-10-22'],
    ['2026-10-25T23:30:00Z', '2026-10-22'],
    ['2026-11-20T00:00:00Z', '2026-11-17'],
    ['2024-03-01T00:00:00Z', '2024-02-27'], // leap year
    ['2027-01-01T00:00:00Z', '2026-12-29'], // year boundary
];
foreach (['Europe/Brussels', 'UTC', 'America/Toronto'] as $defaultTimezone) {
    date_default_timezone_set($defaultTimezone);
    foreach ($cases as [$instant, $expected]) {
        $actual = visitor_stats_utc_cutoff(new DateTimeImmutable($instant));
        if ($actual !== $expected) throw new RuntimeException("Incorrect UTC cutoff for $instant");
    }
}
echo "Three-day visitor archive policy and DST cutoff consistent\n";
