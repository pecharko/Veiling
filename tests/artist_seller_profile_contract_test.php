<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$editor=file_get_contents($root.'/catalogus-public/admin/verkoper.php');
$validation=file_get_contents($root.'/private/lot_sellers.php');
$images=file_get_contents($root.'/private/seller_images.php');
$schema=file_get_contents($root.'/database/migrations/20261111_seller_artist_profile.sql');
$snapshot=file_get_contents($root.'/private/snapshots.php');
$preview=file_get_contents($root.'/dev/preview/seed.php');
if (!is_string($editor)||!is_string($validation)||!is_string($images)||!is_string($schema)||!is_string($snapshot)||!is_string($preview))
  throw new RuntimeException('Artist profile source missing');
foreach (['name="artist_intro"','maxlength="500"','enctype="multipart/form-data"',
  'name="photo_work_<?=$slot?>"','name="photo_portrait_1"','seller_image_upload(',
  'seller_image_delete(','id="seller-artist-fields"'] as $key)
  if (!str_contains($editor,$key)) throw new RuntimeException('Artist editor missing: '.$key);
foreach (['artist_intro'=>500] as $field=>$maximum)
  if (!str_contains($validation,"'".$field."'=>".$maximum)) throw new RuntimeException('Artist intro length limit missing');
foreach (['seller_image_check','work','portrait','slot>=1 && $slot<=3',"slot===1",'is_uploaded_file','store_image',
  'catalog_safe_unlink_photo'] as $required)
  if (!str_contains($images,$required)) throw new RuntimeException('Artist image safety rule missing: '.$required);
foreach (['artist_intro VARCHAR(500)','CREATE TABLE seller_images','fk_seller_images_seller',
  'uq_seller_image_slot','chk_seller_image_slot'] as $required)
  if (!str_contains($schema,$required)) throw new RuntimeException('Artist profile schema missing: '.$required);
foreach (['seller_images','artist_intro','41lokeren-v5-seller-mobile','snap_require_referenced_photos'] as $required)
  if (!str_contains($snapshot,$required)) throw new RuntimeException('Snapshot gallery support missing: '.$required);
if (!str_contains($preview,"LOCAL_PREVIEW_ISOLATED") || !str_contains($preview,'20261111_seller_artist_profile.sql'))
  throw new RuntimeException('Local preview-only gallery migration missing');
require_once $root.'/private/seller_images.php';
foreach ([['work',1],['work',2],['work',3],['portrait',1]] as [$kind,$slot]) seller_image_check($kind,$slot);
foreach ([['work',0],['work',4],['portrait',2],['invalid',1]] as [$kind,$slot]) {
  try { seller_image_check($kind,$slot); throw new RuntimeException('Invalid image slot accepted'); }
  catch (InvalidArgumentException) {}
}
require_once $root.'/private/lot_sellers.php';
$base=['seller_type'=>'kunstenaar','name'=>'Fictieve maker','email'=>'maker@example.invalid',
  'street'=>'Teststraat','house_number'=>'4','postal_code'=>'9160','municipality'=>'Lokeren',
  'artist_name'=>'Maker','artist_url'=>'https://example.org/'];
$valid=seller_clean($base+['artist_intro'=>str_repeat('A',500)]);
if (mb_strlen($valid['artist_intro'])!==500) throw new RuntimeException('500-character artist intro rejected');
$multiline=seller_clean($base+['artist_intro'=>"Eerste regel\r\nTweede regel"]);
if ($multiline['artist_intro']!=="Eerste regel\nTweede regel")
  throw new RuntimeException('Multiline artist introduction should normalize line endings');
try {
  seller_clean($base+['artist_intro'=>str_repeat('A',501)]);
  throw new RuntimeException('501-character artist intro accepted');
} catch (InvalidArgumentException) {}
$private=seller_clean(array_merge($base,['seller_type'=>'particulier','artist_intro'=>'should clear']));
if ($private['artist_intro']!=='') throw new RuntimeException('Private seller retained artist introduction');
echo "PASS artist introduction, 3 work slots, portrait, upload safety and snapshot scope.\n";
