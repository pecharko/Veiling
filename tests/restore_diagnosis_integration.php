<?php
declare(strict_types=1);

/**
 * T-055: exhaustive, READ-ONLY classification of a pending restore.
 * Use disposable MySQL/ZIP/photos only. No automated recovery is authorized.
 */
$root=dirname(__DIR__);
if(getenv('GITHUB_ACTIONS')!=='true'
 ||getenv('CI_SNAPSHOT_TEST')!=='yes'
 ||getenv('CI_DB_HOST')!=='127.0.0.1'
 ||getenv('CI_DB_NAME')!=='auction_test'){
    throw new RuntimeException('T-055 requires isolated GitHub Actions fixtures.');
}
require $root.'/private/bootstrap.php';
require $root.'/private/restore_diagnosis.php';
$pdo=db();
if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='auction_test')
    throw new RuntimeException('Unexpected database.');
$passed=0;
function diagnosis_assert(bool $condition,string $message): void {
    global $passed;
    if(!$condition)throw new RuntimeException('FAILED T-055: '.$message);
    $passed++;
    echo "PASS T-055: {$message}\n";
}
if(restore_journal_pending())throw new RuntimeException('Journal already pending before test.');
$clear=restore_recovery_diagnose($pdo);
diagnosis_assert($clear['status']==='clear'
    &&$clear['read_only']===true
    &&$clear['will_modify_data']===false,
    'without a journal diagnosis reports only absence of maintenance, not a full integrity audit');
$name=snap_create($pdo);
$parent=dirname(snap_uploads());
$old=$parent.'/.uploads-before-restore-'.bin2hex(random_bytes(8));
$stage=$parent.'/.restore-'.bin2hex(random_bytes(8));
$journalCreated=false;$photoPath=null;$originalPhoto=null;
$beforeTitle=null;$beforeUpdated=null;
try{
    $journal=restore_journal_begin($name,$old,$stage,true);
    $journalCreated=true;
    $report=restore_recovery_diagnose($pdo);
    diagnosis_assert($report['status']==='target_verified_pending_review'
        &&$report['maintenance_must_remain']
        &&$report['database_matches_target']===true
        &&$report['active_photos_match_target']===true
        &&$report['target_archive_sha256_verified']
        &&$report['archive_photo_hashes_verified'],
        'full DB and all active ZIP photo hashes can match without permitting journal clearance');
    $json=json_encode($report,JSON_THROW_ON_ERROR);
    diagnosis_assert(!str_contains($json,'ci-admin@example.invalid')
      &&!str_contains($json,'ci-password-not-a-real-secret')
      &&!str_contains($json,'password_hash')
      &&!str_contains($json,$root)
      &&$report['next_step']==='KEEP_MAINTENANCE_AND_OBTAIN_HUMAN_REVIEW',
      'report emits classifications only, never passwords, rows or server paths');

    // The CLI entrypoint supports exactly --plan and never has a mutator mode.
    $cmd=[PHP_BINARY,$root.'/scripts/restore_diagnose.php','--plan'];
    $child=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);
    if(!is_resource($child))throw new RuntimeException('Unable to start CLI readonly tool.');
    fclose($pipes[0]);
    $out=stream_get_contents($pipes[1]);
    $err=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    $status=proc_close($child);
    $cli=json_decode($out,true,512,JSON_THROW_ON_ERROR);
    diagnosis_assert($status===2 && $err==='' && $cli['status']===$report['status']
        && $cli['will_modify_data']===false && restore_journal_pending(),
        'CLI --plan returns structured pending status without mutating journal');
    $cmd=[PHP_BINARY,$root.'/scripts/restore_diagnose.php','--restore'];
    $child=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);
    if(!is_resource($child))throw new RuntimeException('Cannot start rejected CLI mode.');
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($child);
    diagnosis_assert($status===64 && restore_journal_pending(),
        'no CLI restore or journal-clear command exists');

    $original=$pdo->query('SELECT title,updated_at FROM lots WHERE lot_number=101')->fetch();
    if(!$original)throw new RuntimeException('Missing CI lot 101.');
    $beforeTitle=(string)$original['title'];$beforeUpdated=(string)$original['updated_at'];
    $pdo->exec("UPDATE lots SET title='CI diagnosis mismatched title' WHERE lot_number=101");
    $different=restore_recovery_diagnose($pdo);
    diagnosis_assert($different['status']==='mixed_or_unknown_keep_maintenance'
      &&$different['database_matches_target']===false
      &&in_array('lots',$different['database_different_tables'],true)
      &&$different['active_photos_match_target']===true,
      'one changed database table is detected while all archived photos still match');
    $pdo->prepare('UPDATE lots SET title=?,updated_at=? WHERE lot_number=101')
        ->execute([$beforeTitle,$beforeUpdated]);$beforeTitle=null;

    [$data,$hashes]=snap_verified($name);
    $photos=$hashes;unset($photos['database.json']);
    if(!$photos)throw new RuntimeException('CI fixture must include a photo.');
    $relative=substr(array_key_first($photos),strlen('uploads/'));
    $photoPath=snap_uploads().'/'.$relative;
    $originalPhoto=file_get_contents($photoPath);
    if($originalPhoto===false)throw new RuntimeException('Could not read CI photo.');
    file_put_contents($photoPath,'CI modified photo');
    $different=restore_recovery_diagnose($pdo);
    diagnosis_assert($different['status']==='mixed_or_unknown_keep_maintenance'
      &&$different['database_matches_target']===true
      &&$different['active_photos_match_target']===false
      &&$different['active_photo_status']==='photo_content_mismatch',
      'changed photo content is detected despite unchanged DB rows');
    file_put_contents($photoPath,$originalPhoto);$originalPhoto=null;

    // Malicious or corrupted archive must be detected using the JOURNAL digest.
    file_put_contents(snap_path($name),'one_extra_byte',FILE_APPEND|LOCK_EX);
    $blocked=restore_recovery_diagnose($pdo);
    diagnosis_assert($blocked['status']==='blocked_unverified'
       &&$blocked['reason']==='target_archive_sha256_mismatch'
       &&$blocked['maintenance_must_remain'],
       'modified ZIP cannot be trusted even if the journal says prepared');
    restore_journal_finish();$journalCreated=false;
    @unlink(snap_path($name));

    // Without a journal, any orphan old uploads directory is still a blocker.
    if(!mkdir($old,0700))throw new RuntimeException('Could not create CI orphan directory.');
    $blocked=restore_recovery_diagnose($pdo);
    diagnosis_assert($blocked['status']==='blocked_unverified'
      &&$blocked['reason']==='missing_or_invalid_journal'
      &&$blocked['maintenance_must_remain'],
      'orphan previous uploads without journal remain in read-only quarantine');
    rmdir($old);
    diagnosis_assert(restore_recovery_diagnose($pdo)['status']==='clear'
       &&!restore_journal_pending(),
       'after CI-only cleanup report recognizes absence of pending marker');
}finally{
    if($originalPhoto!==null&&$photoPath!==null)file_put_contents($photoPath,$originalPhoto);
    if($beforeTitle!==null)$pdo->prepare('UPDATE lots SET title=?,updated_at=? WHERE lot_number=101')
        ->execute([$beforeTitle,$beforeUpdated]);
    if(is_dir($old))@rmdir($old);
    if($journalCreated && is_file(restore_journal_path()))@unlink(restore_journal_path());
    if(is_file(snap_path($name)))@unlink(snap_path($name));
}
echo "T-055: {$passed} read-only classifier checks passed.\n";
