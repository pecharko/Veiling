<?php
declare(strict_types=1);
require_once __DIR__.'/images.php';
require_once __DIR__.'/photo_delete_safety.php';

/** Only call within administrator-only requests holding the catalog mutex. */
function seller_image_rows(PDO $pdo,int $sellerId): array {
  $st=$pdo->prepare('SELECT id,seller_id,image_kind,slot,path FROM seller_images WHERE seller_id=? ORDER BY image_kind,slot');
  $st->execute([$sellerId]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}
function seller_image_check(string $kind,int $slot): void {
  if (!(($kind==='work' && $slot>=1 && $slot<=3) || ($kind==='portrait' && $slot===1))) {
    throw new InvalidArgumentException('Ongeldige fotopositie.');
  }
}
function seller_image_upload(PDO $pdo,int $sellerId,string $kind,int $slot,array $file): void {
  seller_image_check($kind,$slot);
  if (($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK
      || !isset($file['tmp_name']) || !is_string($file['tmp_name'])
      || !is_uploaded_file($file['tmp_name'])) {
    throw new RuntimeException('Selecteer een geldige foto om te uploaden.');
  }
  $st=$pdo->prepare('SELECT seller_type FROM lot_sellers WHERE id=?');
  $st->execute([$sellerId]);
  if ($st->fetchColumn()!=='kunstenaar') throw new InvalidArgumentException('Foto’s zijn alleen voor kunstenaars.');
  $path=store_image($file['tmp_name'],(string)($file['name']??'foto'));
  $previous=null;
  try {
    $pdo->beginTransaction();
    $find=$pdo->prepare('SELECT path FROM seller_images WHERE seller_id=? AND image_kind=? AND slot=? FOR UPDATE');
    $find->execute([$sellerId,$kind,$slot]);
    $previous=$find->fetchColumn();
    $save=$pdo->prepare('INSERT INTO seller_images (seller_id,image_kind,slot,path) VALUES (?,?,?,?)
      ON DUPLICATE KEY UPDATE path=VALUES(path)');
    $save->execute([$sellerId,$kind,$slot,$path]);
    $pdo->commit();
  } catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    @unlink(dirname(__DIR__).'/catalogus-public/'.$path);
    throw $ex;
  }
  if (is_string($previous) && $previous!==$path) {
    catalog_safe_unlink_photo($previous,dirname(__DIR__).'/catalogus-public');
  }
}
function seller_image_delete(PDO $pdo,int $sellerId,string $kind,int $slot): void {
  seller_image_check($kind,$slot);
  $pdo->beginTransaction();
  try {
    $st=$pdo->prepare('SELECT path FROM seller_images WHERE seller_id=? AND image_kind=? AND slot=? FOR UPDATE');
    $st->execute([$sellerId,$kind,$slot]);
    $path=$st->fetchColumn();
    if ($path===false) throw new InvalidArgumentException('Foto niet gevonden.');
    $pdo->prepare('DELETE FROM seller_images WHERE seller_id=? AND image_kind=? AND slot=?')->execute([$sellerId,$kind,$slot]);
    $pdo->commit();
  } catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $ex;
  }
  catalog_safe_unlink_photo((string)$path,dirname(__DIR__).'/catalogus-public');
}
function seller_image_clear(PDO $pdo,int $sellerId): void {
  $rows=seller_image_rows($pdo,$sellerId);
  $pdo->prepare('DELETE FROM seller_images WHERE seller_id=?')->execute([$sellerId]);
  foreach($rows as $row) catalog_safe_unlink_photo((string)$row['path'],dirname(__DIR__).'/catalogus-public');
}
