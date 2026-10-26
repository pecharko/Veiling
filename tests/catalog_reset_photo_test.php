<?php
declare(strict_types=1);
require dirname(__DIR__).'/private/catalog_reset.php';
$root=sys_get_temp_dir().'/catalog-reset-photo-'.bin2hex(random_bytes(6));
$dir=$root.'/uploads/2026/10';
if(!mkdir($dir,0700,true)) throw new RuntimeException('Cannot create test dir');
$name=str_repeat('a',32).'.webp';
$file=$dir.'/'.$name;
file_put_contents($file,'isolated-test-image');
$outside=$root.'/do-not-delete.txt';
file_put_contents($outside,'keep');
try {
  $failed=reset_catalog_photo_files(['uploads/2026/10/'.$name,'../do-not-delete.txt','uploads/2026/10/../do-not-delete.txt'],$root);
  if($failed!==0 || is_file($file) || !is_file($outside)) throw new RuntimeException('Photo cleanup failed or escaped upload root');
  if(reset_catalog_photo_files(['uploads/2026/10/'.$name],$root)!==0) throw new RuntimeException('Missing file should not be a failure');
  echo "PASS: physical photo removed, path traversal rejected, outside file preserved\n";
} finally {
  @unlink($file); @unlink($outside); @rmdir($dir); @rmdir($root.'/uploads/2026'); @rmdir($root.'/uploads'); @rmdir($root);
}
