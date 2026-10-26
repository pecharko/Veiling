<?php
declare(strict_types=1);

/**
 * Destructive, explicit administrator operation.
 * Caller holds the catalog advisory mutex and has checked CSRF/role/confirmation.
 * Snapshot ZIP files are not touched. DB deletion and registry reset are atomic.
 */
function reset_catalog_lots(PDO $pdo): array {
  if ($pdo->inTransaction()) throw new LogicException('Catalog reset requires a new transaction.');
  $paths = [];
  try {
    $pdo->beginTransaction();
    $paths = $pdo->query('SELECT path FROM lot_images')->fetchAll(PDO::FETCH_COLUMN);
    $count = (int)$pdo->query('SELECT COUNT(*) FROM lots')->fetchColumn();
    $pdo->exec('DELETE FROM lot_images');
    $pdo->exec('DELETE FROM lots');
    $pdo->exec('DELETE FROM issued_lot_numbers');
    $pdo->commit();
    return ['deleted' => $count, 'paths' => $paths];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

/** Remove only files referenced by the deleted rows and inside the upload root. */
function reset_catalog_photo_files(array $paths, string $publicRoot): int {
  $failed = 0;
  foreach ($paths as $path) {
    if (!is_string($path) || !preg_match('~^uploads/[0-9]{4}/[0-9]{2}/[a-f0-9]{32}\\.(webp|jpg|png)$~', $path)) continue;
    $file = rtrim($publicRoot, '/') . '/' . $path;
    if (is_file($file) && !@unlink($file)) $failed++;
  }
  return $failed;
}
