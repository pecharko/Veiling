<?php
declare(strict_types=1);

/**
 * Broncodebewaking voor de tijdelijk uitgeschakelde CSV-import.
 * Dit is geen vervanging voor een HTTP/POST-smoketest op de hosting.
 */
$root = dirname(__DIR__);
$inactiveRoute = (string)file_get_contents($root . '/catalogus-public/admin/import.php');
$nav = (string)file_get_contents($root . '/private/bootstrap.php');
$dashboard = (string)file_get_contents($root . '/catalogus-public/admin/index.php');

$assert = static function (bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($inactiveRoute, 'http_response_code(404);'), 'CSV-route moet 404 geven.');
$assert(str_contains($inactiveRoute, 'exit;'), 'CSV-route moet direct stoppen.');
$assert(!str_contains($inactiveRoute, "require_auth()"), 'CSV-route moet niet bij beheerlogica komen.');
$assert(!preg_match('/\\b(?:INSERT|UPDATE|DELETE)\\s+(?:INTO|FROM|lots)\\b/i', $inactiveRoute), 'CSV-route bevat nog muterende SQL.');
$assert(!str_contains($nav, "admin/import.php"), 'CSV-menu-entry is nog zichtbaar.');
$assert(!str_contains($dashboard, "admin/import.php"), 'CSV-dashboardactie is nog zichtbaar.');

echo "CSV-import geblokkeerd in broncode: 6 controles geslaagd\\n";
