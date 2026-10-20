<?php
declare(strict_types=1);

/**
 * T-057: after SIGKILL before COMMIT, restore the original photo tree ONLY
 * when the entire live DB matches a separate, verified pre-restore safety ZIP.
 *
 * Test runs exclusively on throwaway GitHub Actions MySQL auction_test,
 * temporary photos and ZIP fixtures, never production.
 */
$root = dirname(__DIR__);
if (getenv('GITHUB_ACTIONS') !== 'true' ||
    getenv('CI_SNAPSHOT_TEST') !== 'yes' ||
    getenv('CI_DB_HOST') !== '127.0.0.1' ||
    getenv('CI_DB_NAME') !== 'auction_test') {
    throw new RuntimeException('T-057 refuses to run outside disposable MySQL CI.');
}
require $root . '/private/bootstrap.php';
require $root . '/private/restore_precommit.php';
$pdo = db();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'auction_test' ||
    !function_exists('proc_open') || !function_exists('posix_kill')) {
    throw new RuntimeException('Unexpected DB or no process fault-injection support.');
}
function original_recovery_assert(bool $ok, string $description): void {
    static $checks = 0;
    if (!$ok) throw new RuntimeException('FAILED T-057: ' . $description);
    $checks++;
    echo "PASS T-057 #{$checks}: {$description}\n";
}
function original_recovery_reject(callable $fn, string $description): void {
    try { $fn(); }
    catch (Throwable $error) {
        original_recovery_assert(true, $description);
        return;
    }
    original_recovery_assert(false, $description . ' unexpectedly succeeded');
}
function original_recovery_cli(array $arguments, string $root): array {
    $proc = proc_open(array_merge(
        [PHP_BINARY, $root . '/scripts/restore_precommit.php'], $arguments
    ), [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, $root);
    if (!is_resource($proc)) throw new RuntimeException('Cannot start isolated CLI.');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    return [proc_close($proc), $out, $err];
}
function original_recovery_kill(string $module, string $code, array $env, string $root): array {
    $proc = proc_open([PHP_BINARY, '-r', $code],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes,$root,
        array_merge(getenv(),$env));
    if (!is_resource($proc)) throw new RuntimeException('Cannot start CI killed worker.');
    fclose($pipes[0]);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    return [proc_close($proc),$out,$err];
}
if (restore_journal_pending())throw new RuntimeException('Recovery evidence already exists in CI.');
$operator='ci-precommit-operator';
$lot=(array)$pdo->query('SELECT id,title,updated_at FROM lots WHERE lot_number=101')->fetch(PDO::FETCH_ASSOC);
if (!$lot || (int)$lot['id']<=0)throw new RuntimeException('Fixture lot #101 missing.');
$originalTitle=(string)$lot['title'];
$originalUpdated=(string)$lot['updated_at'];
$lotId=(int)$lot['id'];
$target=null;
foreach (snap_listing() as $item) {
    [$db]=snap_verified($item['name']);
    foreach ($db['tables']['lots'] as $row) {
        if ((int)$row['lot_number']===101) {$target=$item['name'];break 2;}
    }
}
if ($target===null)throw new RuntimeException('Target CI snapshot missing.');
$relative='2026/10/'.bin2hex(random_bytes(16)).'.jpg';
$current=snap_uploads().'/'.$relative;
$extraId=0;$safety=null;$journal=null;$savedTarget=null;$receipt=null;
$files=[];$flags=[];$photoHash=null;$completed=false;
try {
    // The safety snapshot must capture all original DB rows and an extra
    // current photo which are absent from the older target snapshot.
    $pdo->exec("UPDATE lots SET title='CI original DB before interrupted restore' WHERE lot_number=101");
    if (!is_dir(dirname($current)))mkdir(dirname($current),0700,true);
    $img=imagecreatetruecolor(29,29);
    imagefill($img,0,0,imagecolorallocate($img,34,78,130));
    if (!imagejpeg($img,$current,90)) throw new RuntimeException('Could not write disposable image.');
    imagedestroy($img);
    $photoHash=hash_file('sha256',$current);
    $pdo->prepare('INSERT INTO lot_images(lot_id,path,sort_order,source_name) VALUES (?,?,?,?)')
        ->execute([$lotId,'uploads/'.$relative,987,'ci-precommit-only.jpg']);
    $extraId=(int)$pdo->lastInsertId();
    $safety=snap_create($pdo);
    original_recovery_assert($safety!==$target,'separate safety and target ZIPs exist');

    // A temporary source COPY crashes only the child immediately after
    // photo swap; database transaction must not have begun.
    $snapshotSource=(string)file_get_contents($root.'/private/snapshots.php');
    $needle="  restore_journal_phase(\$journal,'photos_active');";
    if (substr_count($snapshotSource,$needle)!==1)
        throw new RuntimeException('Snapshot SIGKILL marker changed.');
    $checkpoint=$needle."\n".<<<'PHP'
  if(getenv('CI_PRECOMMIT_KILL')==='yes'){
    file_put_contents((string)getenv('CI_PRECOMMIT_MARKER'),'swapped');
    posix_kill(getmypid(),9);
  }
PHP;
    $crashSource=$root.'/private/.ci-precommit-'.bin2hex(random_bytes(6)).'.php';
    $files[]=$crashSource;
    file_put_contents($crashSource,str_replace($needle,$checkpoint,$snapshotSource),LOCK_EX);
    $flag=sys_get_temp_dir().'/ci-precommit-'.bin2hex(random_bytes(8));
    $flags[]=$flag;
    $script='require '.var_export($root.'/private/bootstrap.php',true).';'
          .'require '.var_export($crashSource,true).';'
          .'snap_restore(db(),'.var_export($target,true).');';
    [$code,$out,$err]=original_recovery_kill($crashSource,$script,
        ['CI_PRECOMMIT_KILL'=>'yes','CI_PRECOMMIT_MARKER'=>$flag],$root);
    original_recovery_assert($code!==0&&is_file($flag)&&restore_journal_pending(),
        'SIGKILL after photo swap before DB commit leaves a pending journal');
    $journal=restore_journal_read();
    original_recovery_assert($journal['snapshot']===$target &&
        $journal['phase']==='photos_active','journal captures interrupted photo swap');
    $old=dirname(snap_uploads()).'/'.$journal['old_uploads'];
    $savedTarget=recovery_precommit_saved_target($journal);
    $receipt=recovery_precommit_receipt($journal);
    original_recovery_assert(is_file($old.'/'.$relative) &&
        hash_file('sha256',$old.'/'.$relative)===$photoHash &&
        !is_file($current),'old CI photo survives while target photos are active');

    $plan=recovery_precommit_plan($pdo,$safety,$operator);
    original_recovery_assert($plan['ready'] && $plan['photo_state']==='initial' &&
        $plan['database_matches_original_safety'] &&
        !$plan['database_matches_target'] &&
        $plan['original_photos_match_safety'] &&
        $plan['target_photos_verified'],
        'read-only plan verifies whole original DB + both photo ZIPs');

    [$exit,$stdout,$stderr]=original_recovery_cli([
        '--plan','--safety-snapshot',$safety,'--operator',$operator],$root);
    $cli=json_decode($stdout,true,512,JSON_THROW_ON_ERROR);
    original_recovery_assert($exit===2 && $cli['ready'] &&
        $cli['approval_challenge']===$plan['approval_challenge'] &&
        is_dir($old) && restore_journal_pending(),
        'read-only CLI emits exact approval challenge without mutating files');

    original_recovery_reject(fn()=>recovery_precommit_approved(
        $pdo,$safety,$operator,$plan['safety_sha256'],$plan['run_id'],
        str_repeat('0',64),RECOVERY_PRECOMMIT_CONFIRM),
        'incorrect approval token cannot move old photos');
    original_recovery_reject(fn()=>recovery_precommit_approved(
        $pdo,$safety,$operator,$plan['safety_sha256'],$plan['run_id'],
        $plan['approval_challenge'],'WRONG CONFIRMATION'),
        'incorrect operator confirmation is rejected');
    original_recovery_reject(fn()=>recovery_precommit_approved(
        $pdo,$target,$operator,$plan['safety_sha256'],$plan['run_id'],
        $plan['approval_challenge'],RECOVERY_PRECOMMIT_CONFIRM),
        'target snapshot is not a separate safety ZIP');
    [$exit]=original_recovery_cli([
        '--rollback','--safety-snapshot',$safety,'--operator',$operator,
        '--run-id',$plan['run_id'],'--challenge',$plan['approval_challenge'],
        '--confirmation',RECOVERY_PRECOMMIT_CONFIRM,
        '--external-copy-verified','IK HEB EEN EXTERNE KOPIE GECONTROLEERD',
        '--external-copy-sha256',str_repeat('0',64)],$root);
    original_recovery_assert($exit===3 && restore_journal_pending() &&
        is_dir($old) && !is_dir($savedTarget),
        'mismatched external safety ZIP SHA cannot authorize photo rollback');

    $before=(array)$pdo->query('SELECT title,updated_at FROM lots WHERE lot_number=101')
        ->fetch(PDO::FETCH_ASSOC);
    $pdo->exec("UPDATE lots SET title='CI changed DB invalidates recovery plan' WHERE lot_number=101");
    $blocked=recovery_precommit_plan($pdo,$safety,$operator);
    original_recovery_assert(!$blocked['ready']&&
        $blocked['reason']==='database_differs_from_safety_zip',
        'any MySQL change blocks a photo-only rollback');
    $pdo->prepare('UPDATE lots SET title=?,updated_at=? WHERE lot_number=101')
        ->execute([$before['title'],$before['updated_at']]);

    $photoBytes=(string)file_get_contents($old.'/'.$relative);
    file_put_contents($old.'/'.$relative,'CI tampered original photo');
    $blocked=recovery_precommit_plan($pdo,$safety,$operator);
    original_recovery_assert(!$blocked['ready'] &&
        $blocked['reason']==='original_photos_do_not_match_safety_zip',
        'tampered previous photo evidence blocks restoration');
    file_put_contents($old.'/'.$relative,$photoBytes);

    // SIGKILL at first ATOMIC rename. The already moved target photo tree
    // must be retained under private/backups; journal must remain pending.
    $originalRecovery=(string)file_get_contents($root.'/private/restore_precommit.php');
    $first=<<<'PHP'
            if (!rename($uploads, $savedTarget)) {
                throw new RuntimeException('Kon doelfoto’s niet privé bewaren.');
            }
            $state = 'target_privately_preserved';
PHP;
    $firstInjected=$first."\n".<<<'PHP'
            if(getenv('CI_PRECOMMIT_KILL_STEP')==='one'){
                file_put_contents((string)getenv('CI_PRECOMMIT_STEP_MARKER'),'target_preserved');
                posix_kill(getmypid(),9);
            }
PHP;
    if(substr_count($originalRecovery,$first)!==1)throw new RuntimeException('First recovery injection changed.');
    $firstPath=$root.'/private/.ci-precommit-step1-'.bin2hex(random_bytes(6)).'.php';
    $files[]=$firstPath;
    file_put_contents($firstPath,str_replace($first,$firstInjected,$originalRecovery),LOCK_EX);
    $flag=sys_get_temp_dir().'/ci-precommit-step1-'.bin2hex(random_bytes(8));
    $flags[]=$flag;
    $script='require '.var_export($root.'/private/bootstrap.php',true).';'
          .'require '.var_export($firstPath,true).';'
          .'recovery_precommit_approved(db(),'.var_export($safety,true).','
          .var_export($operator,true).','.var_export($plan['safety_sha256'],true).','
          .var_export($plan['run_id'],true).','
          .var_export($plan['approval_challenge'],true).','
          .var_export(RECOVERY_PRECOMMIT_CONFIRM,true).');';
    [$code,$out,$err]=original_recovery_kill($firstPath,$script,[
        'CI_PRECOMMIT_KILL_STEP'=>'one','CI_PRECOMMIT_STEP_MARKER'=>$flag],$root);
    original_recovery_assert($code!==0&&is_file($flag) &&
        restore_journal_pending() && !is_dir(snap_uploads()) &&
        is_dir($old) && is_dir($savedTarget),
        'SIGKILL during first rename leaves both photo sets preserved and maintenance active');

    $resume=recovery_precommit_plan($pdo,$safety,$operator);
    original_recovery_assert($resume['ready'] &&
        $resume['photo_state']==='target_privately_preserved' &&
        $resume['approval_challenge']!==$plan['approval_challenge'],
        'interrupted private preservation requires new plan and approval');
    original_recovery_reject(fn()=>recovery_precommit_approved(
        $pdo,$safety,$operator,$plan['safety_sha256'],$plan['run_id'],
        $plan['approval_challenge'],RECOVERY_PRECOMMIT_CONFIRM),
        'stale first-stage approval is rejected after SIGKILL');

    // SIGKILL after reactivating ORIGINAL photos; journal remains until
    // independent DB+photo checks and operator re-approval.
    $second=<<<'PHP'
            if (!rename($old, $uploads)) {
                throw new RuntimeException('Kon originele foto’s niet activeren.');
            }
            $state = 'original_reactivated';
PHP;
    $secondInjected=$second."\n".<<<'PHP'
            if(getenv('CI_PRECOMMIT_KILL_STEP')==='two'){
                file_put_contents((string)getenv('CI_PRECOMMIT_STEP_MARKER'),'original_reactivated');
                posix_kill(getmypid(),9);
            }
PHP;
    if(substr_count($originalRecovery,$second)!==1)
        throw new RuntimeException('Second recovery injection changed.');
    $secondPath=$root.'/private/.ci-precommit-step2-'.bin2hex(random_bytes(6)).'.php';
    $files[]=$secondPath;
    file_put_contents($secondPath,str_replace($second,$secondInjected,$originalRecovery),LOCK_EX);
    $flag=sys_get_temp_dir().'/ci-precommit-step2-'.bin2hex(random_bytes(8));
    $flags[]=$flag;
    $script='require '.var_export($root.'/private/bootstrap.php',true).';'
          .'require '.var_export($secondPath,true).';'
          .'recovery_precommit_approved(db(),'.var_export($safety,true).','
          .var_export($operator,true).','.var_export($resume['safety_sha256'],true).','
          .var_export($resume['run_id'],true).','
          .var_export($resume['approval_challenge'],true).','
          .var_export(RECOVERY_PRECOMMIT_CONFIRM,true).');';
    [$code,$out,$err]=original_recovery_kill($secondPath,$script,[
        'CI_PRECOMMIT_KILL_STEP'=>'two','CI_PRECOMMIT_STEP_MARKER'=>$flag],$root);
    original_recovery_assert($code!==0&&is_file($flag) &&
        restore_journal_pending() && is_dir(snap_uploads()) &&
        !is_dir($old) && is_dir($savedTarget) &&
        hash_file('sha256',$current)===$photoHash,
        'SIGKILL after original photo reactivation preserves both trees and journal');

    $last=recovery_precommit_plan($pdo,$safety,$operator);
    original_recovery_assert($last['ready'] &&
        $last['photo_state']==='original_reactivated' &&
        $last['approval_challenge']!==$resume['approval_challenge'],
        'new operator approval required after second SIGKILL');

    [$exit,$stdout,$stderr]=original_recovery_cli([
        '--rollback','--safety-snapshot',$safety,'--operator',$operator,
        '--run-id',$last['run_id'],'--challenge',$last['approval_challenge'],
        '--confirmation',RECOVERY_PRECOMMIT_CONFIRM,
        '--external-copy-verified','IK HEB EEN EXTERNE KOPIE GECONTROLEERD',
        '--external-copy-sha256',$last['safety_sha256']],$root);
    if($exit!==0)throw new RuntimeException('Approved CI photo rollback rejected: '.$stderr.' '.$stdout);
    $done=json_decode($stdout,true,512,JSON_THROW_ON_ERROR);
    $completed=true;
    original_recovery_assert($done['status']==='original_state_reactivated_after_approval' &&
        !$done['database_changes_made'] && !$done['photos_deleted'] &&
        !restore_journal_pending() && is_file($receipt),
        'approved completion clears journal only after verifying DB and photos');
    original_recovery_assert(is_file($current) &&
        hash_file('sha256',$current)===$photoHash &&
        recovery_compare_photos($savedTarget,snap_verified($target)[1])['matches_target'],
        'original active photos and target private photos are both retained byte-for-byte');
    $dbCheck=recovery_compare_database($pdo,snap_verified($safety)[0]);
    original_recovery_assert($dbCheck['matches_target'],
        'complete original MySQL state remains unchanged by rollback');
    $receiptData=json_decode((string)file_get_contents($receipt),true,32,JSON_THROW_ON_ERROR);
    original_recovery_assert($receiptData['format']==='41lokeren-original-photo-rollback-v1' &&
        $receiptData['run_id']===$journal['run_id'] &&
        $receiptData['safety_sha256']===$last['safety_sha256'] &&
        $receiptData['target_photos_privately_preserved']===true,
        'private approval receipt identifies the original safety ZIP and preserved target files');
    [$repeat]=original_recovery_cli([
        '--rollback','--safety-snapshot',$safety,'--operator',$operator,
        '--run-id',$last['run_id'],'--challenge',$last['approval_challenge'],
        '--confirmation',RECOVERY_PRECOMMIT_CONFIRM,
        '--external-copy-verified','IK HEB EEN EXTERNE KOPIE GECONTROLEERD',
        '--external-copy-sha256',$last['safety_sha256']],$root);
    original_recovery_assert($repeat===3 && is_dir($savedTarget) &&
        is_file($receipt) && !restore_journal_pending(),
        'repeat approval cannot mutate privately preserved evidence');
} finally {
    foreach($files as $path)@unlink($path);
    foreach($flags as $path)@unlink($path);
    // CI cleanup ONLY; production recovery helper never deletes these.
    if($journal!==null) {
        $old=dirname(snap_uploads()).'/'.$journal['old_uploads'];
        if(is_dir($old))snap_remove_tree($old);
    }
    if($savedTarget!==null&&is_dir($savedTarget))snap_remove_tree($savedTarget);
    if($receipt!==null&&is_file($receipt))@unlink($receipt);
    if(is_file(restore_journal_path()))@unlink(restore_journal_path());
    if($safety!==null&&is_file(snap_path($safety)))@unlink(snap_path($safety));
    if($extraId>0)$pdo->prepare('DELETE FROM lot_images WHERE source_name=?')
        ->execute(['ci-precommit-only.jpg']);
    $pdo->prepare('UPDATE lots SET title=?,updated_at=? WHERE lot_number=101')
        ->execute([$originalTitle,$originalUpdated]);
    @unlink($current);
}
echo "T-057: approved pre-commit photo-only recovery and two crash boundaries passed (CI ONLY).\n";
