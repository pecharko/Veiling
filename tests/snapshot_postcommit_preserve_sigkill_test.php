<?php
declare(strict_types=1);
/**
 * T-059: kill an isolated restore after the complete old-photo directory was
 * atomically moved to private evidence, but before clearing the journal.
 * Never run against production: this test mutates disposable CI MySQL/photos.
 */
if (getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('CI_SNAPSHOT_TEST') !== 'yes'
    || getenv('CI_DB_HOST') !== '127.0.0.1'
    || getenv('CI_DB_NAME') !== 'auction_test') {
    throw new RuntimeException('T-059 requires disposable GitHub Actions/MySQL CI.');
}
$root = dirname(__DIR__);
require $root.'/private/bootstrap.php';
require $root.'/private/snapshots.php';
require_once $root.'/private/restore_finalize.php';
$pdo = db();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'auction_test'
    || !function_exists('posix_kill') || !function_exists('proc_open')) {
    throw new RuntimeException('T-059 refuses unsupported CI environment.');
}
function t059_assert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('FAIL T-059: '.$message);
    echo 'PASS T-059: '.$message."\n";
}
t059_assert(!restore_journal_pending(), 'previous recovery journal is clear');
$target = null;
$targetRows = [];
foreach (snap_listing() as $item) {
    [$data] = snap_verified($item['name']);
    $lots = array_values(array_filter($data['tables']['lots'],
        static fn(array $lot): bool => (int)$lot['lot_number'] === 101));
    if (count($lots) === 1) {
        $target = $item['name'];
        $targetRows = $lots;
        break;
    }
}
if ($target === null) throw new RuntimeException('No verified disposable T-059 target snapshot.');
$uploadRoot = snap_uploads();
$priorRel = '2026/10/'.bin2hex(random_bytes(16)).'.jpg';
$priorPhoto = $uploadRoot.'/'.$priorRel;
$priorFolder = dirname($priorPhoto);
$temp = null;
$marker = sys_get_temp_dir().'/ci-postcommit-preserved-'.bin2hex(random_bytes(8));
$child = null;
$pipes = [];
$safety = null;
try {
    if (!is_dir($priorFolder) && !mkdir($priorFolder, 0755, true)) {
        throw new RuntimeException('Cannot create CI-only prior photo directory.');
    }
    $image = imagecreatetruecolor(24, 24);
    imagefill($image, 0, 0, imagecolorallocate($image, 70, 140, 210));
    if (!imagejpeg($image, $priorPhoto, 90)) {
        throw new RuntimeException('Cannot create CI-only prior photo.');
    }
    imagedestroy($image);
    $priorHash = hash_file('sha256', $priorPhoto);
    $pdo->prepare('UPDATE lots SET title=? WHERE lot_number=101')
        ->execute(['CI pre-restore photos requiring complete preservation']);
    $safety = snap_create($pdo);
    $source = (string)file_get_contents($root.'/private/snapshots.php');
    $needle = '  if($oldSaved){$preservedOld=snap_preserve_previous_photos($old,$journal);$oldSaved=false;}';
    if (substr_count($source, $needle) !== 1) {
        throw new RuntimeException('Post-commit preserve checkpoint changed: review T-059.');
    }
    $hook = <<<'CHILD_HOOK'
  if(getenv('CI_KILL_AFTER_PRIOR_PRESERVED')==='yes'){
   file_put_contents((string)getenv('CI_KILL_MARKER'), 'prior-private');
   posix_kill(getmypid(), 9);
   exit(93);
  }
CHILD_HOOK;
    $temp = $root.'/private/.ci-post-preserved-'.bin2hex(random_bytes(6)).'.php';
    if (file_put_contents($temp, str_replace($needle, $needle."\n".$hook, $source), LOCK_EX) === false) {
        throw new RuntimeException('Cannot prepare disposable CI subprocess.');
    }
    $call = 'require '.var_export($root.'/private/bootstrap.php', true).';'
        .'require '.var_export($temp, true).';snap_restore(db(),'
        .var_export($target, true).');';
    $child = proc_open([PHP_BINARY, '-r', $call],
        [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']], $pipes, $root,
        array_merge(getenv(), ['CI_KILL_AFTER_PRIOR_PRESERVED'=>'yes', 'CI_KILL_MARKER'=>$marker]));
    if (!is_resource($child)) throw new RuntimeException('Unable to start disposable T-059 child.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $pipes = [];
    $exit = proc_close($child);
    $child = null;
    t059_assert($exit !== 0 && is_file($marker), 'restore child killed after private preservation');
    t059_assert(restore_journal_pending(), 'maintenance remains enabled after SIGKILL');
    $journal = restore_journal_read();
    t059_assert($journal['phase'] === 'database_committed', 'journal contains committed-but-not-finished phase');
    $publicOld = dirname($uploadRoot).'/'.$journal['old_uploads'];
    $preservedOld = restore_journal_dir().'/.restore-preserved-old-'.$journal['run_id'];
    t059_assert(!file_exists($publicOld) && is_dir($preservedOld),
        'complete old photo tree was atomically moved out of the public directory');
    t059_assert(is_file($preservedOld.'/'.$priorRel)
        && hash_file('sha256', $preservedOld.'/'.$priorRel) === $priorHash,
        'prior-only JPEG survives bit-for-bit in private evidence');
    t059_assert(!is_file($priorPhoto), 'prior-only JPEG is absent from restored active photos');
    $restoredTitle = $pdo->query('SELECT title FROM lots WHERE lot_number=101')->fetchColumn();
    t059_assert($restoredTitle === $targetRows[0]['title'], 'catalog DB committed target snapshot');
    $plan = recovery_finalize_plan($pdo, $safety, 'ci-post-preserved');
    t059_assert(($plan['status'] ?? null) === 'verified_target_requires_explicit_approval'
        && ($plan['preservation_plan'] ?? null) === 'old_photos_already_privately_preserved'
        && ($plan['maintenance_must_remain'] ?? null) === true,
        'read-only recovery recognizes the complete private previous-photo evidence');
    // CI-only cleanup after independent disposable fixture verification.
    // No live environment or actual operator authorization is involved.
    restore_journal_finish();
    t059_assert(!restore_journal_pending(), 'maintenance marker clears only after verification');
    snap_remove_tree($preservedOld);
    t059_assert(!file_exists($preservedOld), 'old photo evidence cleaned only after journal clearance');
    @unlink(snap_path($safety));
    $safety = null;
} finally {
    if ($temp !== null) @unlink($temp);
    @unlink($marker);
    if (is_resource($child)) {
        proc_terminate($child, 9);
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        proc_close($child);
    }
    // On failure, keep crash evidence for disposable CI diagnosis; no auto
    // journal clearing or attempt at blind restore/rollback.
}
