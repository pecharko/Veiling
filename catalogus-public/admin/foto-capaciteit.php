<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/private/bootstrap.php';
require_auth();
require_no_index();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
  header('Allow: GET', true, 405);
  exit;
}
header('Content-Type: text/html; charset=UTF-8');
header('Referrer-Policy: no-referrer');

$functions = ['imagecreatetruecolor', 'imagecreatefromjpeg', 'imagecreatefrompng',
  'imagecreatefromwebp', 'imagecopyresampled', 'imagewebp'];
$checks = [
  'PHP-geheugenlimiet' => ini_get('memory_limit'),
  'Maximale uploadbestandsgrootte' => ini_get('upload_max_filesize'),
  'Maximale POST-grootte' => ini_get('post_max_size'),
  'GD-extensie beschikbaar' => extension_loaded('gd') ? 'Ja' : 'Nee',
];
foreach ($functions as $name) {
  $checks[$name . ' beschikbaar'] = function_exists($name) ? 'Ja' : 'Nee';
}
admin_top('Fotocapaciteit server');
echo '<section class="lot-editor-card"><h2>Diagnose fotoverwerking</h2><p>Alleen uitlezen van PHP-instellingen; er worden geen foto’s verwerkt of opgeslagen.</p><dl>';
foreach ($checks as $label => $value) {
  echo '<dt>' . e($label) . '</dt><dd>' . e($value === false ? '(niet beschikbaar)' : $value) . '</dd>';
}
echo '</dl></section>';
admin_bottom();
