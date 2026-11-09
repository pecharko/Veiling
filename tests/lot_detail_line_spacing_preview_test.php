<?php
declare(strict_types=1);
// nl2br() supplies the line breaks; CSS must not preserve the same newline a second time.
$root = dirname(__DIR__);
$detail = file_get_contents($root . '/catalogus-public/kavel.php');
$css = file_get_contents($root . '/catalogus-public/assets/catalogus.css');
if (!is_string($detail) || !is_string($css)) throw new RuntimeException('Missing detail source');
foreach (['description', 'details'] as $field) {
  if (!str_contains($detail, "<?=nl2br(e(\$lot['$field']))?>")) {
    throw new RuntimeException("$field must retain safe newline conversion");
  }
}
if (!str_contains($css, '.detail-page-grid .detail-field-value{min-width:0;max-width:100%;overflow-wrap:anywhere;white-space:normal;line-height:1.55}')) {
  throw new RuntimeException('Detail values must not double-render nl2br line breaks');
}
if (!str_contains($detail, 'class="detail-field-value"')) throw new RuntimeException('Detail field wrapper missing');
echo "PASS: description and details render each entered newline once.\n";
