<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

/** Controleer bestanden op echte afbeeldingsinhoud en bewaar ze onder een nieuwe naam. */
function store_image(string $inputFile, string $origName): string {
  if (!is_file($inputFile)) throw new RuntimeException('Het fotobestand ontbreekt.');
  $size = filesize($inputFile);
  if (!$size || $size > 8 * 1024 * 1024) throw new RuntimeException('Foto groter dan 8 MB of leeg.');
  $info = @getimagesize($inputFile);
  if (!$info || $info[0] < 20 || $info[1] < 20 || $info[0] * $info[1] > 12000000) {
    throw new RuntimeException('Ongeldige foto of foto met te veel pixels (maximaal 12 MP).');
  }
  $mime = (new finfo(FILEINFO_MIME_TYPE))->file($inputFile);
  $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
  if (!isset($allowed[$mime]) || $info['mime'] !== $mime) throw new RuntimeException('Alleen JPG, PNG en WebP zijn toegestaan.');
  $relativeDir = 'uploads/' . date('Y/m');
  $absoluteDir = dirname(__DIR__) . '/catalogus-public/' . $relativeDir;
  if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
    throw new RuntimeException('Kan de fotomap niet maken.');
  }
  $filename = bin2hex(random_bytes(16));
  $maxEdge = 1800;
  $scale = min(1, $maxEdge / max($info[0], $info[1]));
  $width = max(1, (int)round($info[0] * $scale));
  $height = max(1, (int)round($info[1] * $scale));
  $canConvert = function_exists('imagewebp') && function_exists('imagecreatetruecolor') && function_exists('imagecreatefromjpeg') && function_exists('imagecreatefrompng') && function_exists('imagecreatefromwebp');
  if ($canConvert) {
    $source = match ($mime) {
      'image/jpeg' => @imagecreatefromjpeg($inputFile),
      'image/png' => @imagecreatefrompng($inputFile),
      'image/webp' => @imagecreatefromwebp($inputFile),
    };
    if ($source !== false) {
      $output = imagecreatetruecolor($width, $height);
      imagealphablending($output, false);
      imagesavealpha($output, true);
      $transparent = imagecolorallocatealpha($output, 0, 0, 0, 127);
      imagefill($output, 0, 0, $transparent);
      imagecopyresampled($output, $source, 0, 0, 0, 0, $width, $height, $info[0], $info[1]);
      $dest = $absoluteDir . '/' . $filename . '.webp';
      $written = imagewebp($output, $dest, 83);
      imagedestroy($source);
      imagedestroy($output);
      if ($written) return $relativeDir . '/' . $filename . '.webp';
    }
  }
  // Fallback wanneer PHP-GD niet is geïnstalleerd: behoud het geverifieerde origineel.
  $dest = $absoluteDir . '/' . $filename . '.' . $allowed[$mime];
  if (!copy($inputFile, $dest)) throw new RuntimeException('Kon foto niet bewaren.');
  return $relativeDir . '/' . basename($dest);
}
function add_lot_image(int $lotId, string $source, string $origName, int $order = 99, ?string $sourceName = null): void {
  $pdo = db();
  $count = $pdo->prepare('SELECT COUNT(*) FROM lot_images WHERE lot_id = ?');
  $count->execute([$lotId]);
  if ((int)$count->fetchColumn() >= 16) throw new RuntimeException('Maximaal 16 foto’s per kavel.');
  $path = store_image($source, $origName);
  try {
    $st = $pdo->prepare('INSERT INTO lot_images (lot_id, path, sort_order, source_name) VALUES (?,?,?,?)');
    $st->execute([$lotId, $path, $order, $sourceName]);
  } catch (Throwable $e) {
    @unlink(dirname(__DIR__) . '/catalogus-public/' . $path);
    throw $e;
  }
}
function photo_files(array $fileGroup): array {
  if (!isset($fileGroup['name']) || !is_array($fileGroup['name'])) return [];
  $out = [];
  foreach ($fileGroup['name'] as $i => $name) {
    $error = (int)($fileGroup['error'][$i] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) continue;
    if ($error !== UPLOAD_ERR_OK) throw new RuntimeException('Uploadfout voor ' . basename((string)$name) . ' (code ' . $error . ').');
    $tmp = (string)$fileGroup['tmp_name'][$i];
    if (!is_uploaded_file($tmp)) throw new RuntimeException('Ongeldige upload.');
    $out[] = ['tmp' => $tmp, 'name' => basename((string)$name)];
  }
  return $out;
}
