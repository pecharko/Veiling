<?php
declare(strict_types=1);
$source = file_get_contents(dirname(__DIR__) . '/catalogus-public/admin/kavel.php');
foreach ([
  "str_starts_with(\$error, 'Dit lotnummer bestaat al.')",
  "SELECT MAX(lot_number) FROM lots",
  "first_available_lot_number(",
  "Voorgesteld nieuw lotnummer:",
  "if (\$submitted !== null && \$suggestedNumber !== '') \$d['lot_number'] = (string)\$suggestedNumber;",
] as $needle) {
  if (!str_contains($source, $needle)) throw new RuntimeException('Missing duplicate-number suggestion contract: ' . $needle);
}
echo "PASS duplicate lot number suggestion contract\n";
