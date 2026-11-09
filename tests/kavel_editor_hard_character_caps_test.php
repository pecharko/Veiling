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
foreach (['description' => 30000, 'details' => 10000] as $field => $limit) {
  if (!preg_match('~<textarea\\b[^>]*\\bname="' . preg_quote($field, '~') . '"[^>]*\\bmaxlength="' . $limit . '"~', $editor)) {
    throw new RuntimeException("Missing textarea max length on $field ($limit)");
  }
}
foreach (['title', 'subtitle', 'artist_name'] as $field) {
  if (!str_contains($editor, "name=\"$field\"") || !str_contains($editor, "lot-" . ($field === 'artist_name' ? 'artist' : $field) . "-error")) {
    throw new RuntimeException("Missing existing inline validation for $field");
  }
}
if (!str_contains($editor, "elseif(mb_strlen(\$subtitle)>50)")) throw new RuntimeException('Server subtitle validation missing');
if (!str_contains($editor, "elseif(mb_strlen(\$title)>50)")) throw new RuntimeException('Server title validation missing');
if (!str_contains($editor, "mb_strlen(\$artistName, 'UTF-8') > 50")) throw new RuntimeException('Server artist validation missing');
echo "PASS: hard browser character caps for all seven limited editor text fields.\n";
