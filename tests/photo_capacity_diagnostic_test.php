<?php
declare(strict_types=1);
$path = dirname(__DIR__) . '/catalogus-public/admin/foto-capaciteit.php';
$source = file_get_contents($path);
if ($source === false) throw new RuntimeException('Missing photo capacity diagnostic');
foreach (["require_auth();", "require_no_index();", "memory_limit", "upload_max_filesize",
  "post_max_size", "extension_loaded('gd')", "imagewebp", "imagecreatefromwebp",
  "function_exists(", "admin_top(", "e($label)", "e($value"] as $needle) {
  if (!str_contains($source, $needle)) throw new RuntimeException('Missing safeguard: ' . $needle);
}
foreach (["phpinfo(", "file_put_contents(", "move_uploaded_file(", "ini_set(", "exec("] as $needle) {
  if (str_contains($source, $needle)) throw new RuntimeException('Unexpected mutation or disclosure: ' . $needle);
}
echo "PASS: admin-only read-only photo runtime diagnostic contract\n";
