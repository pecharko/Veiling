<?php
declare(strict_types=1);

/**
 * T-052: disk-journal format, atomic phases and fail-closed corrupt/orphan
 * markers. No production data, snapshots or actual restore are mutated.
 */
$root=dirname(__DIR__);
if(getenv('GITHUB_ACTIONS')!=='true' ||
   getenv('CI_SNAPSHOT_TEST')!=='yes' ||
   getenv('CI_DB_HOST')!=='127.0.0.1' ||
   getenv('CI_DB_NAME')!=='auction_test') {
    throw new RuntimeException('Isolated GitHub Actions test only.');
}
require $root.'/private/bootstrap.php';
require $root.'/private/snapshots.php';
if(db()->query('SELECT DATABASE()')->fetchColumn()!=='auction_test') {
    throw new RuntimeException('Unexpected database.');
}
if(restore_journal_pending())throw new RuntimeException('Journal pending before T-052 unit test.');
$path=restore_journal_path();
$tmp=restore_journal_dir().'/.restore-active.tmp-'.bin2hex(random_bytes(8));
$done=0;
function journal_test(bool $pass,string $name): void {
    global $done;
    if(!$pass)throw new RuntimeException('FAIL T-052: '.$name);
    $done++;
    echo 'PASS T-052: '.$name."\n";
}
function journal_fails(callable $op,string $expected,string $name): void {
    try{$op();}catch(Throwable $e){
        journal_test(str_contains($e->getMessage(),$expected),$name);
        return;
    }
    journal_test(false,$name.' unexpectedly allowed');
}
try {
    $list=snap_listing();
    if(!$list)throw new RuntimeException('No disposable snapshot fixture found.');
    $journal=restore_journal_begin(
        $list[0]['name'],
        dirname(snap_uploads()).'/.uploads-before-restore-'.str_repeat('a',16),
        dirname(snap_uploads()).'/.restore-'.str_repeat('b',16),
        true
    );
    journal_test(restore_journal_pending() && is_file($path)
       && filesize($path)>30 && filesize($path)<4096,
        'durable, private maintenance marker is published');
    $read=restore_journal_read();
    journal_test($read['format']==='41lokeren-restore-journal-v1'
       &&$read['phase']==='prepared'
       &&hash_equals($read['snapshot_sha256'],hash_file('sha256',snap_path($read['snapshot']))),
       'journal metadata and snapshot SHA-256 round trip correctly');
    journal_fails(fn()=>restore_journal_require_clear(),
        'onderbroken','a pending restore rejects new operations');

    restore_journal_phase($journal,'old_saved');
    journal_test(restore_journal_read()['phase']==='old_saved',
        'atomic journal update records the prior photo directory');
    restore_journal_phase($journal,'photos_active');
    journal_test(restore_journal_read()['phase']==='photos_active',
        'journal captures active swapped-photo phase');
    restore_journal_phase($journal,'database_committed');
    journal_test(restore_journal_read()['phase']==='database_committed',
        'journal captures successful MySQL commit');
    journal_fails(fn()=>restore_journal_phase($journal,'invalid'),
        'Onbekende','invalid restore phase cannot be committed');

    file_put_contents($tmp,'incomplete');
    journal_test(restore_journal_pending(),'orphan temporary write is treated as maintenance');
    journal_fails(fn()=>restore_journal_finish(),
        'Onvoltooid','cannot clear verified journal while partial update exists');
    unlink($tmp);
    restore_journal_finish();
    journal_test(!restore_journal_pending(),'completed journal is cleared only after verification');

    file_put_contents($path,'{"format":"nonsense"}');
    journal_test(restore_journal_pending(),'corrupt journal still activates maintenance');
    journal_fails(fn()=>restore_journal_read(),
        'Onjuist','corrupt journal is rejected without trusting paths');
    journal_fails(fn()=>restore_journal_finish(),
        'Onjuist','corrupt journal cannot be silently deleted');
    unlink($path);

    file_put_contents($tmp,'half-written');
    journal_test(restore_journal_pending(),'temporary-only journal blocks startup');
    journal_fails(fn()=>restore_journal_require_clear(),
        'onderbroken','temp-only interrupted journal blocks new snapshot');
    unlink($tmp);
    journal_test(!restore_journal_pending(),'manual cleanup after CI-only review releases guard');
}finally{
    if(is_file($tmp))@unlink($tmp);
    if(is_file($path))@unlink($path);
}
echo "T-052 journal unit integrity: {$done} isolated checks passed.\n";
