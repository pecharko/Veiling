<?php
declare(strict_types=1);

/**
 * T-053: inject a SQL error AFTER the photo swap and BEFORE DB COMMIT.
 * The normal catch path must undo SQL + photos and clear maintenance marker.
 * Disposable MySQL, disposable PHP files, and disposable images only.
 */
$root=dirname(__DIR__);
if(getenv('GITHUB_ACTIONS')!=='true'
 ||getenv('CI_SNAPSHOT_TEST')!=='yes'
 ||getenv('CI_DB_HOST')!=='127.0.0.1'
 ||getenv('CI_DB_NAME')!=='auction_test'){
    throw new RuntimeException('Requires isolated GitHub Actions CI.');
}
require $root.'/private/bootstrap.php';
require $root.'/private/snapshots.php';
$pdo=db();
if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='auction_test'){
    throw new RuntimeException('Unexpected database.');
}
if(restore_journal_pending())throw new RuntimeException('Pending restore before T-053.');
$source=(string)file_get_contents($root.'/private/snapshots.php');
$needle="   foreach(['settings','admins','lots','lot_images','login_attempts'] as \$table){";
if(substr_count($source,$needle)!==1)throw new RuntimeException('Review restore fault point.');
$inject=<<<'PHP'
   if(getenv('CI_RESTORE_SQL_FAIL')==='yes'){
      throw new RuntimeException('CI deliberate SQL error before commit');
   }
PHP;
$fixture=$root.'/private/.ci-restore-error-'.bin2hex(random_bytes(6)).'.php';
$relative='2026/10/'.str_repeat('d',32).'.jpg';
$file=snap_uploads().'/'.$relative;
$lotId=(int)$pdo->query('SELECT id FROM lots WHERE lot_number=101')->fetchColumn();
if($lotId<=0)throw new RuntimeException('Disposable lot #101 missing.');
$originalTitle=(string)$pdo->query('SELECT title FROM lots WHERE id='.$lotId)->fetchColumn();
$extraId=0;$pipes=[];$child=null;
try{
    $target=null;
    foreach(snap_listing() as $item){
        [$data]=snap_verified($item['name']);
        foreach($data['tables']['lots'] as $row){
            if((int)$row['lot_number']===101){$target=$item['name'];break 2;}
        }
    }
    if($target===null)throw new RuntimeException('No verified disposable snapshot found.');
    file_put_contents($fixture,str_replace($needle,$inject."\n".$needle,$source),LOCK_EX);
    $pdo->exec("UPDATE lots SET title='CI row must survive SQL rollback' WHERE lot_number=101");
    if(!is_dir(dirname($file)))mkdir(dirname($file),0700,true);
    $image=imagecreatetruecolor(35,35);
    imagefill($image,0,0,imagecolorallocate($image,30,120,80));
    imagejpeg($image,$file,90);imagedestroy($image);
    $beforeHash=hash_file('sha256',$file);
    $pdo->prepare('INSERT INTO lot_images(lot_id,path,sort_order,source_name) VALUES (?,?,?,?)')
        ->execute([$lotId,'uploads/'.$relative,99,'ci-restore-error-only.jpg']);
    $extraId=(int)$pdo->lastInsertId();

    $call='require '.var_export($root.'/private/bootstrap.php',true).';'
        .'require '.var_export($fixture,true).';'
        .'try{snap_restore(db(),'.var_export($target,true).');exit(9);}'
        .'catch(RuntimeException $e){'
        .'if(!str_contains($e->getMessage(),"CI deliberate SQL error"))throw $e;'
        .'echo "EXPECTED_ROLLBACK";}';
    $child=proc_open([PHP_BINARY,'-r',$call],
       [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root,
       array_merge(getenv(),['CI_RESTORE_SQL_FAIL'=>'yes']));
    if(!is_resource($child))throw new RuntimeException('Cannot launch test PHP.');
    fclose($pipes[0]);
    $output=stream_get_contents($pipes[1]);
    $error=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);$pipes=[];
    $exit=proc_close($child);$child=null;
    if($exit!==0||trim($output)!=='EXPECTED_ROLLBACK'){
        throw new RuntimeException("Injected error child failed ({$exit}): {$error} {$output}");
    }
    echo "PASS T-053: fault injection interrupted restoration after photo swap and before commit.\n";
    if(restore_journal_pending())throw new RuntimeException('Handled error left stale maintenance journal.');
    if((string)$pdo->query('SELECT title FROM lots WHERE lot_number=101')->fetchColumn()
       !=='CI row must survive SQL rollback'){
        throw new RuntimeException('MySQL rollback failed to preserve original lot.');
    }
    $check=$pdo->prepare('SELECT path FROM lot_images WHERE id=?');
    $check->execute([$extraId]);
    if($check->fetchColumn()!=='uploads/'.$relative
       ||!is_file($file) || hash_file('sha256',$file)!==$beforeHash){
       throw new RuntimeException('Photo rollback did not restore original linked image.');
    }
    if(glob(dirname(snap_uploads()).'/.uploads-before-restore-*')){
        throw new RuntimeException('Handled failure left old photo directory behind.');
    }
    echo "PASS T-053: SQL rows, original photos and checksum all match pre-restore state.\n";
    echo "PASS T-053: a fully handled exception cleared maintenance journal and left no stale directory.\n";
} finally {
    if(is_resource($child)){
      proc_terminate($child,9);
      foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);
      proc_close($child);
    }
    @unlink($fixture);
    if($extraId>0)$pdo->prepare('DELETE FROM lot_images WHERE id=?')->execute([$extraId]);
    $pdo->prepare('UPDATE lots SET title=? WHERE id=?')->execute([$originalTitle,$lotId]);
    @unlink($file);
}
