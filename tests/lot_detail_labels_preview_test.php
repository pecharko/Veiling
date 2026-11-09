<?php
declare(strict_types=1);
// Public lot detail sections are individually labeled without changing storage or escaping.
$root = dirname(__DIR__);
$detail = file_get_contents($root . '/catalogus-public/kavel.php');
$css = file_get_contents($root . '/catalogus-public/assets/catalogus.css');
if (!is_string($detail) || !is_string($css)) throw new RuntimeException('Missing public detail sources');
foreach ([
  'description' => 'Beschrijving',
  'details' => 'Details, afmetingen en staat',
] as $field => $label) {
  $class = $field === 'description' ? 'detail-description' : 'detail-specs';
  $template = '<?php if($lot[\'' . $field . '\']): ?><div class="' . $class . '"><span class="detail-field-label">' . $label . '</span><div class="detail-field-value"><?=nl2br(e($lot[\'' . $field . '\']))?></div></div><?php endif; ?>';
  if (!str_contains($detail, $template)) throw new RuntimeException('Missing labeled/escaped conditional field: ' . $field);
}
if (!str_contains($css, '.detail-page-grid .detail-field-label{display:block;') ||
    !str_contains($css, '.detail-page-grid .detail-field-value{min-width:0;max-width:100%;overflow-wrap:anywhere}')) {
  throw new RuntimeException('Detail labels or long-token containment styling missing');
}
echo "PASS: public lot description and specifications have accessible labels.\n";
