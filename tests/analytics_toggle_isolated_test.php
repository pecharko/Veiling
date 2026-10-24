<?php
declare(strict_types=1);
// Isolated behavior test: no production database and no live filesystem writes.
$root = sys_get_temp_dir() . '/auction-analytics-toggle-' . bin2hex(random_bytes(5));
mkdir($root);
$source = file_get_contents(dirname(__DIR__) . '/private/analytics_control.php');
$source = str_replace("__DIR__ . '/analytics.disabled'", "getenv('ANALYTICS_TEST_MARKER')", $source);
$testFile = $root . '/control.php';
file_put_contents($testFile, $source);
putenv('ANALYTICS_TEST_MARKER=' . $root . '/analytics.disabled');
require $testFile;
if (!analytics_collection_enabled()) throw new RuntimeException('Expected enabled by default');
set_analytics_collection_enabled(false);
if (analytics_collection_enabled()) throw new RuntimeException('Expected disabled');
set_analytics_collection_enabled(false);
if (analytics_collection_enabled()) throw new RuntimeException('Repeated disable failed');
set_analytics_collection_enabled(true);
if (!analytics_collection_enabled()) throw new RuntimeException('Expected re-enabled');
unlink($testFile);
rmdir($root);
echo "PASS analytics toggle persists and is reversible without touching visitor records\n";
