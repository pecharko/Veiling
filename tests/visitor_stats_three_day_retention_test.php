<?php
declare(strict_types=1);
// Offline regression guard: cron, archive validation, manual preview, privacy
// must use the same three-day UTC retention threshold.
$root = dirname(__DIR__);
$checks = [
 'private/visitor_stats_daily_cron.php' => "strtotime('-3 days')",
 'private/visitor_stats_archive_one_day_experimental.php' => "strtotime('-3 days')",
 'catalogus-public/admin/statistieken.php' => "strtotime('-3 days')",
 'catalogus-public/privacy.php' => 'drie volledige UTC-dagen',
];
foreach ($checks as $path => $expected) {
    $code = file_get_contents($root . '/' . $path);
    if (!is_string($code) || !str_contains($code, $expected)) throw new RuntimeException("Missing retention policy: $path");
    if (str_contains($code, "strtotime('-7 days')") || str_contains($code, 'zeven volledige UTC-dagen')) {
        throw new RuntimeException("Outdated seven-day archive policy: $path");
    }
}
echo "Three-day visitor archive policy consistent\n";
