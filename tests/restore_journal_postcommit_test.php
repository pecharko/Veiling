<?php
declare(strict_types=1);

/**
 * T-054: SIGKILL AFTER MySQL commit but BEFORE journal phase update.
 * The last saved phase is ambiguous. The PHP site must remain closed and
 * the recovery assets must remain until the database and target photos are
 * independently verified. All repairs are confined to disposable CI data.
 */
$root=dirname(__DIR__);
if(getenv('GITHUB_ACTIONS')!=='true'
 ||getenv('CI_SNAPSHOT_TEST')!=='yes'
 ||getenv('CI_DB_HOST')!=='127.0.0.1'
 ||getenv('CI_DB_NAME')!=='auction_test'){
    throw new RuntimeException('Only isolated GitHub Actions/MySQL CI is allowed.');
}
require $root.'/private/bootstrap.php';
require $root.'/private/snapshots.php';
$pdo=db();
if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='auction_test'
   ||!function_exists('posix_kill') || !function_exists('proc_open')) {
    throw new RuntimeException('Unexpected database or unsupported CI process functions.');
}
if(restore_journal_pending())throw new RuntimeException('Journal pending before T-054.');
$source=(string)file_get_contents($root.'/private/snapshots.php');
$needle="   \$dbCommitted=true;";
if(substr_count($source,$needle)!==1)throw new RuntimeException('Review changed post-commit checkpoint.');
$hook=<<<'PHP'
   if(getenv('CI_KILL_AFTER_COMMIT')==='yes'){
      file_put_contents((string)getenv('CI_KILL_MARKER'),'committed');
      posix_kill(getmypid(),9);
      exit(91);
   }
PHP;
$temp=$root.'/private/.ci-postcommit-'.bin2hex(random_bytes(6)).'.php';
$marker=sys_get_temp_dir().'/ci-postcommit-kill-'.bin2hex(random_bytes(6));
$uploads=snap_uploads();$parent=dirname($uploads);
$currentRel='2026/10/'.str_repeat('e',32).'.jpg';
$currentFile=$uploads.'/'.$currentRel;
$lotId=(int)$pdo->query('SELECT id FROM lots WHERE lot_number=101')->fetchColumn();
if($lotId<=0)throw new RuntimeException('Missing disposable lot 101.');
$originalTitle=(string)$pdo->query('SELECT title FROM lots WHERE id='.$lotId)->fetchColumn();
$extraId=0;$prior=null;$child=null;$pipes=[];$recovered=false;
try {
 $target=null;$targetData=null;$targetHashes=null;
 foreach(snap_listing() as $item){
  [$data,$hashes]=snap_verified($item['name']);
  $lotRows=array_values(array_filter($data['tables']['lots'],
     static fn(array $row):bool=>(int)$row['lot_number']===101));
  if(count($lotRows)===1){
   $target=$item['name'];$targetData=$data;$targetHashes=$hashes;break;
  }
 }
 if($target===null)throw new RuntimeException('No verified disposable snapshot.');
 if((glob($parent.'/.uploads-before-restore-*')?:[])!==[]){
  throw new RuntimeException('Stale restore directory before post-commit test.');
 }
 file_put_contents($temp,str_replace($needle,$needle."\n".$hook,$source),LOCK_EX);
 $pdo->exec("UPDATE lots SET title='CI pre-restore state that must be replaced' WHERE lot_number=101");
 if(!is_dir(dirname($currentFile)))mkdir(dirname($currentFile),0700,true);
 $image=imagecreatetruecolor(31,31);
 imagefill($image,0,0,imagecolorallocate($image,60,120,210));
 imagejpeg($image,$currentFile,90);imagedestroy($image);
 $beforeHash=hash_file('sha256',$currentFile);
 $pdo->prepare('INSERT INTO lot_images(lot_id,path,sort_order,source_name) VALUES (?,?,?,?)')
   ->execute([$lotId,'uploads/'.$currentRel,999,'ci-postcommit-only.jpg']);
 $extraId=(int)$pdo->lastInsertId();

 $call='require '.var_export($root.'/private/bootstrap.php',true).';'
      .'require '.var_export($temp,true).';snap_restore(db(),'
      .var_export($target,true).');';
 $child=proc_open([PHP_BINARY,'-r',$call],
  [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root,
  array_merge(getenv(),['CI_KILL_AFTER_COMMIT'=>'yes','CI_KILL_MARKER'=>$marker]));
 if(!is_resource($child))throw new RuntimeException('Cannot start disposable child.');
 fclose($pipes[0]);
 $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);
 fclose($pipes[1]);fclose($pipes[2]);$pipes=[];
 $exit=proc_close($child);$child=null;
 if($exit===0||!is_file($marker))throw new RuntimeException("Post-commit SIGKILL did not occur: {$stderr} {$stdout}");
 echo "PASS T-054: isolated restore child was killed immediately after a successful DB commit.\n";
 if(!restore_journal_pending())throw new RuntimeException('Post-commit crash lost maintenance marker.');
 $journal=restore_journal_read();
 if($journal['phase']!=='photos_active'||$journal['snapshot']!==$target){
  throw new RuntimeException('Journal did not retain ambiguous pre-commit phase.');
 }
 echo "PASS T-054: a stale photos_active journal is treated as ambiguous, never as proof that DB rolled back.\n";
 $dirs=glob($parent.'/.uploads-before-restore-*')?:[];
 if(count($dirs)!==1||!is_dir($dirs[0]))throw new RuntimeException('Missing pre-restore photo directory.');
 $prior=$dirs[0];
 if(basename($prior)!==$journal['old_uploads']
    ||!is_file($prior.'/'.$currentRel)
    ||hash_file('sha256',$prior.'/'.$currentRel)!==$beforeHash
    ||is_file($currentFile)){
   throw new RuntimeException('Original pre-restore photos were not preserved.');
 }
 $row=$pdo->prepare('SELECT title FROM lots WHERE lot_number=?');
 $row->execute([101]);
 $restoredTitle=$row->fetchColumn();
 $targetLot=array_values(array_filter($targetData['tables']['lots'],
     static fn(array $entry):bool=>(int)$entry['lot_number']===101))[0];
 if($restoredTitle!==$targetLot['title']){
  throw new RuntimeException('Database did not commit the target archive.');
 }
 $remaining=$pdo->prepare('SELECT COUNT(*) FROM lot_images WHERE source_name=?');
 $remaining->execute(['ci-postcommit-only.jpg']);
 if((int)$remaining->fetchColumn()!==0)throw new RuntimeException('Old image DB row survived target DB commit.');
 foreach($targetHashes as $archivePath=>$sha){
  if($archivePath==='database.json')continue;
  $rel=substr($archivePath,strlen('uploads/'));
  if(!is_file($uploads.'/'.$rel)||hash_file('sha256',$uploads.'/'.$rel)!==$sha){
   throw new RuntimeException('Activated snapshot photo did not match archive hash.');
  }
 }
 echo "PASS T-054: independent disposable DB and photo checks confirm the target snapshot committed.\n";
 require_once $root.'/private/restore_diagnosis.php';
 $diagnosis=restore_recovery_diagnose($pdo);
 if($diagnosis['status']!=='target_verified_pending_review'
    ||$diagnosis['database_matches_target']!==true
    ||$diagnosis['active_photos_match_target']!==true
    ||$diagnosis['maintenance_must_remain']!==true){
    throw new RuntimeException('Read-only diagnosis failed to verify full target after post-commit SIGKILL.');
 }
 echo "PASS T-055: after post-commit SIGKILL all tables and photos match but journal stays pending review.\n";
 try{
  snap_create($pdo);
  throw new RuntimeException('New snapshot was accepted during unresolved post-commit journal.');
 }catch(RuntimeException $e){
  if(!str_contains($e->getMessage(),'onderbroken'))throw $e;
 }
 echo "PASS T-054: ambiguous post-commit state refuses follow-on snapshot.\n";

 // Only now, after verifying target DB + every archived photo, may a CI
 // operator complete the interrupted old-photo cleanup. Not automatic on OVH.
 snap_remove_tree($prior);$prior=null;
 restore_journal_finish();$recovered=true;
 if(restore_journal_pending())throw new RuntimeException('Marker persisted after verified fixture completion.');
 echo "PASS T-054: CI-only verified completion clears journal and preserves target DB/photos.\n";
} finally {
 @unlink($temp);@unlink($marker);
 if(is_resource($child)){
  proc_terminate($child,9);
  foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);
  proc_close($child);
 }
 if($prior!==null&&is_dir($prior)){
  // Best-effort disposable-test cleanup; a failure already fails the CI step.
  if(is_dir($uploads)){
   $stray=$parent.'/.ci-restore-discard-'.bin2hex(random_bytes(5));
   if(rename($uploads,$stray)&&rename($prior,$uploads))snap_remove_tree($stray);
  }else rename($prior,$uploads);
 }
 if($extraId>0){
  $pdo->prepare('DELETE FROM lot_images WHERE id=?')->execute([$extraId]);
 }
 $pdo->prepare('UPDATE lots SET title=? WHERE id=?')->execute([$originalTitle,$lotId]);
 @unlink($currentFile);
 if(!$recovered&&restore_journal_pending())@unlink(restore_journal_path());
}
