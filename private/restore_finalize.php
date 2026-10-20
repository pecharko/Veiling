<?php
declare(strict_types=1);

/**
 * Restore issue #2, phase 3C: human-approved FINALIZATION ONLY.
 *
 * This code will never replay SQL, restore a snapshot, delete a photo or
 * replace active uploads. It may move the SAVED PRE-RESTORE photo directory
 * into private recovery evidence, atomically on the same filesystem, and
 * remove the maintenance journal only after full target-state verification.
 *
 * A mixed/unknown recovery state MUST remain in maintenance and requires a
 * separate professionally reviewed recovery procedure.
 *
 * SECURITY: CLI-only; authorized operator must first inspect a separate plan
 * and obtain independent approval before invoking finalize.
 */
require_once __DIR__ . '/restore_diagnosis.php';

const RECOVERY_FINALIZE_CONFIRM = 'IK KEUR HET AFRONDEN VAN DIT HERSTEL GOED';

function recovery_finalize_operator(string $operator): string {
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{2,63}$/D', $operator)) {
        throw new InvalidArgumentException('Ongeldige operatorcode.');
    }
    return $operator;
}
function recovery_finalize_evidence_path(array $journal): string {
    return restore_journal_dir() . '/.restore-preserved-old-' . $journal['run_id'];
}
function recovery_finalize_receipt_path(array $journal): string {
    return restore_journal_dir() . '/.restore-finalized-' . $journal['run_id'] . '.json';
}

/** Only small, private and atomically published approval receipts. */
function recovery_finalize_write_receipt(string $destination, array $data): void {
    if (is_link($destination) || file_exists($destination)) {
        throw new RuntimeException('Bevestigingsbewijs bestaat reeds; handmatige controle vereist.');
    }
    $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    if (strlen($json) > 4096) {
        throw new RuntimeException('Bevestigingsbewijs te groot.');
    }
    $tmp = restore_journal_dir() . '/.restore-finalizing-tmp-' . bin2hex(random_bytes(8));
    $file = fopen($tmp, 'x+b');
    if ($file === false) throw new RuntimeException('Kan bevestigingsbewijs niet aanmaken.');
    $activated = false;
    try {
        if (!chmod($tmp, 0600)) throw new RuntimeException('Kan bewijsbestand niet afschermen.');
        $offset = 0;
        while ($offset < strlen($json)) {
            $count = fwrite($file, substr($json, $offset));
            if ($count === false || $count <= 0)
                throw new RuntimeException('Schrijven van herstelbewijs mislukt.');
            $offset += $count;
        }
        if (!fflush($file) || (function_exists('fsync') && !fsync($file))) {
            throw new RuntimeException('Herstelbewijs kon niet naar schijf.');
        }
        if (!rename($tmp, $destination)) {
            throw new RuntimeException('Kon herstelbewijs niet activeren.');
        }
        $activated = true;
    } finally {
        fclose($file);
        if (!$activated && is_file($tmp)) @unlink($tmp);
    }
}

/**
 * Compare a saved pre-restore photo tree with an explicitly named safety ZIP.
 * Safety ZIP is separately created by the admin restore flow BEFORE it swaps
 * the DB+photos, and must be checked as a separate backup by the operator.
 */
function recovery_finalize_safety(string $name, array $journal): array {
    if (!snap_name($name) || $name === $journal['snapshot']) {
        throw new RuntimeException('Selecteer de afzonderlijke veiligheidskopie van vóór het herstel.');
    }
    $path = snap_path($name);
    if (!is_file($path) || is_link($path)) {
        throw new RuntimeException('Gekozen veiligheidssnapshot ontbreekt of is onveilig.');
    }
    [$database, $hashes] = snap_verified($name);
    // A manifest alone cannot prove the archived photo bytes actually match.
    recovery_verify_archive_photos($name, $hashes);
    $sha = hash_file('sha256', $path);
    if ($sha === false) throw new RuntimeException('Kan checksum veiligheidskopie niet lezen.');
    return ['name'=>$name, 'sha256'=>$sha, 'hashes'=>$hashes,
        'photo_count'=>count($hashes)-1];
}

/** Return NO MUTATION; the plan must be accepted in a separate CLI call. */
function recovery_finalize_plan(PDO $pdo, string $safetyName, string $operator): array {
    recovery_finalize_operator($operator);
    $blocked = static fn(string $reason):array=>[
        'read_only'=>true, 'ready'=>false, 'requires_human_review'=>true,
        'will_modify_data'=>false, 'reason'=>$reason,
        'next_step'=>'KEEP_MAINTENANCE_AND_OBTAIN_HUMAN_REVIEW',
    ];
    if (!restore_journal_pending()) return $blocked('no_pending_recovery');
    if (glob(restore_journal_dir().'/.restore-finalizing-tmp-*')) {
        return $blocked('unfinished_approval_receipt');
    }
    try {
        $journal = restore_journal_read();
    } catch (Throwable $error) {
        return $blocked('missing_or_invalid_journal');
    }
    if (!in_array($journal['phase'], ['photos_active','database_committed'], true)) {
        return $blocked('restore_phase_not_ready_for_finalization');
    }
    // Full independent target verification; never trust journal phase as proof.
    $diagnosis = restore_recovery_diagnose($pdo);
    if ($diagnosis['status'] !== 'target_verified_pending_review' ||
        $diagnosis['maintenance_must_remain'] !== true ||
        $diagnosis['database_matches_target'] !== true ||
        $diagnosis['active_photos_match_target'] !== true ||
        $diagnosis['archive_photo_hashes_verified'] !== true) {
        return $blocked('target_not_independently_verified');
    }
    try {
        $safety = recovery_finalize_safety($safetyName, $journal);
    } catch (Throwable $error) {
        return $blocked('safety_snapshot_not_verified');
    }
    $parent = dirname(snap_uploads());
    $oldPath = $parent . '/' . $journal['old_uploads'];
    $stagePath = $parent . '/' . $journal['stage_uploads'];
    $savedPath = recovery_finalize_evidence_path($journal);
    if (is_link($oldPath) || is_link($stagePath) || is_link($savedPath)
        || is_link(restore_journal_dir())
        || is_dir($stagePath) || is_file($stagePath)) {
        return $blocked('unsafe_or_unfinished_photo_staging');
    }
    if (is_dir($oldPath) && is_dir($savedPath)) {
        return $blocked('duplicated_prior_uploads_evidence');
    }
    if (file_exists($savedPath) && !is_dir($savedPath)) {
        return $blocked('invalid_private_evidence_path');
    }
    $preservation = 'safety_archive_only';
    if (is_dir($oldPath)) {
        $checked = recovery_compare_photos($oldPath, $safety['hashes']);
        if (!$checked['matches_target'])return $blocked('saved_photos_mismatch_safety_zip');
        $preservation = 'move_previous_uploads_to_private';
        $a = stat($oldPath);$b = stat(restore_journal_dir());
        if ($a===false || $b===false || $a['dev'] !== $b['dev']) {
            return $blocked('cannot_atomically_preserve_on_same_filesystem');
        }
    } elseif (is_dir($savedPath)) {
        $checked = recovery_compare_photos($savedPath, $safety['hashes']);
        if (!$checked['matches_target'])return $blocked('private_evidence_mismatch_safety_zip');
        $preservation = 'old_photos_already_privately_preserved';
    } elseif ($journal['uploads_existed'] && $safety['photo_count'] > 0) {
        // An old upload directory vanished unexpectedly. Even with a safety
        // ZIP, require separate incident analysis instead of silent approval.
        return $blocked('prior_photo_directory_missing');
    } elseif ($journal['uploads_existed']) {
        // Empty previous photo set: the safety archive still provides evidence.
        $preservation = 'empty_pre_restore_photos_safety_zip';
    }
    $receiptPath = recovery_finalize_receipt_path($journal);
    if (is_link($receiptPath)) return $blocked('unsafe_existing_approval_receipt');
    if (is_file($receiptPath)) {
        $bytes = file_get_contents($receiptPath);
        if ($bytes === false || strlen($bytes) > 4096)
            return $blocked('invalid_existing_approval_receipt');
        try{$receipt = json_decode($bytes,true,32,JSON_THROW_ON_ERROR);}
        catch(Throwable $error){return $blocked('invalid_existing_approval_receipt');}
        if (!is_array($receipt) ||
            ($receipt['format']??null)!=='41lokeren-restore-finalized-v1' ||
            ($receipt['run_id']??null)!==$journal['run_id'] ||
            ($receipt['target_sha256']??null)!==$journal['snapshot_sha256'] ||
            ($receipt['safety_sha256']??null)!==$safety['sha256'] ||
            ($receipt['operator']??null)!==$operator ||
            ($receipt['read_only_diagnosis_confirmed']??null)!==true ||
            ($receipt['old_photos_privately_preserved']??null)!==is_dir($savedPath) ||
            is_dir($oldPath)) {
            return $blocked('existing_receipt_mismatch');
        }
        if (!is_dir($savedPath) && $journal['uploads_existed']
            && $safety['photo_count']>0) {
            return $blocked('existing_receipt_but_evidence_missing');
        }
    }
    $key = implode("\n",[
        '41lokeren-approved-finalize-v1',
        $journal['run_id'], $journal['snapshot'],
        $journal['snapshot_sha256'], $journal['phase'],
        $safety['name'], $safety['sha256'], $operator, $preservation,
    ]);
    return [
        'read_only'=>true, 'ready'=>true,
        'requires_human_review'=>true,
        'will_modify_data'=>false,
        'status'=>'verified_target_requires_explicit_approval',
        'run_id'=>$journal['run_id'],
        'target_snapshot'=>$journal['snapshot'],
        'target_sha256'=>$journal['snapshot_sha256'],
        'safety_snapshot'=>$safety['name'],
        'safety_sha256'=>$safety['sha256'],
        'safety_photo_count'=>$safety['photo_count'],
        'preservation_plan'=>$preservation,
        'operator'=>$operator,
        'approval_challenge'=>hash('sha256', $key),
        'maintenance_must_remain'=>true,
        'next_step'=>'OBTAIN_SECOND_EXPLICIT_HUMAN_APPROVAL',
    ];
}

/**
 * Called only by an explicitly invoked authenticated system operator from
 * CLI. Target DB+photos must already be fully restored, byte-for-byte.
 *
 * No DB content changes; no active photo changes; no photo deletion. The old
 * photo directory is moved to private backup evidence, never discarded.
 */
function recovery_finalize_approved(
    PDO $pdo, string $safetyName, string $operator,
    string $externalCopySha256, string $runId, string $challenge,
    string $confirmation
): array {
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('Uitsluitend via gecontroleerde PHP CLI toegestaan.');
    }
    recovery_finalize_operator($operator);
    if ($confirmation !== RECOVERY_FINALIZE_CONFIRM ||
        !preg_match('/^[a-f0-9]{32}$/D', $runId) ||
        !preg_match('/^[a-f0-9]{64}$/D', $challenge)) {
        throw new RuntimeException('Expliciete hersteltoestemming ontbreekt of is ongeldig.');
    }
    snap_init();
    // Match normal snapshot lock order: file lock THEN catalog-wide SQL lock.
    $fileLock = snap_lock();
    $catalogLocked = false;
    try {
        catalog_data_lock($pdo, 0);$catalogLocked=true;
        $plan = recovery_finalize_plan($pdo, $safetyName, $operator);
        if (!$plan['ready'] ||
            !preg_match('/^[a-f0-9]{64}$/D', $externalCopySha256) ||
            !hash_equals($externalCopySha256, $plan['safety_sha256']) ||
            !hash_equals($runId, $plan['run_id']) ||
            !hash_equals($challenge, $plan['approval_challenge'])) {
            throw new RuntimeException('Diagnose of goedkeuring is gewijzigd. Maak een nieuw plan en herbeoordeel.');
        }
        $journal = restore_journal_read();
        $oldPath = dirname(snap_uploads()) . '/' . $journal['old_uploads'];
        $savedPath = recovery_finalize_evidence_path($journal);
        if ($plan['preservation_plan']==='move_previous_uploads_to_private') {
            if (file_exists($savedPath) || is_link($savedPath) ||
                !is_dir($oldPath) || is_link($oldPath)) {
                throw new RuntimeException('Onveilige oude fotomap; geen herstelactie uitgevoerd.');
            }
            // Both dirs on SAME filesystem were verified at plan time.
            $a = stat($oldPath);$b=stat(restore_journal_dir());
            if ($a===false||$b===false||$a['dev']!==$b['dev']) {
                throw new RuntimeException('Atomic move kan niet worden gegarandeerd.');
            }
            if (!rename($oldPath, $savedPath)) {
                throw new RuntimeException('Kon oude foto’s niet privé bewaren.');
            }
            // A crash here keeps the restore journal and maintenance ACTIVE.
        }
        // Check private evidence is complete, including its photo hashes.
        if (is_dir($savedPath)) {
            $safety = recovery_finalize_safety($safetyName,$journal);
            $matches = recovery_compare_photos($savedPath,$safety['hashes']);
            if (!$matches['matches_target']) {
                throw new RuntimeException('Veiligheidskopie en privéfotobewijs komen niet overeen.');
            }
        }
        // Re-check the target state AFTER preserving the old photo evidence.
        $diagnosis = restore_recovery_diagnose($pdo);
        if ($diagnosis['status'] !== 'target_verified_pending_review' ||
            !$diagnosis['database_matches_target'] ||
            !$diagnosis['active_photos_match_target']) {
            throw new RuntimeException('Doelgegevens gewijzigd; onderhoud blijft actief.');
        }
        $receiptPath = recovery_finalize_receipt_path($journal);
        if (!file_exists($receiptPath) && !is_link($receiptPath)) {
            recovery_finalize_write_receipt($receiptPath,[
                'format'=>'41lokeren-restore-finalized-v1',
                'run_id'=>$journal['run_id'],
                'target_sha256'=>$journal['snapshot_sha256'],
                'safety_sha256'=>$plan['safety_sha256'],
                'operator'=>$operator,
                'approved_at'=>gmdate('c'),
                'read_only_diagnosis_confirmed'=>true,
                'old_photos_privately_preserved'=>is_dir($savedPath),
            ]);
        }
        // No prior uploads remain exposed outside the private backups folder.
        // Journal clear is LAST; crash before here keeps the PHP website closed.
        restore_journal_finish();
        return [
            'status'=>'finalization_completed',
            'run_id'=>$runId,
            'database_or_active_photos_modified'=>false,
            'old_photos_privately_preserved'=>is_dir($savedPath),
            'safety_snapshot_preserved'=>true,
            'approval_receipt_preserved'=>true,
            'next_step'=>'VERIFY_SITE_WITHOUT_DESTRUCTIVE_RESTORE',
        ];
    } finally {
        try{if($catalogLocked)catalog_data_unlock($pdo);}
        finally{flock($fileLock, LOCK_UN);fclose($fileLock);}
    }
}
