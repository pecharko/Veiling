<?php
declare(strict_types=1);
require dirname(__DIR__) . '/private/lot_form.php';
$cases = [
  [[], 1],
  [[1, 2, 3], 4],
  [[2, 3, 4], 1],
  [[1, 3, 4], 2],
  [[1, 1, 2, 4], 3],
  [[1, 2, 999999], 3],
  [[999999], 1],
];
foreach ($cases as [$numbers, $expected]) {
  $actual = first_available_lot_number($numbers);
  if ($actual !== $expected) {
    throw new RuntimeException('Expected ' . $expected . ', got ' . var_export($actual, true));
  }
}
echo "PASS first available lot number tests\\n";
