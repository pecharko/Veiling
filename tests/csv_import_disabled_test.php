<?php
declare(strict_types=1);

/**
 * Broncodebewaking voor uitgeschakelde CSV- en bulkfoto-import.
 * Dit is geen vervanging voor een HTTP/POST-smoketest op de hosting.
 */
$root = dirname(__DIR__);
$inactiveRoute = (string)file_get_contents($root . '/catalogus-public/admin/import.php');
$nav = (string)file_get_contents($root . '/private/bootstrap.php');
$dashboard = (string)file_get_contents($root . '/catalogus-public/admin/index.php');
$photoRoute = (string)file_get_contents($root . '/catalogus-public/admin/fotos-bulk.php');
$editor = (string)file_get_contents($root . '/catalogus-public/admin/kavel.php');
$backup = (string)file_get_contents($root . '/catalogus-public/admin/backup.php');
$health = (string)file_get_contents($root . '/catalogus-public/admin/systeemcheck.php');

$assert = static function (bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($inactiveRoute, 'http_response_code(404);'), 'CSV-route moet 404 geven.');
$assert(str_contains($inactiveRoute, 'exit;'), 'CSV-route moet direct stoppen.');
$assert(!str_contains($inactiveRoute, "require_auth()"), 'CSV-route moet niet bij beheerlogica komen.');
$assert(!preg_match('/\\b(?:INSERT|UPDATE|DELETE)\\s+(?:INTO|FROM|lots)\\b/i', $inactiveRoute), 'CSV-route bevat nog muterende SQL.');
$assert(!str_contains($nav, "admin/import.php"), 'CSV-menu-entry is nog zichtbaar.');
$assert(!str_contains($dashboard, "admin/import.php"), 'CSV-dashboardactie is nog zichtbaar.');

$assert(str_contains($photoRoute, 'http_response_code(404);'), 'Bulkfoto-route moet 404 geven.');
$assert(str_contains($photoRoute, 'exit;'), 'Bulkfoto-route moet direct stoppen.');
$assert(!preg_match('/\\b(?:require|include)(?:_once)?\\b|(?:add_lot_image|file_put_contents|unlink|\\bdb\\s*\\()/i', $photoRoute), 'Bulkfoto-route mag geen data lezen of muteren.');
$assert(!str_contains($nav, 'fotos-bulk.php'), 'Foto-import mag niet in het adminmenu staan.');
$assert(!str_contains($health, 'photo-inbox') && !str_contains($health, 'fotobulkmap'), 'Systeemcheck mag geen oude bulkfoto-map vereisen.');
$assert(str_contains($editor, 'name="photos[]"'), 'Foto-upload per kavel moet beschikbaar blijven.');
$assert(!str_contains($editor, 'gebruik je de foto-import') && !str_contains($backup, 'of foto-import'), 'Verouderde bulkimportinstructies mogen niet terugkeren.');

echo "CSV-import (6) en bulkfoto-import (7): 13 broncodecontroles geslaagd\n";
