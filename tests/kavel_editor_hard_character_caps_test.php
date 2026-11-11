<?php
declare(strict_types=1);
// Offline regression: browser entry limits mirror existing server limits.
$editor = file_get_contents(dirname(__DIR__) . '/catalogus-public/admin/kavel.php');
if (!is_string($editor)) throw new RuntimeException('Missing editor');
foreach ([
  'title' => 50,
  'subtitle' => 50,
  'category' => 120,
] as $field => $limit) {
  // PHP template values contain ?>, so do not use an [^>]* match over the input tag.
  if (!str_contains($editor, 'name="' . $field . '" value="<?=e($d[\'' . $field . '\'])?>" maxlength="' . $limit . '"')) {
    throw new RuntimeException("Missing native input maxlength on $field ($limit)");
  }
}
foreach (['description' => 250, 'details' => 100] as $field => $limit) {
  if (!str_contains($editor, 'name="' . $field . '" rows="' . ($field === 'description' ? '6' : '4') . '" maxlength="' . $limit . '"')) {
    throw new RuntimeException("Missing native textarea maxlength on $field ($limit)");
  }
}
foreach (['lot-title-error', 'lot-subtitle-error', 'lot-artist-error', 'function validateTitle()', 'function validateSubtitle()', 'function validateArtistName()'] as $obsolete) {
  if (str_contains($editor, $obsolete)) throw new RuntimeException("Redundant UI length error remains: $obsolete");
}
foreach (['description', 'details'] as $field) {
  if (!str_contains($editor, 'str_replace(["\\r\\n", "\\r"], "\\n", (string)($_POST[\'' . $field . '\']??\'\'))')) {
    throw new RuntimeException("Missing line-ending normalization: $field");
  }
}
if (!str_contains($editor, "elseif(mb_strlen(\$subtitle)>50)")) throw new RuntimeException('Server subtitle validation missing');
if (!str_contains($editor, "elseif(mb_strlen(\$description)>250)")) throw new RuntimeException('Server description validation missing');
if (!str_contains($editor, "elseif(mb_strlen(\$details)>100)")) throw new RuntimeException('Server details validation missing');
if (!str_contains($editor, "elseif(mb_strlen(\$title)>50)")) throw new RuntimeException('Server title validation missing');
$sellerEditor=file_get_contents(dirname(__DIR__) . '/catalogus-public/admin/verkoper.php');
if (!is_string($sellerEditor) || !str_contains($sellerEditor, 'name="artist_name" maxlength="50"')
    || !str_contains($sellerEditor, 'name="artist_url" maxlength="2048"'))
  throw new RuntimeException('Artist field caps must be enforced by central seller editor');
$artistValidator = file_get_contents(dirname(__DIR__) . '/private/lot_artist.php');
if (!is_string($artistValidator) || !str_contains($artistValidator, 'maximaal 50 tekens')) throw new RuntimeException('Server artist validation missing');
echo "PASS: hard browser character caps for lot fields and delegated seller artist limits.\n";
