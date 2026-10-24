<?php
declare(strict_types=1);
// Offline regression contract: first photo matches public catalog cover ordering.
$source = file_get_contents(dirname(__DIR__) . '/catalogus-public/admin/index.php');
foreach ([
  'SELECT li.path FROM lot_images li WHERE li.lot_id=l.id ORDER BY li.sort_order, li.id LIMIT 1',
  "url(\$row['cover'])",
  'loading="lazy"',
  'dashboard-cover-empty',
  'colspan="7"'
] as $expected) {
  if (!str_contains($source, $expected)) {
    throw new RuntimeException('Missing admin cover photo contract: ' . $expected);
  }
}
echo "PASS admin lot cover contracts\n";
