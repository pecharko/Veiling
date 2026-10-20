<?php
declare(strict_types=1);

/**
 * Phase 3D: operator-approved, photo-only rollback of an interrupted restore.
 *
 * Only when ALL five current MySQL tables match the separate, verified
 * BEFORE-restoration safety ZIP may the OLD photo tree be reactivated.
 * Neither this module nor its CLI writes SQL, deletes photos, replays a ZIP
 * restore, or guesses the outcome of an ambiguous COMMIT.
 *
 * Both the target photo tree and the pre-restore photo tree are always
 * preserved. A crash at either rename leaves the journal and maintenance
 * marker active, and a new read-only plan + approval is required to resume.
 *
 * Usage is CLI-only for a qualified human incident operator, NOT for the
 * public web UI. External SFTP/SQL writers must be excluded separately.
 */
require_once __DIR__ . '/restore_finalize.php';

const RECOVERY_PRECOMMIT_CONFIRM = 'IK KEUR HET TERUGPLAATSEN VAN DE OORSPRONKELIJKE FOTOS GOED';

function recovery_precommit_saved_target(array $journal): string {
    return restore_journal_dir() . '/.restore-preserved-target-' . $journal['run_id'];
}
function recovery_precommit_receipt(array $journal): string {
    return restore_journal_dir() . '/.restore-rolled-back-' . $journal['run_id'] . '.json';
}
function recovery_precommit_block(string $reason): array {
    return [
        'ready' => false,
        'status' => 'blocked_keep_maintenance',
        'reason' => $reason,
        'read_only' => true,
        'will_modify_data' => false,
        'maintenance_must_remain' => true,
        'requires_human_review' => true,
        'next_step' => 'KEEP_MAINTENANCE_AND_OBTAIN_EXPERT_REVIEW',
    ];
}

/**
 * An entirely non-mutating plan, including archives and filesystem. All ZIP
 * contents are independently hashed, and the MySQL snapshot view must match
 * the pre-restore safety archive rather than the requested target archive.
 */
function recovery_precommit_plan(PDO $pdo, string $safetyName, string $operator): array {
    recovery_finalize_operator($operator);
    if (!restore_journal_pending()) return recovery_precommit_block('no_restore_incident');
    if (glob(restore_journal_dir() . '/.restore-active.tmp-*') ||
        glob(restore_journal_dir() . '/.restore-finalizing-tmp-*')) {
        return recovery_precommit_block('unfinished_private_journal_or_receipt');
    }
    try {
        catalog_data_lock($pdo, 0);
    } catch (Throwable $error) {
        return recovery_precommit_block('catalog_lock_busy');
    }
    try {
        try {
            $journal = restore_journal_read();
            if ($journal['uploads_existed'] !== true ||
                !in_array($journal['phase'], ['old_saved', 'photos_active'], true)) {
                return recovery_precommit_block('restore_phase_not_eligible');
            }
            $targetName = $journal['snapshot'];
            $targetPath = snap_path($targetName);
            if (is_link($targetPath) || !is_file($targetPath)) {
                return recovery_precommit_block('target_snapshot_missing');
            }
            $targetHash = hash_file('sha256', $targetPath);
            if ($targetHash === false ||
                !hash_equals($journal['snapshot_sha256'], $targetHash)) {
                return recovery_precommit_block('target_snapshot_checksum_mismatch');
            }
            [$targetDb, $targetHashes] = snap_verified($targetName);
            recovery_verify_archive_photos($targetName, $targetHashes);
            $safety = recovery_finalize_safety($safetyName, $journal);
            $safetyPath = snap_path($safetyName);
            [$safetyDb, $safetyHashes] = snap_verified($safetyName);
            if ($safety['sha256'] === $journal['snapshot_sha256']) {
                return recovery_precommit_block('safety_and_target_are_identical');
            }
            // The pre-restore database must match in its ENTIRETY, not only
            // one representative row or journal phase.
            $dbOld = recovery_compare_database($pdo, $safetyDb);
            if (!$dbOld['matches_target']) {
                return recovery_precommit_block('database_differs_from_safety_zip');
            }
            $dbTarget = recovery_compare_database($pdo, $targetDb);
            if ($dbTarget['matches_target']) {
                return recovery_precommit_block('database_already_matches_target');
            }
            $parent = dirname(snap_uploads());
            $uploads = snap_uploads();
            $old = $parent . '/' . $journal['old_uploads'];
            $stage = $parent . '/' . $journal['stage_uploads'];
            $preserved = recovery_precommit_saved_target($journal);
            $receiptPath = recovery_precommit_receipt($journal);
            if (is_link($uploads) || is_link($old) || is_link($stage) ||
                is_link($preserved) || is_link($receiptPath) ||
                is_link(restore_journal_dir()) || file_exists($stage)) {
                return recovery_precommit_block('unsafe_or_unfinished_directory');
            }
            $oldDirs = glob($parent . '/.uploads-before-restore-*') ?: [];
            if (count($oldDirs) > 1 ||
                ($oldDirs && $oldDirs[0] !== $old)) {
                return recovery_precommit_block('unexpected_old_upload_directory');
            }
            $hasActive = is_dir($uploads);
            $hasOld = is_dir($old);
            $hasTargetPrivate = is_dir($preserved);
            if ((file_exists($uploads) && !$hasActive) ||
                (file_exists($old) && !$hasOld) ||
                (file_exists($preserved) && !$hasTargetPrivate)) {
                return recovery_precommit_block('unexpected_file_at_photo_directory');
            }
            // Three exact, enumerated states are supported. The two
            // intermediate states result from an intentional SIGKILL point.
            if ($hasActive && $hasOld && !$hasTargetPrivate) {
                $state = 'initial';
                $activePhotoManifest = $targetHashes;
            } elseif (!$hasActive && $hasOld && $hasTargetPrivate) {
                $state = 'target_privately_preserved';
                $activePhotoManifest = null;
            } elseif ($hasActive && !$hasOld && $hasTargetPrivate) {
                $state = 'original_reactivated';
                $activePhotoManifest = $safetyHashes;
            } else {
                return recovery_precommit_block('unrecognized_photo_restore_state');
            }
            $originalDir = $hasOld ? $old : $uploads;
            $originalCheck = recovery_compare_photos($originalDir, $safetyHashes);
            if (!$originalCheck['matches_target']) {
                return recovery_precommit_block('original_photos_do_not_match_safety_zip');
            }
            $targetDir = $hasTargetPrivate ? $preserved : $uploads;
            $targetCheck = recovery_compare_photos($targetDir, $targetHashes);
            if (!$targetCheck['matches_target']) {
                return recovery_precommit_block('target_photos_are_not_verifiable');
            }
            if ($activePhotoManifest !== null) {
                $activeCheck = recovery_compare_photos($uploads, $activePhotoManifest);
                if (!$activeCheck['matches_target']) {
                    return recovery_precommit_block('active_photos_unexpected');
                }
            }
            $privateStat = stat(restore_journal_dir());
            $parentStat = stat($parent);
            if ($privateStat === false || $parentStat === false ||
                $privateStat['dev'] !== $parentStat['dev']) {
                return recovery_precommit_block('private_evidence_not_same_filesystem');
            }
            $receiptExists = is_file($receiptPath);
            if (file_exists($receiptPath) && !$receiptExists) {
                return recovery_precommit_block('unsafe_existing_receipt');
            }
            if ($receiptExists) {
                $bytes = file_get_contents($receiptPath);
                if ($bytes === false || strlen($bytes) > 4096) {
                    return recovery_precommit_block('invalid_existing_receipt');
                }
                try {
                    $receipt = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
                } catch (Throwable $error) {
                    return recovery_precommit_block('invalid_existing_receipt');
                }
                if (!is_array($receipt) ||
                    ($receipt['format'] ?? null) !== '41lokeren-original-photo-rollback-v1' ||
                    ($receipt['run_id'] ?? null) !== $journal['run_id'] ||
                    ($receipt['operator'] ?? null) !== $operator ||
                    ($receipt['target_sha256'] ?? null) !== $journal['snapshot_sha256'] ||
                    ($receipt['safety_sha256'] ?? null) !== $safety['sha256'] ||
                    ($receipt['original_photos_restored'] ?? null) !== true ||
                    ($receipt['target_photos_privately_preserved'] ?? null) !== true ||
                    $state !== 'original_reactivated') {
                    return recovery_precommit_block('existing_receipt_mismatch');
                }
            }
            // Hash both archive files again AFTER reading their contents, to
            // catch archive modification while diagnostic hashing was running.
            $targetHashAfter = hash_file('sha256', $targetPath);
            $safetyHashAfter = hash_file('sha256', $safetyPath);
            if ($targetHashAfter === false || $safetyHashAfter === false ||
                !hash_equals($targetHash, $targetHashAfter) ||
                !hash_equals($safety['sha256'], $safetyHashAfter)) {
                return recovery_precommit_block('snapshot_changed_during_diagnosis');
            }
            $input = implode("\n", [
                '41lokeren-precommit-rollback-v1',
                $journal['run_id'], $journal['phase'],
                $journal['snapshot'], $journal['snapshot_sha256'],
                $safetyName, $safety['sha256'], $operator, $state,
            ]);
            return [
                'ready' => true,
                'status' => 'original_database_and_photos_verified',
                'read_only' => true,
                'will_modify_data' => false,
                'requires_human_review' => true,
                'maintenance_must_remain' => true,
                'run_id' => $journal['run_id'],
                'target_snapshot' => $targetName,
                'target_sha256' => $targetHash,
                'safety_snapshot' => $safetyName,
                'safety_sha256' => $safety['sha256'],
                'database_matches_original_safety' => true,
                'database_matches_target' => false,
                'original_photos_match_safety' => true,
                'target_photos_verified' => true,
                'photo_state' => $state,
                'target_photos_privately_preserved' => $hasTargetPrivate,
                'operator' => $operator,
                'approval_challenge' => hash('sha256', $input),
                'next_step' => 'OBTAIN_SEPARATE_EXPLICIT_APPROVAL',
            ];
        } catch (Throwable $error) {
            return recovery_precommit_block('archive_database_or_photo_verification_failed');
        }
    } finally {
        catalog_data_unlock($pdo);
    }
}

/**
 * ONLY for an independently approved operator after reviewing the read-only
 * plan and a separately saved external safety ZIP. The approval is explicit
 * and bound to a particular stage, ZIP pair, run ID and operator.
 *
 * A successful call never changes the database or deletes any photo bytes.
 */
function recovery_precommit_approved(
    PDO $pdo, string $safetyName, string $operator,
    string $externalCopySha256, string $runId,
    string $challenge, string $confirmation
): array {
    if (PHP_SAPI !== 'cli' || $confirmation !== RECOVERY_PRECOMMIT_CONFIRM ||
        !preg_match('/^[a-f0-9]{32}$/D', $runId) ||
        !preg_match('/^[a-f0-9]{64}$/D', $challenge) ||
        !preg_match('/^[a-f0-9]{64}$/D', $externalCopySha256)) {
        throw new RuntimeException('Expliciete herstelautorisatie ontbreekt of is ongeldig.');
    }
    snap_init();
    $fileLock = snap_lock();
    $catalogLocked = false;
    try {
        catalog_data_lock($pdo, 0);
        $catalogLocked = true;
        $plan = recovery_precommit_plan($pdo, $safetyName, $operator);
        if (($plan['ready'] ?? false) !== true ||
            !hash_equals($plan['run_id'], $runId) ||
            !hash_equals($plan['approval_challenge'], $challenge) ||
            !hash_equals($plan['safety_sha256'], $externalCopySha256)) {
            throw new RuntimeException('Plan of externe veiligheidskopie gewijzigd. Nieuwe beoordeling vereist.');
        }
        $journal = restore_journal_read();
        $uploads = snap_uploads();
        $old = dirname($uploads) . '/' . $journal['old_uploads'];
        $savedTarget = recovery_precommit_saved_target($journal);
        $state = $plan['photo_state'];

        if ($state === 'initial') {
            // First atomic rename: archive TARGET photos privately, without
            // deleting anything. If the process dies here, PHP stays HTTP 503.
            if (file_exists($savedTarget) || is_link($savedTarget) ||
                !is_dir($uploads) || is_link($uploads)) {
                throw new RuntimeException('Onveilige fotomappositie vóór behoud van doelarchief.');
            }
            if (!rename($uploads, $savedTarget)) {
                throw new RuntimeException('Kon doelfoto’s niet privé bewaren.');
            }
            $state = 'target_privately_preserved';
        }
        if ($state === 'target_privately_preserved') {
            // Before reactivating the original photo tree, rehash BOTH sides.
            $verified = recovery_precommit_plan($pdo, $safetyName, $operator);
            if (($verified['ready'] ?? false) !== true ||
                $verified['photo_state'] !== 'target_privately_preserved') {
                throw new RuntimeException('Fotobewijs gewijzigd tijdens herstel. Onderhoud blijft actief.');
            }
            if (is_dir($uploads) || is_link($uploads) ||
                !is_dir($old) || is_link($old)) {
                throw new RuntimeException('Originele fotomap kan niet veilig worden geactiveerd.');
            }
            if (!rename($old, $uploads)) {
                throw new RuntimeException('Kon originele foto’s niet activeren.');
            }
            $state = 'original_reactivated';
        }

        // Recheck the entire database, both ZIPs, original active photos and
        // preserved target photos AFTER BOTH renames.
        $verified = recovery_precommit_plan($pdo, $safetyName, $operator);
        if (($verified['ready'] ?? false) !== true ||
            $verified['photo_state'] !== 'original_reactivated') {
            throw new RuntimeException('Database of fotointegriteit onzeker. Onderhoud blijft actief.');
        }
        $receiptPath = recovery_precommit_receipt($journal);
        if (!is_file($receiptPath) && !is_link($receiptPath)) {
            recovery_finalize_write_receipt($receiptPath, [
                'format' => '41lokeren-original-photo-rollback-v1',
                'run_id' => $journal['run_id'],
                'target_sha256' => $plan['target_sha256'],
                'safety_sha256' => $plan['safety_sha256'],
                'operator' => $operator,
                'approved_at' => gmdate('c'),
                'original_photos_restored' => true,
                'target_photos_privately_preserved' => true,
                'database_changes_made' => false,
            ]);
        }
        // The journal is cleared only AFTER both trees and the database have
        // been checked and the private audit receipt is durably published.
        restore_journal_finish();
        return [
            'status' => 'original_state_reactivated_after_approval',
            'run_id' => $runId,
            'database_changes_made' => false,
            'photos_deleted' => false,
            'target_photos_privately_preserved' => true,
            'safety_snapshot_preserved' => true,
            'approval_receipt_preserved' => true,
            'next_step' => 'VERIFY_SITE_READ_ONLY_AFTER_APPROVED_INCIDENT',
        ];
    } finally {
        try {
            if ($catalogLocked) catalog_data_unlock($pdo);
        } finally {
            flock($fileLock, LOCK_UN);
            fclose($fileLock);
        }
    }
}
