<?php
declare(strict_types=1);

/**
 * T-056: approved FINALIZATION of an already verified target after SIGKILL.
 *
 * All writes here are restricted to disposable MySQL auction_test, PHP CI
 * process, isolated photo fixture, snapshot, and private recovery evidence.
 * NEVER run this test on the website or production database.
 */
$root=dirname(__DIR__);
if(getenv('GITHUB_ACTIONS')!=='true'
 ||getenv('CI_SNAPSHOT_TEST')!=='yes'
 ||getenv('CI_DB_HOST')!=='127.0.0.1'
 ||getenv('CI_DB_NAME')!=='auction_test'){
    throw new RuntimeException('T-056 may only execute in disposable GitHub Actions CI.');
}
require $root.'/private/bootstrap.php';
require $root.'/private/restore_finalize.php';
$pdo=db();
if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='auction_test'
 || !function_exists('proc_open') || !function_exists('posix_kill')){
    throw new RuntimeException('Wrong DB or CI process functions unavailable.');
}
$passes=0;
function finalize_test(bool $ok,string $message): void {
    global $passes;
    if(!$ok)throw new RuntimeException('FAIL T-056: '.$message);
    $passes++;
    echo "PASS T-056: {$message}\n";
}
function finalize_reject(callable $fn,string $name): void {
    try{$fn();}catch(Throwable $e){
        finalize_test(true,$name);return;
    }
    finalize_test(false,$name.' unexpectedly succeeded');
}
function finalize_cli(array $flags,string $root): array {
    $p=proc_open(array_merge([PHP_BINARY,$root.'/scripts/restore_finalize.php'],$flags),
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);
    if(!is_resource($p))throw new RuntimeException('Cannot start isolated recovery CLI.');
    fclose($pipes[0]);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    return [proc_close($p),$out,$err];
}
if(restore_journal_pending())throw new RuntimeException('Stale journal before disposable test.');

$operator='ci-approved-operator';
$prior=restore_recovery_diagnose($pdo);
finalize_test($prior['status']==='clear','normal CI state has no restore journal');
$oldTitle=(string)$pdo->query('SELECT title FROM lots WHERE lot_number=101')->fetchColumn();
$lotId=(int)$pdo->query('SELECT id FROM lots WHERE lot_number=101')->fetchColumn();
if($lotId<=0)throw new RuntimeException('No disposable lot 101.');
$target=null;
foreach(snap_listing() as $item){
    [$data]=snap_verified($item['name']);
    foreach($data['tables']['lots'] as $row){
        if((int)$row['lot_number']===101){$target=$item['name'];break 2;}
    }
}
if($target===null)throw new RuntimeException('No CI target archive.');
$source=(string)file_get_contents($root.'/private/snapshots.php');
$hookPoint="   \$dbCommitted=true;";
if(substr_count($source,$hookPoint)!==1)
    throw new RuntimeException('Restore commit checkpoint changed.');
$hook=<<<'PHP'
   if(getenv('CI_APPROVED_KILL')==='yes'){
      file_put_contents((string)getenv('CI_APPROVED_KILL_FLAG'),'postcommit');
      posix_kill(getmypid(),9);
      exit(97);
   }
PHP;
$fixture=$root.'/private/.ci-approval-'.bin2hex(random_bytes(6)).'.php';
$flag=sys_get_temp_dir().'/ci-approved-'.bin2hex(random_bytes(8));
$extraRel='2026/10/'.str_repeat('f',32).'.jpg';
$extraFile=snap_uploads().'/'.$extraRel;
$safety=null;$extraId=0;$child=null;$pipes=[];
$journalData=null;$evidence=null;$receipt=null;$completed=false;
$killHelper=null;$killMarker=null;
try{
    // Make a BEFORE-restoration safety ZIP matching the photos stored in
    // the old uploads directory, then crash after the target DB is committed.
    $pdo->exec("UPDATE lots SET title='CI before approved completion' WHERE lot_number=101");
    if(!is_dir(dirname($extraFile)))mkdir(dirname($extraFile),0700,true);
    $image=imagecreatetruecolor(27,27);
    imagefill($image,0,0,imagecolorallocate($image,90,20,180));
    if(!imagejpeg($image,$extraFile,90))throw new RuntimeException('Cannot write CI photo.');
    imagedestroy($image);
    $extraHash=hash_file('sha256',$extraFile);
    $pdo->prepare('INSERT INTO lot_images(lot_id,path,sort_order,source_name) VALUES(?,?,?,?)')
        ->execute([$lotId,'uploads/'.$extraRel,99,'ci-approval-only.jpg']);
    $extraId=(int)$pdo->lastInsertId();
    $safety=snap_create($pdo);
    file_put_contents($fixture,str_replace($hookPoint,$hookPoint."\n".$hook,$source),LOCK_EX);
    $call='require '.var_export($root.'/private/bootstrap.php',true).';'
         .'require '.var_export($fixture,true).';'
         .'snap_restore(db(),'.var_export($target,true).');';
    $child=proc_open([PHP_BINARY,'-r',$call],
       [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root,
       array_merge(getenv(),['CI_APPROVED_KILL'=>'yes','CI_APPROVED_KILL_FLAG'=>$flag]));
    if(!is_resource($child))throw new RuntimeException('Unable to launch CI crashed restore.');
    fclose($pipes[0]);
    $stdOut=stream_get_contents($pipes[1]);$stdErr=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);$pipes=[];
    $status=proc_close($child);$child=null;
    finalize_test($status!==0 && is_file($flag)
        && restore_journal_pending(),'SIGKILL immediately post-commit leaves private journal pending');
    $journalData=restore_journal_read();
    $oldDir=dirname(snap_uploads()).'/'.$journalData['old_uploads'];
    $evidence=recovery_finalize_evidence_path($journalData);
    $receipt=recovery_finalize_receipt_path($journalData);
    finalize_test(is_file($oldDir.'/'.$extraRel)
        && hash_file('sha256',$oldDir.'/'.$extraRel)===$extraHash,
        'original pre-restore photos are preserved under the journal-linked directory');

    $plan=recovery_finalize_plan($pdo,$safety,$operator);
    finalize_test($plan['ready']===true
       &&$plan['preservation_plan']==='move_previous_uploads_to_private'
       &&$plan['target_snapshot']===$target
       &&$plan['run_id']===$journalData['run_id']
       &&$plan['will_modify_data']===false,
       'read-only plan binds target, safety ZIP, run ID and preservation operation');

    $report=restore_recovery_diagnose($pdo);
    finalize_test($report['status']==='target_verified_pending_review'
       &&$report['maintenance_must_remain']===true,
       'full database and target photo bytes are already restored and match');
    [$code,$out,$err]=finalize_cli([
       '--plan','--safety-snapshot',$safety,'--operator',$operator],$root);
    $cliPlan=json_decode($out,true,512,JSON_THROW_ON_ERROR);
    finalize_test($code===2 && $cliPlan['approval_challenge']===$plan['approval_challenge']
       &&$cliPlan['will_modify_data']===false,
       'CLI plan does not mutate any data and requires separate approval');

    finalize_reject(fn()=>recovery_finalize_approved($pdo,$safety,$operator,
       $plan['safety_sha256'],$plan['run_id'],str_repeat('0',64),RECOVERY_FINALIZE_CONFIRM),
       'incorrect approval challenge cannot clear restore maintenance');
    finalize_reject(fn()=>recovery_finalize_approved($pdo,$safety,$operator,
       $plan['safety_sha256'],$plan['run_id'],$plan['approval_challenge'],'wrong confirmation'),
       'wrong explicit approval phrase is rejected');
    finalize_reject(fn()=>recovery_finalize_approved($pdo,$target,$operator,
       $plan['safety_sha256'],$plan['run_id'],$plan['approval_challenge'],RECOVERY_FINALIZE_CONFIRM),
       'the target ZIP cannot substitute for a separate safety snapshot');
    finalize_test(restore_journal_pending() && is_dir($oldDir)
       && !is_dir($evidence) && !is_file($receipt),
       'rejected attempts leave journal and old photo evidence untouched');

    // A changed active database must make the old challenge unusable.
    $targetData=snap_verified($target)[0];
    $targetLot=array_values(array_filter($targetData['tables']['lots'],
        static fn(array $r):bool=>(int)$r['lot_number']===101))[0];
    $pdo->exec("UPDATE lots SET title='CI mismatch before approval' WHERE lot_number=101");
    $blocked=recovery_finalize_plan($pdo,$safety,$operator);
    finalize_test($blocked['ready']===false
        &&$blocked['reason']==='target_not_independently_verified',
        'plan refuses release if even one database row differs from target');
    $pdo->prepare('UPDATE lots SET title=?,updated_at=? WHERE lot_number=101')
        ->execute([$targetLot['title'],$targetLot['updated_at']]);

    $savedPhotoBytes=file_get_contents($oldDir.'/'.$extraRel);
    if($savedPhotoBytes===false)throw new RuntimeException('Cannot read CI saved photo evidence.');
    file_put_contents($oldDir.'/'.$extraRel,'ci tampered previous photo');
    $wrongPhotos=recovery_finalize_plan($pdo,$safety,$operator);
    finalize_test($wrongPhotos['ready']===false
        &&$wrongPhotos['reason']==='saved_photos_mismatch_safety_zip'
        &&restore_journal_pending(),
        'tampered pre-restore photo evidence blocks approval without clearing journal');
    file_put_contents($oldDir.'/'.$extraRel,$savedPhotoBytes);

    // Different command-line operator must use a different plan approval.
    $different=recovery_finalize_plan($pdo,$safety,'another-operator');
    finalize_test($different['approval_challenge']!==$plan['approval_challenge'],
        'operator identity is bound into the approval challenge');

    [$code,$out,$err]=finalize_cli([
       '--finalize','--safety-snapshot',$safety,'--operator',$operator,
       '--run-id',$plan['run_id'],'--challenge',$plan['approval_challenge'],
       '--confirmation',RECOVERY_FINALIZE_CONFIRM,
       '--external-copy-verified','NO',
       '--external-copy-sha256',$plan['safety_sha256']], $root);
    finalize_test($code===3 && restore_journal_pending()
       && is_dir($oldDir) && !is_file($receipt),
       'CLI requires a separate explicit attestation of verified offsite copy');

    [$code,$out,$err]=finalize_cli([
       '--finalize','--safety-snapshot',$safety,'--operator',$operator,
       '--run-id',$plan['run_id'],'--challenge',$plan['approval_challenge'],
       '--confirmation',RECOVERY_FINALIZE_CONFIRM,
       '--external-copy-verified','IK HEB EEN EXTERNE KOPIE GECONTROLEERD',
       '--external-copy-sha256',str_repeat('0',64)],$root);
    finalize_test($code===3 && restore_journal_pending() && is_dir($oldDir),
       'external backup hash must match the exact safety ZIP SHA-256');

    // Kill another DISPOSABLE PHP process after it has moved old photos to
    // the private recovery directory, but BEFORE it can write a receipt and
    // remove the maintenance journal. Only a fresh operator plan may resume.
    $killHelper=$root.'/private/.ci-finalize-kill-'.bin2hex(random_bytes(6)).'.php';
    $killMarker=sys_get_temp_dir().'/ci-finalize-kill-'.bin2hex(random_bytes(6));
    $helperSource=(string)file_get_contents($root.'/private/restore_finalize.php');
    $point=<<<'PHP'
            if (!rename($oldPath, $savedPath)) {
                throw new RuntimeException('Kon oude foto’s niet privé bewaren.');
            }
PHP;
    $fault=$point."\n".<<<'PHP'
            if(getenv('CI_FINALIZE_KILL')==='yes'){
                file_put_contents((string)getenv('CI_FINALIZE_KILL_MARKER'),'private_evidence_saved');
                posix_kill(getmypid(),9);
                exit(93);
            }
PHP;
    if(substr_count($helperSource,$point)!==1)
        throw new RuntimeException('Approved finalizer injection checkpoint changed.');
    file_put_contents($killHelper,str_replace($point,$fault,$helperSource),LOCK_EX);
    $killCall='require '.var_export($root.'/private/bootstrap.php',true).';'
       .'require '.var_export($killHelper,true).';'
       .'recovery_finalize_approved(db(),'.var_export($safety,true).','
       .var_export($operator,true).','.var_export($plan['safety_sha256'],true).','
       .var_export($plan['run_id'],true).','
       .var_export($plan['approval_challenge'],true).','
       .var_export(RECOVERY_FINALIZE_CONFIRM,true).');';
    $child=proc_open([PHP_BINARY,'-r',$killCall],
       [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root,
       array_merge(getenv(),[
           'CI_FINALIZE_KILL'=>'yes',
           'CI_FINALIZE_KILL_MARKER'=>$killMarker
       ]));
    if(!is_resource($child))throw new RuntimeException('Cannot start CI interrupted finalization.');
    fclose($pipes[0]);
    $killOut=stream_get_contents($pipes[1]);
    $killErr=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);$pipes=[];
    $killStatus=proc_close($child);$child=null;
    finalize_test($killStatus!==0 && is_file($killMarker)
       && restore_journal_pending()
       && !is_dir($oldDir) && is_dir($evidence) && !is_file($receipt),
       'SIGKILL during finalization preserves old photos in private and leaves journal active');

    file_put_contents($receipt,'not valid JSON',LOCK_EX);
    $invalidReceipt=recovery_finalize_plan($pdo,$safety,$operator);
    finalize_test($invalidReceipt['ready']===false
       &&$invalidReceipt['reason']==='invalid_existing_approval_receipt'
       &&restore_journal_pending(),
       'corrupt previous approval receipt leaves interrupted restore in maintenance');
    unlink($receipt);
    file_put_contents($receipt,json_encode([
        'format'=>'41lokeren-restore-finalized-v1',
        'run_id'=>$plan['run_id'],
        'target_sha256'=>$plan['target_sha256'],
        'safety_sha256'=>$plan['safety_sha256'],
        'operator'=>'unknown-writer',
        'read_only_diagnosis_confirmed'=>true,
        'old_photos_privately_preserved'=>true,
    ],JSON_THROW_ON_ERROR),LOCK_EX);
    $wrongReceipt=recovery_finalize_plan($pdo,$safety,$operator);
    finalize_test($wrongReceipt['ready']===false
       &&$wrongReceipt['reason']==='existing_receipt_mismatch'
       &&restore_journal_pending(),
       'mismatched operator in otherwise plausible approval receipt fails closed');
    unlink($receipt);
    $resumed=recovery_finalize_plan($pdo,$safety,$operator);
    finalize_test($resumed['ready']===true
       &&$resumed['preservation_plan']==='old_photos_already_privately_preserved'
       &&$resumed['approval_challenge']!==$plan['approval_challenge'],
       'crashed finalization requires a new approval challenge for already-preserved evidence');
    finalize_reject(fn()=>recovery_finalize_approved($pdo,$safety,$operator,
       $plan['safety_sha256'],$plan['run_id'],$plan['approval_challenge'],RECOVERY_FINALIZE_CONFIRM),
       'old approval challenge cannot resume an interrupted finalization');
    $plan=$resumed;

    // This is the ONLY destructive step, and only on disposable GitHub CI
    // fixtures. User approval is simulated as part of the unit test.
    [$code,$out,$err]=finalize_cli([
       '--finalize','--safety-snapshot',$safety,'--operator',$operator,
       '--run-id',$plan['run_id'],'--challenge',$plan['approval_challenge'],
       '--confirmation',RECOVERY_FINALIZE_CONFIRM,
       '--external-copy-verified','IK HEB EEN EXTERNE KOPIE GECONTROLEERD',
       '--external-copy-sha256',$plan['safety_sha256']
    ],$root);
    if($code!==0)throw new RuntimeException('Approved CI CLI failed: '.$err.' '.$out);
    $done=json_decode($out,true,512,JSON_THROW_ON_ERROR);
    $completed=true;
    finalize_test($done['status']==='finalization_completed'
       &&!restore_journal_pending() && is_file($receipt)
       &&!is_dir($oldDir) && is_dir($evidence),
       'approved finalization clears journal ONLY after private old-photo preservation');
    finalize_test(is_file($evidence.'/'.$extraRel)
       && hash_file('sha256',$evidence.'/'.$extraRel)===$extraHash,
       'pre-restore photo contents retained with original SHA-256 in private backups');
    $receiptData=json_decode((string)file_get_contents($receipt),true,32,JSON_THROW_ON_ERROR);
    finalize_test($receiptData['run_id']===$plan['run_id']
       &&$receiptData['safety_sha256']===$plan['safety_sha256']
       &&$receiptData['operator']===$operator
       &&$receiptData['old_photos_privately_preserved']===true,
       'restricted audit receipt records immutable recovery and approval identifiers');
    $dbNow=restore_recovery_diagnose($pdo);
    $before=hash_file('sha256',snap_path($target));
    finalize_test($dbNow['status']==='clear'
       && hash_file('sha256',snap_path($target))===$before,
       'website is unlocked but immutable target ZIP remains unchanged');

    [$code,$out,$err]=finalize_cli([
       '--finalize','--safety-snapshot',$safety,'--operator',$operator,
       '--run-id',$plan['run_id'],'--challenge',$plan['approval_challenge'],
       '--confirmation',RECOVERY_FINALIZE_CONFIRM,
       '--external-copy-verified','IK HEB EEN EXTERNE KOPIE GECONTROLEERD',
       '--external-copy-sha256',$plan['safety_sha256']
    ],$root);
    finalize_test($code===3 && is_dir($evidence) && is_file($receipt)
       &&!restore_journal_pending(),
       'repeat approval is rejected without changing preserved evidence');
} finally {
    @unlink($fixture);@unlink($flag);
    if($killHelper!==null)@unlink($killHelper);
    if($killMarker!==null)@unlink($killMarker);
    if(is_resource($child)){
       proc_terminate($child,9);
       foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);
       proc_close($child);
    }
    // ONLY CI test cleanup. Production code never does any of this.
    if($journalData!==null){
        $oldDir=dirname(snap_uploads()).'/'.$journalData['old_uploads'];
        if(is_dir($oldDir))snap_remove_tree($oldDir);
    }
    if($evidence!==null&&is_dir($evidence))snap_remove_tree($evidence);
    if($receipt!==null&&is_file($receipt))@unlink($receipt);
    if(restore_journal_pending()&&is_file(restore_journal_path()))
        @unlink(restore_journal_path());
    if($safety!==null&&is_file(snap_path($safety)))@unlink(snap_path($safety));
    if($extraId>0)$pdo->prepare('DELETE FROM lot_images WHERE source_name=?')
        ->execute(['ci-approval-only.jpg']);
    $pdo->prepare('UPDATE lots SET title=? WHERE lot_number=101')->execute([$oldTitle]);
    @unlink($extraFile);
}
echo "T-056: {$passes} operator-approved completion safety checks passed (CI ONLY).\n";
