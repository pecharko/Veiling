<?php
declare(strict_types=1);

/**
 * T-058: read-only forensic comparison of an interrupted restore against
 * BOTH separate snapshots, including synthetic mixed and untrusted data.
 *
 * Disposable GitHub Actions MySQL/ZIP/photos ONLY. Production is not accessed.
 * All deliberate SQL/photo mutations below are fixture setup/cleanup, never
 * part of the forensic module or its CLI.
 */
$root = dirname(__DIR__);
if (getenv('GITHUB_ACTIONS') !== 'true' ||
    getenv('CI_SNAPSHOT_TEST') !== 'yes' ||
    getenv('CI_DB_HOST') !== '127.0.0.1' ||
    getenv('CI_DB_NAME') !== 'auction_test') {
    throw new RuntimeException('T-058 requires disposable auction_test MySQL.');
}
require $root . '/private/bootstrap.php';
require $root . '/private/restore_mixed_diagnosis.php';
$pdo = db();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'auction_test') {
    throw new RuntimeException('Unexpected MySQL database.');
}
if (restore_journal_pending()) {
    throw new RuntimeException('Pre-existing incident evidence before CI test.');
}
$passes = 0;
function mixed_check(bool $value, string $description): void {
    global $passes;
    if (!$value) throw new RuntimeException('FAILED T-058: ' . $description);
    $passes++;
    echo "PASS T-058 #{$passes}: {$description}\n";
}
function mixed_cli(string $root, array $arguments): array {
    $proc = proc_open(array_merge([
        PHP_BINARY, $root . '/scripts/restore_mixed_diagnose.php',
    ], $arguments), [
        0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w'],
    ], $pipes, $root);
    if (!is_resource($proc)) throw new RuntimeException('Cannot start CI CLI.');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    return [proc_close($proc), $output, $error];
}

$safety = null;
$target = null;
$journal = null;
$oldDir = null;
$originalPhoto = null;
$photoPath = null;
$oldPhoto = null;
$zipOriginal = null;
try {
    $safety = snap_create($pdo);
    [$originalDb, $originalHashes] = snap_verified($safety);
    $originalPhotos = $originalHashes;
    unset($originalPhotos['database.json']);
    if (!$originalPhotos) throw new RuntimeException('CI baseline photo missing.');
    $firstPhoto = array_key_first($originalPhotos);
    $relative = substr($firstPhoto, strlen('uploads/'));
    $photoPath = snap_uploads() . '/' . $relative;
    $originalPhoto = file_get_contents($photoPath);
    if ($originalPhoto === false) throw new RuntimeException('CI photo unreadable.');

    // Prepare target ZIP with two changed DB tables and different photo bytes.
    $pdo->exec("UPDATE lots SET title='CI forensic target lot' WHERE lot_number=101");
    $pdo->exec("UPDATE settings SET title='CI forensic target settings' WHERE id=1");
    if (file_put_contents($photoPath, 'CI forensic target JPEG bytes') === false) {
        throw new RuntimeException('Cannot mutate disposable photo fixture.');
    }
    $target = snap_create($pdo);
    [$targetDb, $targetHashes] = snap_verified($target);
    mixed_check($target !== $safety &&
        $targetHashes[$firstPhoto] !== $originalHashes[$firstPhoto],
        'separate safety and target ZIPs hold different complete image hashes');

    // Simulate a pending restore journal, leaving the TARGET data and images
    // active while the journal's phase is deliberately NOT proof of commit.
    $parent = dirname(snap_uploads());
    $oldDir = $parent . '/.uploads-before-restore-' . bin2hex(random_bytes(8));
    $stage = $parent . '/.restore-' . bin2hex(random_bytes(8));
    $journal = restore_journal_begin($target, $oldDir, $stage, true);
    restore_journal_phase($journal, 'photos_active');
    $targetReport = restore_mixed_diagnose($pdo, $safety);
    mixed_check($targetReport['status'] === 'target_state_matches_but_keep_maintenance'
        && $targetReport['database_matches_target'] === true
        && $targetReport['database_matches_safety'] === false
        && $targetReport['photo_set_states']['active']['matches_target'] === true
        && $targetReport['maintenance_must_remain'] === true,
        'full matching target state remains read-only and journal stays pending');
    mixed_check($targetReport['database_table_states']['lots'] === 'target_only'
        && $targetReport['database_table_states']['settings'] === 'target_only'
        && $targetReport['database_table_states']['admins'] === 'both',
        'per-table labels distinguish changed and unchanged snapshot tables');

    $json = json_encode($targetReport, JSON_THROW_ON_ERROR);
    mixed_check($targetReport['will_modify_data'] === false &&
        !str_contains($json, 'ci-admin@example.invalid') &&
        !str_contains($json, 'password_hash') &&
        !str_contains($json, $root) &&
        !str_contains($json, 'CI forensic target lot'),
        'diagnosis discloses neither private row data nor filesystem paths');

    [$status, $output, $stderr] = mixed_cli($root, [
        '--plan','--safety-snapshot',$safety,
    ]);
    $plan = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
    mixed_check($status === 2 && $stderr === '' &&
        $plan['status'] === $targetReport['status'] &&
        $plan['will_modify_data'] === false && restore_journal_pending(),
        'CLI --plan preserves the journal and emits only classification');
    [$status] = mixed_cli($root, [
        '--restore','--safety-snapshot',$safety,
    ]);
    mixed_check($status === 64 && restore_journal_pending(),
        'mutating CLI option is unavailable, even during an incident');

    // One full table is from the safety ZIP, another from target => mixed DB.
    $origLot = array_values(array_filter($originalDb['tables']['lots'],
        static fn(array $r):bool=>(int)$r['lot_number'] === 101))[0];
    $pdo->prepare('UPDATE lots SET title=?, updated_at=? WHERE lot_number=101')
        ->execute([$origLot['title'], $origLot['updated_at']]);
    $mixed = restore_mixed_diagnose($pdo, $safety);
    mixed_check($mixed['status'] === 'database_not_equal_to_either_archive'
        && $mixed['database_table_states']['lots'] === 'safety_only'
        && $mixed['database_table_states']['settings'] === 'target_only'
        && $mixed['database_matches_target'] === false
        && $mixed['database_matches_safety'] === false,
        'one old DB table plus one target DB table is a prohibited mixed state');

    // A row belonging to neither archive is explicitly 'neither'; no SQL
    // reconstruction is offered, not even a plan that permits mutation.
    $pdo->exec("UPDATE lots SET title='CI forensic unknown third state' WHERE lot_number=101");
    $unknown = restore_mixed_diagnose($pdo, $safety);
    mixed_check($unknown['status'] === 'database_not_equal_to_either_archive'
        && $unknown['database_table_states']['lots'] === 'neither'
        && $unknown['maintenance_must_remain'],
        'unknown third-state database row remains blocked without reconstruction');

    // The stored old photo directory can be independently identified as
    // safety data, but that does not make a mixed database safe to modify.
    $oldPhoto = $oldDir . '/' . $relative;
    if (!mkdir(dirname($oldPhoto),0700,true) ||
        file_put_contents($oldPhoto,$originalPhoto) === false) {
        throw new RuntimeException('Cannot prepare disposable old-photo directory.');
    }
    $withOld = restore_mixed_diagnose($pdo, $safety);
    mixed_check($withOld['photo_set_states']['original_previous']['present']
        && $withOld['photo_set_states']['original_previous']['matches_safety']
        && $withOld['photo_set_states']['active']['matches_target']
        && $withOld['status'] === 'database_not_equal_to_either_archive',
        'both intact photo trees do not authorize repair of a mixed database');

    // Corrupted old photos are flagged without modifying the active target.
    file_put_contents($oldPhoto,'CI forensic damaged previous photo');
    $firstUntrusted = recovery_mixed_tree_fingerprint($oldDir);
    file_put_contents($oldPhoto,'CI forensic different damaged previous photo');
    $secondUntrusted = recovery_mixed_tree_fingerprint($oldDir);
    mixed_check($firstUntrusted !== null &&
        $secondUntrusted !== null &&
        !hash_equals($firstUntrusted, $secondUntrusted),
        'full evidence fingerprints detect changes even when both photos are already untrusted');
    $badOld = restore_mixed_diagnose($pdo, $safety);
    mixed_check($badOld['photo_set_states']['original_previous']['present']
        && !$badOld['photo_set_states']['original_previous']['matches_safety']
        && $badOld['maintenance_must_remain'],
        'corrupt old photo evidence is visible without copying or deleting it');
    file_put_contents($oldPhoto, $originalPhoto);

    // An altered archive can no longer be treated as the safety baseline.
    $zipOriginal = file_get_contents(snap_path($target));
    if ($zipOriginal === false) throw new RuntimeException('Could not read CI ZIP.');
    file_put_contents(snap_path($target),'ci corrupted ZIP',FILE_APPEND|LOCK_EX);
    $tampered = restore_mixed_diagnose($pdo, $safety);
    mixed_check($tampered['status'] === 'blocked_unverified' &&
        $tampered['reason'] === 'target_or_journal_checksum_invalid',
        'target archive modified after journaling triggers fail-closed verdict');
    file_put_contents(snap_path($target),$zipOriginal);
    $zipOriginal = null;

    // Journal phase cannot be used as SQL commit evidence.
    restore_journal_phase($journal,'database_committed');
    $stillUnknown = restore_mixed_diagnose($pdo,$safety);
    mixed_check($stillUnknown['status'] === 'database_not_equal_to_either_archive'
        && $stillUnknown['journal_phase_is_not_commit_proof'],
        'even database_committed journal label cannot bless mixed actual rows');

    // Restore a COMPLETE original DB view while target photos remain live:
    // this is a known mismatched data/photo state, not a green site.
    foreach ($originalDb['tables']['settings'] as $settings) {
        $pdo->prepare('UPDATE settings SET title=?,updated_at=? WHERE id=?')
            ->execute([$settings['title'],$settings['updated_at'],$settings['id']]);
    }
    $pdo->prepare('UPDATE lots SET title=?,updated_at=? WHERE lot_number=101')
        ->execute([$origLot['title'],$origLot['updated_at']]);
    $safetyDbTargetPhotos = restore_mixed_diagnose($pdo,$safety);
    mixed_check($safetyDbTargetPhotos['status'] === 'database_and_photo_state_differ'
        && $safetyDbTargetPhotos['database_matches_safety'] === true
        && $safetyDbTargetPhotos['photo_set_states']['active']['matches_target'],
        'complete safety DB with target photos is detected as mismatched');
    mixed_check(restore_journal_pending(), 'no incident evidence has been altered');

    // No journal is never a full validation result; it is a blocked incident
    // diagnostic even if the caller passes known ZIP names.
    if (is_dir($oldDir)) snap_remove_tree($oldDir);
    $oldDir = null;
    restore_journal_finish();
    $journal = null;
    $absent = restore_mixed_diagnose($pdo,$safety);
    mixed_check($absent['status']==='blocked_unverified' &&
        $absent['reason']==='no_pending_restore',
        'absence of a journal never produces a green forensic incident report');
} finally {
    // ONLY disposable CI fixture cleanup. The product diagnosis cannot mutate
    // SQL rows, files or journals and has no recovery endpoint.
    if ($oldDir !== null && is_dir($oldDir)) snap_remove_tree($oldDir);
    if (is_file(restore_journal_path())) @unlink(restore_journal_path());
    if ($zipOriginal !== null && $target !== null) {
        file_put_contents(snap_path($target),$zipOriginal);
    }
    // Restore original CI database and image using the pre-test safety ZIP.
    if ($safety !== null && is_file(snap_path($safety))) {
        snap_restore($pdo,$safety);
    }
    if ($target !== null && is_file(snap_path($target))) @unlink(snap_path($target));
    if ($safety !== null && is_file(snap_path($safety))) @unlink(snap_path($safety));
    if ($originalPhoto !== null && $photoPath !== null && is_file($photoPath)) {
        // Full ZIP restore already replaced photo. This is a last CI check.
        if (hash_file('sha256',$photoPath) !== hash('sha256',$originalPhoto)) {
            throw new RuntimeException('Disposable photo not recovered after CI test.');
        }
    }
}
echo "T-058: {$passes} read-only mixed-state forensic checks passed (CI ONLY).\n";
