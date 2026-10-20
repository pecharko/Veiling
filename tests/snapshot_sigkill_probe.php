<?php
declare(strict_types=1);

/**
 * T-050B/T-052: kill isolated restore AFTER photo swap, BEFORE DB commit.
 * The durable journal must prevent further PHP page/admin access until
 * the original disposable CI data has been manually put back and checked.
 * This deliberately does NOT simulate recovery on production.
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
$needle="  restore_journal_phase(\$journal,'photos_active');";
if(substr_count($source,$needle)!==1)throw new RuntimeException('Review changed restore crash checkpoint.');
$replacement=$needle."\n".<<<'PHP'
  if(getenv('CI_KILL_AT_SWAP')==='yes'){
    file_put_contents((string)getenv('CI_CRASH_MARKER'),'photos_active');
    posix_kill(getmypid(),9);
    exit(91);
  }
PHP;
$probe=$root.'/private/.ci-crash-'.bin2hex(random_bytes(6)).'.php';
$marker=sys_get_temp_dir().'/ci-snapshot-kill-'.bin2hex(random_bytes(8));
$uploads=snap_uploads();$base=dirname($uploads);
$currentOnlyRel='2026/10/'.str_repeat('c',32).'.jpg';
$currentOnly=$uploads.'/'.$currentOnlyRel;
$oldDirs=glob($base.'/.uploads-before-restore-*')?:[];
if($oldDirs || restore_journal_pending())throw new RuntimeException('Existing recovery evidence before isolated test.');
$lotId=(int)$pdo->query('SELECT id FROM lots WHERE lot_number=101')->fetchColumn();
if($lotId<=0)throw new RuntimeException('Expected disposable lot #101 missing.');
$originalTitle=(string)$pdo->query('SELECT title FROM lots WHERE id='.$lotId)->fetchColumn();
$child=null;$pipes=[];$server=null;$serverPipes=[];$priorDirectory=null;$toDelete=null;$newImageId=0;
$recovered=false;

function journal_http_get(string $path): array {
 $ch=curl_init('http://127.0.0.1:8097'.$path);
 curl_setopt_array($ch,[
  CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,
  CURLOPT_CONNECTTIMEOUT=>1,CURLOPT_TIMEOUT=>4
 ]);
 $data=curl_exec($ch);
 $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
 curl_close($ch);
 return [$status,$data===false?'':(string)$data];
}
try {
 $target=null;
 foreach(snap_listing() as $item){
  [$database]=snap_verified($item['name']);
  foreach($database['tables']['lots'] as $lot){
   if((int)$lot['lot_number']===101){$target=$item['name'];break 2;}
  }
 }
 if($target===null)throw new RuntimeException('No verified CI fixture snapshot.');
 file_put_contents($probe,str_replace($needle,$replacement,$source),LOCK_EX);
 $pdo->exec("UPDATE lots SET title='CI old data after simulated crash' WHERE lot_number=101");
 if(!is_dir(dirname($currentOnly)))mkdir(dirname($currentOnly),0700,true);
 $image=imagecreatetruecolor(30,30);
 imagefill($image,0,0,imagecolorallocate($image,12,42,83));
 if(!imagejpeg($image,$currentOnly,90))throw new RuntimeException('Cannot write disposable photo.');
 imagedestroy($image);
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
 $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);
 fclose($pipes[1]);fclose($pipes[2]);$pipes=[];
 $status=proc_close($child);$child=null;
 if($status===0 || !is_file($marker))throw new RuntimeException('SIGKILL was not reached: '.$stderr.' '.$stdout);
 echo "PASS T-050B: restore process was SIGKILLed after photo swap and before DB transaction.\n";

 if(!restore_journal_pending())throw new RuntimeException('Missing durable crash journal after SIGKILL.');
 $journal=restore_journal_read();
 if($journal['phase']!=='photos_active'||$journal['snapshot']!==$target){
  throw new RuntimeException('Restore journal does not preserve expected crash phase/snapshot.');
 }
 echo "PASS T-052: private journal survives SIGKILL with validated restore metadata.\n";
 $dirs=glob($base.'/.uploads-before-restore-*')?:[];
 if(count($dirs)!==1 || !is_dir($dirs[0]))throw new RuntimeException('Original photos not retained.');
 $priorDirectory=$dirs[0];
 if($journal['old_uploads']!==basename($priorDirectory)
    ||!is_file($priorDirectory.'/'.$currentOnlyRel)
    ||is_file($currentOnly)
    ||(int)$pdo->query('SELECT COUNT(*) FROM lot_images WHERE id='.$newImageId)->fetchColumn()!==1){
  throw new RuntimeException('Current DB/photo mismatch or backup identity not reproduced.');
 }
 echo "PASS T-050B: preserved prior files and original DB reveal dangerous interrupted restore.\n";
 require_once $root.'/private/restore_diagnosis.php';
 $diagnosis=restore_recovery_diagnose($pdo);
 if($diagnosis['status']!=='mixed_or_unknown_keep_maintenance'
    ||$diagnosis['database_matches_target']!==false
    ||$diagnosis['active_photos_match_target']!==true
    ||$diagnosis['maintenance_must_remain']!==true){
    throw new RuntimeException('Read-only diagnosis failed to detect pre-commit DB/photo mismatch.');
 }
 echo "PASS T-055: after pre-commit SIGKILL only photos match target; report stays blocked.\n";

 try{
  snap_create($pdo);
  throw new RuntimeException('New snapshot unexpectedly succeeded while journal was pending.');
 }catch(RuntimeException $e){
  if(!str_contains($e->getMessage(),'onderbroken'))throw $e;
 }
 echo "PASS T-052: pending journal also blocks direct attempts to create another snapshot.\n";

 // The web entrypoint must refuse BOTH visitor and administrator PHP pages,
 // even though the terminated MySQL lock owner no longer holds GET_LOCK.
 $server=proc_open([PHP_BINARY,'-S','127.0.0.1:8097','-t',$root.'/catalogus-public'],
  [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$serverPipes,$root);
 if(!is_resource($server))throw new RuntimeException('Cannot launch local PHP test server.');
 fclose($serverPipes[0]);
 $ready=false;
 for($i=0;$i<30;$i++){
  [$http,$body]=journal_http_get('/index.php');
  if($http===503){$ready=true;break;}
  usleep(100000);
 }
 if(!$ready)throw new RuntimeException('HTTP maintenance response was not served.');
 foreach(['/index.php','/admin/login.php','/admin/kavel.php','/admin/backup.php'] as $route){
  [$http,$body]=journal_http_get($route);
  if($http!==503 || !str_contains($body,'tijdelijk niet beschikbaar')
     || str_contains($body,'ci-crash-only') || str_contains($body,'password_hash')){
    throw new RuntimeException("Maintenance gate did not safely block {$route}: {$http}");
  }
 }
 echo "PASS T-052: public catalog and all tested admin PHP routes fail closed with HTTP 503.\n";

 // CI-fixture-only operator recovery; NEVER automate a production rollback by
 // guessing whether the database COMMIT did or did not complete.
 $toDelete=$base.'/.ci-restored-temp-'.bin2hex(random_bytes(6));
 if(!rename($uploads,$toDelete)||!rename($priorDirectory,$uploads)){
  throw new RuntimeException('Disposable fixture repair failed.');
 }
 $priorDirectory=null;
 snap_remove_tree($toDelete);$toDelete=null;
 if(!is_file($currentOnly))throw new RuntimeException('Fixture photo was not recovered.');
 $pdo->prepare('DELETE FROM lot_images WHERE id=?')->execute([$newImageId]);$newImageId=0;
 $pdo->prepare('UPDATE lots SET title=? WHERE id=?')->execute([$originalTitle,$lotId]);
 @unlink($currentOnly);
 $check=$pdo->prepare('SELECT COUNT(*) FROM lot_images WHERE source_name=?');
 $check->execute(['ci-crash-only.jpg']);
 if((int)$check->fetchColumn()!==0)throw new RuntimeException('Disposable photo metadata not repaired.');
 restore_journal_finish();$recovered=true;
 if(restore_journal_pending())throw new RuntimeException('Maintenance persisted after safe fixture repair.');
 [$home]=journal_http_get('/index.php');
 [$login]=journal_http_get('/admin/login.php');
 if($home!==200||$login!==200)throw new RuntimeException("HTTP did not reopen after fixture recovery: {$home}/{$login}");
 echo "PASS T-052: maintenance cleared only after verified disposable fixture repair; homepage/login reopen.\n";
}finally {
 @unlink($probe);@unlink($marker);
 if(is_resource($child)){
  proc_terminate($child,9);
  foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);
  proc_close($child);
 }
 if(is_resource($server)){
  proc_terminate($server,9);
  foreach($serverPipes as $pipe)if(is_resource($pipe))fclose($pipe);
  proc_close($server);
 }
 if($priorDirectory!==null&&is_dir($priorDirectory)){
  if(is_dir($uploads)){
   $stale=$base.'/.ci-stale-'.bin2hex(random_bytes(6));
   if(rename($uploads,$stale)&&rename($priorDirectory,$uploads))snap_remove_tree($stale);
  }else rename($priorDirectory,$uploads);
 }
 if($toDelete!==null&&is_dir($toDelete))snap_remove_tree($toDelete);
 if($newImageId>0)$pdo->prepare('DELETE FROM lot_images WHERE id=?')->execute([$newImageId]);
 $pdo->prepare('UPDATE lots SET title=? WHERE id=?')->execute([$originalTitle,$lotId]);
 @unlink($currentOnly);
 if(!$recovered&&restore_journal_pending()){
  // Only CI cleanup after attempted fixture repair; failures still fail CI.
  @unlink(restore_journal_path());
 }
}
