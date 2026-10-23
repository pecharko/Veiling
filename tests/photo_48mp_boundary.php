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
$tmp = sys_get_temp_dir() . '/veiling-48mp-' . bin2hex(random_bytes(6));
if (!mkdir($tmp)) throw new RuntimeException('No temp directory');
$produced = [];
try {
  foreach (['jpeg', 'png'] as $format) {
    $image = imagecreatetruecolor(8000, 6000);
    if (!$image) throw new RuntimeException('Could not generate 48 MP test image');
    $color = imagecolorallocate($image, 30, 90, 130);
    imagefilledrectangle($image, 0, 0, 7999, 5999, $color);
    $input = $tmp . '/48mp.' . ($format === 'jpeg' ? 'jpg' : 'png');
    $written = $format === 'jpeg' ? imagejpeg($image, $input, 85) : imagepng($image, $input, 1);
    imagedestroy($image);
    if (!$written || filesize($input) > 16 * 1024 * 1024) {
      throw new RuntimeException('48 MP test input cannot be used within 16 MiB');
    }
    $relative = store_image($input, basename($input));
    $output = $root . '/catalogus-public/' . $relative;
    $produced[] = $output;
    $info = getimagesize($output);
    if (!$info || $info['mime'] !== 'image/webp' || $info[0] !== 1800 || $info[1] !== 1350) {
      throw new RuntimeException('Unexpected converted 48 MP output');
    }
    echo "PASS: $format 8000x6000 -> 1800x1350 WebP at 512M limit\n";
  }
} finally {
  foreach ($produced as $path) if (is_file($path)) unlink($path);
  foreach (glob($tmp . '/*') ?: [] as $path) unlink($path);
  rmdir($tmp);
}
echo "PASS: 48 MP boundary conversion in isolated CI environment\n";
