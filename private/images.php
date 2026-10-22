<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

/** Controleer bestanden op echte afbeeldingsinhoud en bewaar ze onder een nieuwe naam. */
function store_image(string $inputFile, string $origName): string {
  if (!is_file($inputFile)) throw new RuntimeException('Het fotobestand ontbreekt.');
  $size = filesize($inputFile);
  if (!$size || $size > 16 * 1024 * 1024) throw new RuntimeException('Foto groter dan 16 MB of leeg.');
  $info = @getimagesize($inputFile);
  if (!$info || $info[0] < 20 || $info[1] < 20 || (float)$info[0] * $info[1] > 48000000) {
    throw new RuntimeException('Ongeldige foto of te hoge resolutie (maximaal 48 MP).');
  }
  $mime = (new finfo(FILEINFO_MIME_TYPE))->file($inputFile);
  if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || $info['mime'] !== $mime) {
    throw new RuntimeException('Alleen JPG, PNG en WebP zijn toegestaan.');
  }
  $functions = ['imagewebp', 'imagecreatetruecolor', 'imagecopyresampled',
    'imagecreatefromjpeg', 'imagecreatefrompng', 'imagecreatefromwebp'];
  foreach ($functions as $function) {
    if (!function_exists($function)) {
      throw new RuntimeException('Automatische foto-optimalisatie is niet beschikbaar op de server.');
    }
  }
  // GD decodes the full original into memory before resizing. Fail before decode
  // when the configured limit cannot safely accommodate both decoded images.
  $limitText = trim((string)ini_get('memory_limit'));
  $unit = strtolower(substr($limitText, -1));
  $limit = (float)$limitText;
  if ($unit === 'g') $limit *= 1024 * 1024 * 1024;
  elseif ($unit === 'm') $limit *= 1024 * 1024;
  elseif ($unit === 'k') $limit *= 1024;
  // Estimate conservatively: decoded source plus working/intermediate allocations.
  $required = (float)$info[0] * $info[1] * 6 + 64 * 1024 * 1024;
  if ($limitText !== '-1' && ($limit <= 0 || memory_get_usage(true) + $required > $limit)) {
    throw new RuntimeException('Deze foto vraagt te veel servergeheugen. Verklein ze vooraf of kies een kleiner bestand.');
  }
  $scale = min(1, 1800 / max($info[0], $info[1]));
  $width = max(1, (int)round($info[0] * $scale));
  $height = max(1, (int)round($info[1] * $scale));
  $source = match ($mime) {
    'image/jpeg' => @imagecreatefromjpeg($inputFile),
    'image/png' => @imagecreatefrompng($inputFile),
    'image/webp' => @imagecreatefromwebp($inputFile),
  };
  if ($source === false) throw new RuntimeException('De foto kan niet worden gelezen. Probeer een ander JPG-, PNG- of WebP-bestand.');
  try {
    $output = imagecreatetruecolor($width, $height);
    if ($output === false) throw new RuntimeException('De foto kan niet worden verwerkt.');
    try {
      imagealphablending($output, false);
      imagesavealpha($output, true);
      $transparent = imagecolorallocatealpha($output, 0, 0, 0, 127);
      imagefill($output, 0, 0, $transparent);
      if (!imagecopyresampled($output, $source, 0, 0, 0, 0, $width, $height, $info[0], $info[1])) {
        throw new RuntimeException('De foto kan niet worden verkleind.');
      }
      $relativeDir = 'uploads/' . date('Y/m');
      $absoluteDir = dirname(__DIR__) . '/catalogus-public/' . $relativeDir;
      if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
        throw new RuntimeException('Kan de fotomap niet maken.');
      }
      $filename = bin2hex(random_bytes(16));
      $dest = $absoluteDir . '/' . $filename . '.webp';
      if (!imagewebp($output, $dest, 83) || !is_file($dest) || filesize($dest) === 0) {
        @unlink($dest);
        throw new RuntimeException('Foto-optimalisatie mislukt; het originele bestand is niet opgeslagen.');
      }
      return $relativeDir . '/' . $filename . '.webp';
    } finally {
      imagedestroy($output);
    }
  } finally {
    imagedestroy($source);
  }
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
