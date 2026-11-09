<?php
declare(strict_types=1);
// Offline regression: browser entry limits mirror existing server limits.
$editor = file_get_contents(dirname(__DIR__) . '/catalogus-public/admin/kavel.php');
if (!is_string($editor)) throw new RuntimeException('Missing editor');
foreach ([
  'title' => 50,
  'subtitle' => 50,
  'artist_name' => 50,
  'category' => 120,
  'artist_url' => 2048,
] as $field => $limit) {
  if (!preg_match('~<input\\b[^>]*\\bname="' . preg_quote($field, '~') . '"[^>]*\\bmaxlength="' . $limit . '"~', $editor)) {
    throw new RuntimeException("Missing browser max length on $field ($limit)");
  }
}
foreach (['description' => 250, 'details' => 100] as $field => $limit) {
  if (!preg_match('~<textarea\\b[^>]*\\bname="' . preg_quote($field, '~') . '"[^>]*\\bmaxlength="' . $limit . '"~', $editor)) {
    throw new RuntimeException("Missing textarea max length on $field ($limit)");
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
if (!str_contains($editor, "mb_strlen(\$artistName, 'UTF-8') > 50")) throw new RuntimeException('Server artist validation missing');
echo "PASS: hard browser character caps for all seven limited editor text fields.\n";
