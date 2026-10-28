<?php
declare(strict_types=1);
require dirname(__DIR__).'/private/photo_delete_safety.php';
$root=sys_get_temp_dir().'/photo-delete-test-'.bin2hex(random_bytes(6));
$folder=$root.'/uploads/2026/10';
if(!mkdir($folder,0700,true))throw new RuntimeException('setup failed');
$name='0123456789abcdef0123456789abcdef.jpg';
$file=$folder.'/'.$name;
$rel='uploads/2026/10/'.$name;
$external=$root.'/outside.jpg';
file_put_contents($external,'preserve');
try {
 foreach(['../outside.jpg','uploads/2026/10/../../outside.jpg','uploads/2026/10/not-a-hash.jpg'] as $bad)
  if(catalog_safe_unlink_photo($bad,$root))throw new RuntimeException('Unsafe name accepted');
 if(!symlink($external,$file))throw new RuntimeException('Could not set up symlink test');
 if(catalog_safe_unlink_photo($rel,$root))throw new RuntimeException('Symlink accepted');
 if(file_get_contents($external)!=='preserve')throw new RuntimeException('Outside file changed');
 unlink($file);
 file_put_contents($file,'regular');
 if(!catalog_safe_unlink_photo($rel,$root)||file_exists($file))throw new RuntimeException('Legitimate photo deletion failed');
}finally{
 if(is_file($file)||is_link($file))unlink($file);
 if(is_file($external))unlink($external);
 rmdir($folder);rmdir($root.'/uploads/2026');rmdir($root.'/uploads');rmdir($root);
}
echo "Photo deletion regression passed\n";
