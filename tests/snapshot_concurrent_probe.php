<?php
declare(strict_types=1);
/**
 * T-050A: verify one consistent MySQL read view using disposable MySQL.
 * PASS means concurrent atomic database changes cannot be split across snapshot tables.
 * This test injects a pause into a temporary COPY of snapshots.php,
 * never the application source and never OVHcloud.
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
$source=(string)file_get_contents($root.'/private/snapshots.php');
$needle='foreach(SNAP_TABLES as $table){';
if(substr_count($source,$needle)!==1)throw new RuntimeException('Review changed snapshot SELECT loop.');
$replacement=<<<'PHP'
foreach(SNAP_TABLES as $table){
  if($table==='lots' && getenv('CI_PAUSE_READ')==='1'){
    file_put_contents((string)getenv('CI_READY_FLAG'),'ready');
    for($i=0;$i<200&&!is_file((string)getenv('CI_RELEASE_FLAG'));$i++)usleep(50000);
    if(!is_file((string)getenv('CI_RELEASE_FLAG')))throw new RuntimeException('Probe timed out.');
  }
PHP;
$probe=$root.'/private/.ci-interleave-'.bin2hex(random_bytes(6)).'.php';
$prefix=sys_get_temp_dir().'/ci-interleave-'.bin2hex(random_bytes(8));
$ready=$prefix.'-ready';$release=$prefix.'-release';
$originalSettings=(string)$pdo->query('SELECT title FROM settings WHERE id=1')->fetchColumn();
$originalLot=(string)$pdo->query('SELECT title FROM lots WHERE lot_number=101')->fetchColumn();
if($originalLot==='')throw new RuntimeException('Expected disposable lot #101 missing.');
$child=null;$pipes=[];$created=null;
try{
 file_put_contents($probe,str_replace($needle,$replacement,$source),LOCK_EX);
 // Both values transition together in one committed InnoDB transaction.
 $pdo->beginTransaction();
 $pdo->exec("UPDATE settings SET title='CI state before' WHERE id=1");
 $pdo->exec("UPDATE lots SET title='CI state before' WHERE lot_number=101");
 $pdo->commit();

 $call='require '.var_export($root.'/private/bootstrap.php',true).';'
      .'require '.var_export($probe,true).';echo snap_create(db());';
 $child=proc_open([PHP_BINARY,'-r',$call],
   [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root,
   array_merge(getenv(),['CI_PAUSE_READ'=>'1','CI_READY_FLAG'=>$ready,'CI_RELEASE_FLAG'=>$release]));
 if(!is_resource($child))throw new RuntimeException('Cannot launch child.');
 fclose($pipes[0]);
 $sawReady=false;
 for($i=0;$i<200;$i++){
  if(is_file($ready)){$sawReady=true;break;}
  if(!proc_get_status($child)['running'])break;
  usleep(50000);
 }
 if(!$sawReady)throw new RuntimeException('Snapshot child never reached lot-read checkpoint.');

 $pdo->beginTransaction();
 $pdo->exec("UPDATE settings SET title='CI state after' WHERE id=1");
 $pdo->exec("UPDATE lots SET title='CI state after' WHERE lot_number=101");
 $pdo->commit();
 file_put_contents($release,'continue');
 $output=stream_get_contents($pipes[1]);
 $error=stream_get_contents($pipes[2]);
 fclose($pipes[1]);fclose($pipes[2]);$pipes=[];
 $status=proc_close($child);$child=null;
 if($status!==0)throw new RuntimeException('Snapshot child failed: '.$error);
 $created=trim($output);
 if(!snap_name($created))throw new RuntimeException('Unexpected snapshot name: '.$output);
 [$data]=snap_verified($created);
 $oldSettings=(string)$data['tables']['settings'][0]['title'];
 $lots=array_values(array_filter($data['tables']['lots'],
   static fn(array $lot):bool=>(int)$lot['lot_number']===101));
 if(count($lots)!==1)throw new RuntimeException('Expected lot missing from snapshot.');
 if($oldSettings!=='CI state before'||$lots[0]['title']!=='CI state before'){
  throw new RuntimeException('Mixed database read view detected; settings and lots must share the same pre-commit state.');
 }
 echo "PASS T-050A: snapshot captured settings and lots from one consistent pre-commit MySQL read view.\n";
}finally{
 @unlink($ready);@unlink($release);@unlink($probe);
 if(is_resource($child)){proc_terminate($child,9);foreach($pipes as $p)if(is_resource($p))fclose($p);proc_close($child);}
 if($pdo->inTransaction())$pdo->rollBack();
 $pdo->beginTransaction();
 $pdo->prepare('UPDATE settings SET title=? WHERE id=1')->execute([$originalSettings]);
 $pdo->prepare('UPDATE lots SET title=? WHERE lot_number=101')->execute([$originalLot]);
 $pdo->commit();
 if($created!==null && snap_name($created))@unlink(snap_path($created));
}
