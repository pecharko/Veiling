<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/private/bootstrap.php';
require_auth();
require_no_index();

function upload_limit_bytes(string $value): int {
  $value = trim($value);
  if ($value === '') return 0;
  $number = (float)$value;
  $last = strtolower(substr($value, -1));
  return (int)($number * match ($last) { 'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1 });
}
function report_item(array &$rows, string $title, bool $okay, string $detail, bool $warning = false): void {
  $rows[] = ['title' => $title, 'state' => $okay ? 'OK' : ($warning ? 'AANDACHT' : 'FOUT'), 'detail' => $detail];
}
$rows = [];
report_item($rows, 'PHP-versie', PHP_VERSION_ID >= 80200, 'Gevonden: PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '. Deze catalogus verwacht minimaal 8.2.');
foreach (['pdo_mysql' => 'MySQL via PDO', 'mbstring' => 'Unicode / multibyte-tekst', 'fileinfo' => 'Controle van afbeeldingsbestanden', 'session' => 'Beheerderlogin'] as $extension => $label) {
  report_item($rows, $label, extension_loaded($extension), $extension . ' moet actief zijn.');
}
report_item($rows, 'Foto-optimalisatie (optioneel)', extension_loaded('gd') && function_exists('imagewebp'),
  'Bij ontbreken van PHP-GD worden originele JPG/PNG/WebP bestanden bewaard; de catalogus blijft werken.', true);
$root = dirname(__DIR__);
$private = dirname(__DIR__, 2) . '/private';
$privateReal = realpath($private) ?: $private;
$rootReal = realpath($root) ?: $root;
report_item($rows, 'Configuratie buiten publieke map',
  is_file($private . '/config.php') && !str_starts_with($privateReal . '/', $rootReal . '/'),
  'private/config.php moet buiten catalogus-public staan. De waarden van het bestand worden hier niet getoond.');
report_item($rows, 'Fotomap voor publieke afbeeldingen',
  is_dir($root . '/uploads') && is_writable($root . '/uploads'),
  'catalogus-public/uploads/ moet bestaan en voor PHP beschrijfbaar zijn.');
$https = isset($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off' && (string)$_SERVER['HTTPS'] !== '';
report_item($rows, 'HTTPS', $https, 'Open de beheeromgeving uitsluitend op een adres dat met https:// begint.');
$upload = upload_limit_bytes((string)ini_get('upload_max_filesize'));
$post = upload_limit_bytes((string)ini_get('post_max_size'));
report_item($rows, 'PHP-uploadlimiet (foto’s)', $upload >= 8*1048576 && $post >= 9*1048576,
  'upload_max_filesize=' . ini_get('upload_max_filesize') . ', post_max_size=' . ini_get('post_max_size') . '. Foto’s voeg je afzonderlijk toe bij het aanmaken of bewerken van een kavel.', true);
$dbOkay = false;
$dbMessage = 'Nog niet gecontroleerd.';
try {
  $pdo = db();
  $pdo->query('SELECT 1')->fetchColumn();
  foreach (['settings','admins','lots','lot_images','login_attempts'] as $table) {
    $pdo->query('SELECT 1 FROM `' . $table . '` LIMIT 1');
  }
  $dbOkay = true;
  $dbMessage = 'Databaseverbinding en de vijf benodigde tabellen zijn bereikbaar.';
} catch (Throwable $err) {
  error_log('Catalogus systeemcheck database: ' . $err->getMessage());
  $dbMessage = 'Databaseverbinding of install.sql-tabellen ontbreken. Kijk in OVHcloud > Databases en importeer sql/install.sql in de juiste database.';
}
report_item($rows, 'MySQL en database-tabellen', $dbOkay, $dbMessage);
$errors = count(array_filter($rows, static fn(array $r) => $r['state'] === 'FOUT'));
$warn = count(array_filter($rows, static fn(array $r) => $r['state'] === 'AANDACHT'));
admin_top('Systeemcontrole OVHcloud');
?>
<div class="panel form-panel">
  <p class="lead">Deze controle helpt om de installatie bij OVHcloud te valideren. Er worden geen wachtwoorden, API-sleutels of databasegebruikers getoond.</p>
  <div class="import-status"><strong><?= $errors === 0 ? 'Basis in orde' : $errors . ' fout(en)' ?></strong> <?= $warn ? ' · ' . $warn . ' aandachtspunt(en)' : '' ?></div>
  <table style="min-width:0"><thead><tr><th>Controle</th><th>Resultaat</th><th>Toelichting</th></tr></thead><tbody>
  <?php foreach ($rows as $row): ?>
    <tr><td><strong><?= e($row['title']) ?></strong></td><td><span class="status <?= $row['state'] === 'OK' ? 'live' : 'draft' ?>"><?= e($row['state']) ?></span></td><td><?= e($row['detail']) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <p class="hint">Belangrijk: een geslaagde systeemcheck is geen volledige beveiligingsaudit. Test ook de publieke catalogus, uploads, login en back-ups voordat je bezoekers uitnodigt.</p>
  <div class="form-actions"><a class="secondary" href="<?= e(url('admin/index.php')) ?>">Terug naar beheer</a><a class="primary" href="<?= e(url('admin/systeemcheck.php')) ?>">Controle vernieuwen ↻</a></div>
</div>
<?php admin_bottom(); ?>
