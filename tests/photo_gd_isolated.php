<?php
declare(strict_types=1);
// Isolated GD integration test: no database, no OVH, no actual uploads.
if (!extension_loaded('gd') || !function_exists('imagewebp')) {
  fwrite(STDERR, "GD with WebP required for this test.\n"); exit(1);
}
$root = dirname(__DIR__);
$source = file_get_contents($root . '/private/images.php');
if ($source === false) throw new RuntimeException('Missing image code');
$needle = "require_once __DIR__ . '/bootstrap.php';";
if (substr_count($source, $needle) !== 1) throw new RuntimeException('Bootstrap structure changed');
$source = str_replace($needle, '', $source);
// eval() cannot accept a strict_types declaration after its opening tag.
$source = preg_replace('/^<\\?php\\s*declare\\(strict_types=1\\);/', '<?php', $source, 1);
if ($source === null) throw new RuntimeException('Could not prepare isolated code');
eval('?>' . $source);
$tmp = sys_get_temp_dir() . '/veiling-gd-' . bin2hex(random_bytes(6));
if (!mkdir($tmp)) throw new RuntimeException('Failed to create temporary directory');
$produced = [];
try {
  foreach (['jpeg', 'png'] as $format) {
    $im = imagecreatetruecolor(3600, 2400);
    $color = imagecolorallocate($im, 20, 80, 130);
    imagefilledrectangle($im, 0, 0, 3599, 2399, $color);
    $input = $tmp . '/image.' . ($format === 'jpeg' ? 'jpg' : 'png');
    $saved = $format === 'jpeg' ? imagejpeg($im, $input, 90) : imagepng($im, $input);
    imagedestroy($im);
    if (!$saved) throw new RuntimeException('Could not create input');
    $relative = store_image($input, basename($input));
    $target = $root . '/catalogus-public/' . $relative;
    $produced[] = $target;
    $info = getimagesize($target);
    if (!$info || $info['mime'] !== 'image/webp' || $info[0] !== 1800 || $info[1] !== 1200) {
      throw new RuntimeException('Incorrect WebP dimensions or format for ' . $format);
    }
    echo "PASS: $format 3600x2400 -> WebP 1800x1200\n";
  }
  $oversized = $tmp . '/oversized.jpg';
  $handle = fopen($oversized, 'wb');
  ftruncate($handle, 16 * 1024 * 1024 + 1);
  fclose($handle);
  try { store_image($oversized, 'oversized.jpg'); throw new RuntimeException('Oversized upload accepted'); }
  catch (RuntimeException $e) {
    if (!str_contains($e->getMessage(), 'groter dan 16 MB')) throw $e;
    echo "PASS: upload above 16 MB rejected\n";
  }
} finally {
  foreach ($produced as $path) if (is_file($path)) unlink($path);
  foreach (glob($tmp . '/*') ?: [] as $path) unlink($path);
  rmdir($tmp);
}
echo "PASS: isolated GD integration; 48 MP peak-memory test remains open\n";
