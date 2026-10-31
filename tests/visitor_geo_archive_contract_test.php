<?php
declare(strict_types=1);
// Offline guard contract: geographic data may only be deleted inside an existing
// transaction after aggregation verification. This test does not touch a DB.
$path = dirname(__DIR__) . '/private/visitor_geo_archive.php';
$source = file_get_contents($path);
if (!is_string($source)) throw new RuntimeException('Geo archive helper missing');
$required = [
    "function visitor_geo_archive_day(PDO \$pdo, string \$day)",
    "if (!\$pdo->inTransaction())",
    "visitor_geo_assert_day_consistent(\$pdo, \$day)",
    "visitor_geo_lock_day(\$pdo, 'visitor_geo_day_totals', \$day)",
    "INSERT INTO visitor_geo_day_totals",
    "Geo aggregate verification failed",
    "DELETE FROM visitor_geo_daily WHERE visit_date=?",
    "Geo archive source changed",
];
foreach ($required as $needle) {
    if (!str_contains($source, $needle)) {
        throw new RuntimeException('Missing geo archive safety contract: ' . $needle);
    }
}
$insert = strpos($source, 'INSERT INTO visitor_geo_day_totals');
$verify = strpos($source, 'Geo aggregate verification failed');
$delete = strpos($source, 'DELETE FROM visitor_geo_daily');
if ($insert === false || $verify === false || $delete === false || !($insert < $verify && $verify < $delete)) {
    throw new RuntimeException('Geo archive must insert, verify, then delete');
}
echo "Geo archive transaction safety contract OK\n";
