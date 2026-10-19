<?php
declare(strict_types=1);
/**
 * T-050B: forcibly SIGKILL a child restore just after it swaps uploads,
 * but before the DB transaction starts. PASS means the existing crash window
 * is reproduced. No production PHP code or production files are modified.
 */
$root=dirname(__DIR__);
if(getenv('GITHUB_ACTIONS')!=='true'
 || getenv('CI_SNAPSHOT_TEST')!=='yes'
 || getenv('CI_DB_HOST')!=='127.0.0.1'
 || getenv('CI_DB_NAME')!=='auction_test'){
 throw new RuntimeException('Only disposable GitHub Actions MySQL CI is allowed.');
}
require $root.'/private/bootstrap.php';
require $root.'/private/snapshots.php';
$pdo=db();
if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='auction_test'){
 throw new RuntimeException('Unexpected database.');
}
if(!function_exists('posix_kill') || !function_exists('proc_open')){
 throw new RuntimeException('SIGKILL probe requires PHP posix and proc_open.');
}
$source=(string)file_get_contents($root.'/private/snapshots.php');
$needle='}$stage=null;';
if(substr_count($source,$needle)!==1)throw new RuntimeException('Review changed restore swap checkpoint.');
$replacement=$needle."\n".<<<'PHP'
  if(getenv('CI_KILL_AT_SWAP')==='yes'){
    file_put_contents((string)getenv('CI_CRASH_MARKER'),'swapped');
    posix_kill(getmypid(),9);
    exit(91);
  }
PHP;
$probe=$root.'/private/.ci-crash-'.bin2hex(random_bytes(6)).'.php';
$marker=sys_get_temp_dir().'/ci-snapshot-kill-'.bin2hex(random_bytes(8));
$uploads=snap_uploads();
$base=dirname($uploads);
$currentOnlyRel='2026/10/'.str_repeat('c',32).'.jpg';
$currentOnly=$uploads.'/'.$currentOnlyRel;
$oldDirs=glob($base.'/.uploads-before-restore-*')?:[];
if(count($oldDirs)!==0)throw new RuntimeException('Leftover restore directory before isolated crash test.');
$lotId=(int)$pdo->query('SELECT id FROM lots WHERE lot_number=101')->fetchColumn();
if($lotId<=0)throw new RuntimeException('Expected disposable lot #101 missing.');
$originalTitle=(string)$pdo->query('SELECT title FROM lots WHERE id='.$lotId)->fetchColumn();
$child=null;$pipes=[];$priorDirectory=null;$toDelete=null;$newImageId=0;
try{
 $target=null;
 foreach(snap_listing() as $item){
  [$db]=snap_verified($item['name']);
  foreach($db['tables']['lots'] as $lot){
   if((int)$lot['lot_number']===101){$target=$item['name'];break 2;}
  }
 }
 if($target===null)throw new RuntimeException('No verified CI fixture snapshot to restore.');
 file_put_contents($probe,str_replace($needle,$replacement,$source),LOCK_EX);
 $pdo->exec("UPDATE lots SET title='CI old data after simulated crash' WHERE lot_number=101");
 if(!is_dir(dirname($currentOnly)))mkdir(dirname($currentOnly),0700,true);
 $img=imagecreatetruecolor(30,30);
 imagefill($img,0,0,imagecolorallocate($img,12,42,83));
 if(!imagejpeg($img,$currentOnly,90))throw new RuntimeException('Cannot write disposable image.');
 imagedestroy($img);
 $pdo->prepare('INSERT INTO lot_images(lot_id,path,sort_order,source_name) VALUES (?,?,?,?)')
  ->execute([$lotId,'uploads/'.$currentOnlyRel,999,'ci-crash-only.jpg']);
 $newImageId=(int)$pdo->lastInsertId();
 $call='require '.var_export($root.'/private/bootstrap.php',true).';'
      .'require '.var_export($probe,true).';snap_restore(db(),'.var_export($target,true).');';
 $child=proc_open([PHP_BINARY,'-r',$call],
  [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root,
  array_merge(getenv(),['CI_KILL_AT_SWAP'=>'yes','CI_CRASH_MARKER'=>$marker]));
 if(!is_resource($child))throw new RuntimeException('Cannot start isolated restore child.');
 fclose($pipes[0]);
 $stdout=stream_get_contents($pipes[1]);
 $stderr=stream_get_contents($pipes[2]);
 fclose($pipes[1]);fclose($pipes[2]);$pipes=[];
 $status=proc_close($child);$child=null;
 if($status===0 || !is_file($marker)){
  throw new RuntimeException('Child did not crash after directory swap: '.$stderr.' '.$stdout);
 }
 echo "PASS T-050B: child was SIGKILLed at the photo-swap/DB boundary.\n";
 $dirs=glob($base.'/.uploads-before-restore-*')?:[];
 if(count($dirs)!==1 || !is_dir($dirs[0]))throw new RuntimeException('Old uploads not preserved.');
 $priorDirectory=$dirs[0];
 echo "PASS T-050B: original photos remain recoverable in old private directory.\n";
 $missing=!is_file($currentOnly);
 $stillLinked=(int)$pdo->query('SELECT COUNT(*) FROM lot_images WHERE id='.$newImageId)->fetchColumn()===1;
 $oldData=(string)$pdo->query('SELECT title FROM lots WHERE id='.$lotId)->fetchColumn()
  ==='CI old data after simulated crash';
 if(!$missing || !$stillLinked || !$oldData || !is_file($priorDirectory.'/'.$currentOnlyRel)){
  throw new RuntimeException('Expected DB/photo inconsistency not reproduced.');
 }
 echo "PASS T-050B: old database still links an image absent from active uploads (risk reproduced, not fixed).\n";

 // Only the disposable CI fixture is manually recovered. The application
 // currently has NO automatic crash-recovery journal. Never do this on live.
 $toDelete=$base.'/.ci-restored-temp-'.bin2hex(random_bytes(6));
 if(!rename($uploads,$toDelete) || !rename($priorDirectory,$uploads)){
  throw new RuntimeException('Isolated fixture directory swap recovery failed.');
 }
 $priorDirectory=null;
 snap_remove_tree($toDelete);$toDelete=null;
 if(!is_file($currentOnly))throw new RuntimeException('Fixture-only recovery did not restore original image.');
 echo "PASS T-050B: operator-style manual recovery works in disposable CI (not automatic recovery).\n";
}finally{
 @unlink($probe);@unlink($marker);
 if(is_resource($child)){
  proc_terminate($child,9);
  foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);
  proc_close($child);
 }
 if($priorDirectory!==null && is_dir($priorDirectory)){
  if(is_dir($uploads)){
   $stale=$base.'/.ci-stale-'.bin2hex(random_bytes(6));
   if(rename($uploads,$stale) && rename($priorDirectory,$uploads))snap_remove_tree($stale);
  }else rename($priorDirectory,$uploads);
 }
 if($toDelete!==null && is_dir($toDelete))snap_remove_tree($toDelete);
 @unlink($currentOnly);
 if($newImageId>0)$pdo->prepare('DELETE FROM lot_images WHERE id=?')->execute([$newImageId]);
 $pdo->prepare('UPDATE lots SET title=? WHERE id=?')->execute([$originalTitle,$lotId]);
}
