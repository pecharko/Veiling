<?php
declare(strict_types=1);
$source = file_get_contents(dirname(__DIR__) . '/catalogus-public/admin/statistieken.php');
if (!is_string($source)) throw new RuntimeException('Admin statistics missing');
foreach ([
 '$cronSuccessful = array_slice',
 '$cronAlerts = array_slice',
 '($run[\'status\'] ?? \'\') === \'ok\'',
 "['error', 'limit']",
 'Geen geslaagde uitvoeringen in de beschikbare loghistoriek.',
 'Geen fouten of waarschuwingen in de beschikbare loghistoriek.',
 'Waarschuwing: inhaallimiet bereikt',
] as $required) {
 if (!str_contains($source, $required)) throw new RuntimeException('Missing cron display contract: ' . $required);
}
if (substr_count($source, '0, 3);') !== 2) throw new RuntimeException('Expected two limits of three runs');
echo "Cron log summary UI contract OK\n";
