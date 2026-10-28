<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/private/snapshots.php';

function assert_rejected(string $name): void {
 try { snap_delete($name); } catch (RuntimeException $e) { return; }
 throw new RuntimeException('Expected unsafe name to be rejected: '.$name);
}

foreach (['../config.php', '../../catalogus-public/index.php', '/etc/passwd', 'snapshot-20261028-111111-aaaaaaaaaaaa.zip/../x', 'not-a-snapshot.zip'] as $name) assert_rejected($name);

$dir=snap_dir();
if (!is_dir($dir) && !mkdir($dir,0700,true)) throw new RuntimeException('Test directory unavailable');
if (is_link($dir)) throw new RuntimeException('Refuse symlinked test directory');
$name='snapshot-20261028-121212-abcdefabcdef.zip';
$path=$dir.'/'.$name;
if(file_exists($path)||is_link($path))throw new RuntimeException('Refuse to overwrite test fixture');
$target=$dir.'/.snyk-delete-test-target-'.bin2hex(random_bytes(6));
if(file_put_contents($target,'keep')===false)throw new RuntimeException('Test setup failed');
try {
 if(!symlink($target,$path)) throw new RuntimeException('Cannot create symlink fixture');
 assert_rejected($name);
 if(!is_file($target)||file_get_contents($target)!=='keep')throw new RuntimeException('Symlink target was touched');
 unlink($path);
 if(file_put_contents($path,'snapshot-test')===false)throw new RuntimeException('Test setup failed');
 snap_delete($name);
 if(file_exists($path)||is_link($path))throw new RuntimeException('Snapshot not deleted');
} finally {
 if(is_link($path)||is_file($path))unlink($path);
 if(is_file($target))unlink($target);
}
echo "snapshot delete security tests passed\n";
