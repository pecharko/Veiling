<?php
declare(strict_types=1);
// Offline contract: character caps, preserved database columns, catalog overflow and detail field labels.
$root = dirname(__DIR__);
$editor = file_get_contents($root . '/catalogus-public/admin/kavel.php');
$catalog = file_get_contents($root . '/catalogus-public/index.php');
$detail = file_get_contents($root . '/catalogus-public/kavel.php');
$css = file_get_contents($root . '/catalogus-public/assets/catalogus.css');
if (!is_string($editor) || !is_string($catalog) || !is_string($detail) || !is_string($css)) {
  throw new RuntimeException('Missing public/editor source');
}
function preview_assert(bool $valid, string $message): void {
  if (!$valid) throw new RuntimeException($message);
}
foreach (['title'=>50,'subtitle'=>50,'artist_name'=>50,'category'=>120,'artist_url'=>2048] as $field=>$limit) {
  preview_assert((bool)preg_match('~<input\\b[^>]*\\bname="'.preg_quote($field,'~').'"[^>]*\\bmaxlength="'.$limit.'"~', $editor), "$field HTML limit");
}
foreach (['description'=>250,'details'=>100] as $field=>$limit) {
  preview_assert((bool)preg_match('~<textarea\\b[^>]*\\bname="'.preg_quote($field,'~').'"[^>]*\\bmaxlength="'.$limit.'"~', $editor), "$field textarea HTML limit");
  preview_assert(str_contains($editor, 'elseif(mb_strlen($'.$field.')>'.$limit.')'), "$field server validation");
}
preview_assert(str_contains($editor, '<label class="lot-full">Ondertitel<input') && !str_contains($editor, 'Ondertitel / maker / materiaal'), 'Subtitle editor label');
preview_assert(str_contains($editor, "['subtitle', 'lot-subtitle-error', 'De ondertitel mag maximaal 50 tekens bevatten.']"), 'Live subtitle warning');
preview_assert(str_contains($editor, "elseif(mb_strlen(\$subtitle)>50)"), 'Subtitle server length check');
preview_assert(str_contains($editor, 'UPDATE lots SET lot_number=?,title=?,subtitle=?') && str_contains($editor, 'INSERT INTO lots (lot_number,title,subtitle,'), 'Unchanged subtitle DB field');
preview_assert(str_contains($catalog, '<p class="lot-subtitle"><?=e($lot[\'subtitle\'])?></p>'), 'Full escaped subtitle');
preview_assert(str_contains($css, '.lot-card .lot-subtitle{min-width:0;max-width:100%;overflow-wrap:anywhere;word-break:normal}'), 'No catalog subtitle overflow');
foreach (['description'=>'Beschrijving','details'=>'Details, afmetingen en staat'] as $field=>$label) {
  $container = $field === 'description' ? 'detail-description' : 'detail-specs';
  $markup = '<?php if($lot[\''.$field.'\']): ?><div class="'.$container.'"><span class="detail-field-label">'.$label.'</span><div class="detail-field-value"><?=nl2br(e($lot[\''.$field.'\']))?></div></div><?php endif; ?>';
  preview_assert(str_contains($detail, $markup), "Labeled and escaped $field");
}
preview_assert(str_contains($css, '.detail-page-grid .detail-field-label{display:block;'), 'Visible detail field labels');
echo "PASS: editor limits, DB continuity, catalog subtitle wrap and detail labels.\n";
