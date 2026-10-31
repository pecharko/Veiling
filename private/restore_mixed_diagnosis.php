<?php
declare(strict_types=1);

/**
 * Phase 3E: read-only forensic TRIAGE for an interrupted snapshot restore.
 *
 * Compares ONE consistent MySQL read view against BOTH the original safety
 * snapshot and the intended target snapshot. Rechecks the database after
 * hashing the photos to detect outside-app writes during the investigation.
 *
 * Nothing in this file changes MySQL rows, moves/deletes photos, updates the
 * private restore journal or can unlock the PHP maintenance gate.
 *
 * A mixed or unverifiable state must be examined on a secured OFFLINE CLONE
 * by a qualified operator, not "fixed" by combining arbitrary SQL rows.
 */
require_once __DIR__ . '/restore_diagnosis.php';

function recovery_mixed_block(string $reason): array {
    return [
        'read_only' => true,
        'will_modify_data' => false,
        'maintenance_must_remain' => true,
        'operator_review_required' => true,
        'status' => 'blocked_unverified',
        'reason' => $reason,
        'next_step' => 'PRESERVE_ALL_EVIDENCE_AND_REVIEW_OFFLINE',
    ];
}

/**
 * Each table is compared against both baseline datasets in the SAME
 * REPEATABLE READ transaction, without exposing private row contents,
 * account email addresses, passwords, lot descriptions or IP hashes.
 *
 * The second reading (later in the diagnosis) is a separate consistent
 * read view; a difference proves the evidence changed during inspection.
 */
function recovery_mixed_db_view(PDO $pdo, array $safety, array $target): array {
    if ($pdo->inTransaction()) {
        throw new RuntimeException('Incidentdiagnose vereist een nieuwe leestansactie.');
    }
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->beginTransaction();
    try {
        $labels = [];
        $fingerprints = [];
        $counts = [];
        foreach (SNAP_TABLES as $table) {
            $rows = $pdo->query('SELECT * FROM ' . chr(96) . $table . chr(96))
                ->fetchAll(PDO::FETCH_ASSOC);
            $current = recovery_table_digest($rows);
            $fromSafety = recovery_table_digest($safety['tables'][$table]);
            $fromTarget = recovery_table_digest($target['tables'][$table]);
            $safetyMatch = hash_equals($fromSafety, $current);
            $targetMatch = hash_equals($fromTarget, $current);
            $labels[$table] = $safetyMatch && $targetMatch ? 'both' :
                ($safetyMatch ? 'safety_only' :
                ($targetMatch ? 'target_only' : 'neither'));
            $fingerprints[$table] = $current;
            $counts[$table] = count($rows);
        }
        $analytics=visitor_geo_snapshot_export($pdo);
        if($analytics!==null || isset($safety['analytics']) || isset($target['analytics'])) {
            $current=visitor_geo_snapshot_digest($analytics);
            $safetyMatch=hash_equals($current,visitor_geo_snapshot_digest($safety['analytics']??null));
            $targetMatch=hash_equals($current,visitor_geo_snapshot_digest($target['analytics']??null));
            $labels['analytics']=$safetyMatch&&$targetMatch?'both':($safetyMatch?'safety_only':($targetMatch?'target_only':'neither'));
            $fingerprints['analytics']=$current;
            $counts['analytics']=$analytics===null?0:array_sum(array_map('count',$analytics['tables']));
        }
        if (!$pdo->commit()) {
            throw new RuntimeException('Kan consistente leestranactie niet afsluiten.');
        }
        return [
            'table_states' => $labels,
            'table_counts' => $counts,
            'fingerprints_internal' => $fingerprints,
            'all_safety' => !in_array('neither', $labels, true)
                && !in_array('target_only', $labels, true),
            'all_target' => !in_array('neither', $labels, true)
                && !in_array('safety_only', $labels, true),
        ];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

/** Per-directory classification returns only known labels, never file paths. */
function recovery_mixed_photo_sets(
    array $locations, array $safetyHashes, array $targetHashes
): array {
    $answer = [];
    foreach ($locations as $label => $path) {
        if (is_link($path)) {
            throw new RuntimeException('Symlink in evidence directory.');
        }
        if (file_exists($path) && !is_dir($path)) {
            throw new RuntimeException('Unexpected file at evidence directory.');
        }
        if (!is_dir($path)) {
            $answer[$label] = [
                'present' => false,
                'matches_safety' => false,
                'matches_target' => false,
            ];
            continue;
        }
        $safety = recovery_compare_photos($path, $safetyHashes);
        $target = recovery_compare_photos($path, $targetHashes);
        $answer[$label] = [
            'present' => true,
            'matches_safety' => $safety['matches_target'] === true,
            'matches_target' => $target['matches_target'] === true,
        ];
    }
    return $answer;
}

/**
 * Independently hash EVERY physical evidence file, even when its content
 * matches NEITHER archive. Comparing only booleans like matches_target=false
 * would otherwise miss one unexpected photo changing into another.
 *
 * Full paths and these internal fingerprints are NEVER returned to CLI.
 */
function recovery_mixed_tree_fingerprint(string $root): ?string {
    if (is_link($root)) throw new RuntimeException('Symlink in incident evidence.');
    if (!is_dir($root)) {
        if (file_exists($root)) throw new RuntimeException('Unexpected evidence file.');
        return null;
    }
    $entries = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $file) {
        if ($file->isLink()) throw new RuntimeException('Symlink inside photo evidence.');
        if ($file->isDir()) continue;
        if (!$file->isFile()) throw new RuntimeException('Unexpected photo evidence node.');
        $relative = str_replace(chr(92), '/',
            substr($file->getPathname(), strlen($root) + 1));
        if (array_key_exists($relative, $entries)) {
            throw new RuntimeException('Duplicate evidence file.');
        }
        $sha = hash_file('sha256', $file->getPathname());
        if ($sha === false) throw new RuntimeException('Unreadable photo evidence.');
        $entries[$relative] = $sha;
        if (count($entries) > 20000) {
            throw new RuntimeException('Too many photo evidence files.');
        }
    }
    ksort($entries, SORT_STRING);
    return hash('sha256', json_encode($entries, JSON_THROW_ON_ERROR));
}

/**
 * Read-only forensic plan. Requires valid original-safety ZIP. The journal
 * phase is informational only; it is NEVER sufficient evidence of DB commit.
 *
 * A returned 'verified_*' status is NOT an authorization to delete evidence,
 * restore a database or clear maintenance; only specific, separately reviewed
 * recovery tools can later request such authorization.
 */
function restore_mixed_diagnose(PDO $pdo, string $safetyName): array {
    if (!snap_name($safetyName)) return recovery_mixed_block('invalid_safety_snapshot_name');
    if (!restore_journal_pending()) return recovery_mixed_block('no_pending_restore');
    try {
        catalog_data_lock($pdo, 0);
    } catch (Throwable $error) {
        return recovery_mixed_block('catalog_mutex_busy');
    }
    try {
        $temp = glob(restore_journal_dir() . '/.restore-active.tmp-*') ?: [];
        $receipts = glob(restore_journal_dir() . '/.restore-finalizing-tmp-*') ?: [];
        if ($temp || $receipts) {
            return recovery_mixed_block('incomplete_journal_or_receipt');
        }
        try {
            $journal = restore_journal_read();
        } catch (Throwable $error) {
            return recovery_mixed_block('journal_missing_or_invalid');
        }
        $targetName = $journal['snapshot'];
        if ($targetName === $safetyName) {
            return recovery_mixed_block('safety_must_be_distinct_from_target');
        }
        $targetPath = snap_path($targetName);
        $safetyPath = snap_path($safetyName);
        $journalPath = restore_journal_path();
        if (is_link($targetPath) || is_link($safetyPath) ||
            is_link($journalPath) || is_link(restore_journal_dir()) ||
            !is_file($targetPath) || !is_file($safetyPath)) {
            return recovery_mixed_block('required_private_evidence_missing_or_unsafe');
        }
        $initialJournalHash = hash_file('sha256', $journalPath);
        $targetHash = hash_file('sha256', $targetPath);
        $safetyHash = hash_file('sha256', $safetyPath);
        if ($initialJournalHash === false || $targetHash === false ||
            $safetyHash === false ||
            !hash_equals($journal['snapshot_sha256'], $targetHash)) {
            return recovery_mixed_block('target_or_journal_checksum_invalid');
        }

        $parent = dirname(snap_uploads());
        $old = $parent . '/' . $journal['old_uploads'];
        $stage = $parent . '/' . $journal['stage_uploads'];
        $preservedTarget = restore_journal_dir() . '/.restore-preserved-target-' .
            $journal['run_id'];
        $preservedOld = restore_journal_dir() . '/.restore-preserved-old-' .
            $journal['run_id'];
        $oldCandidates = glob($parent . '/.uploads-before-restore-*') ?: [];
        if (count($oldCandidates) > 1 ||
            ($oldCandidates && $oldCandidates[0] !== $old) ||
            is_link($stage) || file_exists($stage)) {
            return recovery_mixed_block('unexpected_old_or_staging_directory');
        }

        try {
            [$target, $targetHashes] = snap_verified($targetName);
            [$safety, $safetyHashes] = snap_verified($safetyName);
            $targetPhotoCount = recovery_verify_archive_photos(
                $targetName, $targetHashes
            );
            $safetyPhotoCount = recovery_verify_archive_photos(
                $safetyName, $safetyHashes
            );
            $initialDb = recovery_mixed_db_view($pdo, $safety, $target);
            $locations = [
                'active' => snap_uploads(),
                'original_previous' => $old,
                'privately_preserved_target' => $preservedTarget,
                'privately_preserved_original' => $preservedOld,
            ];
            $photoFingerprintsBefore = [];
            foreach ($locations as $label => $location) {
                $photoFingerprintsBefore[$label] =
                    recovery_mixed_tree_fingerprint($location);
            }
            $photos = recovery_mixed_photo_sets(
                $locations, $safetyHashes, $targetHashes
            );
            $secondDb = recovery_mixed_db_view($pdo, $safety, $target);
            $photoFingerprintsAfter = [];
            foreach ($locations as $label => $location) {
                $photoFingerprintsAfter[$label] =
                    recovery_mixed_tree_fingerprint($location);
            }
        } catch (Throwable $error) {
            return recovery_mixed_block('database_zip_or_photo_verification_failed');
        }
        if ($initialDb['fingerprints_internal'] !== $secondDb['fingerprints_internal']) {
            return recovery_mixed_block('database_changed_during_review');
        }
        if ($photoFingerprintsBefore !== $photoFingerprintsAfter) {
            return recovery_mixed_block('photo_evidence_changed_during_review');
        }
        if (!hash_equals($initialJournalHash, (string)hash_file('sha256', $journalPath)) ||
            !hash_equals($targetHash, (string)hash_file('sha256', $targetPath)) ||
            !hash_equals($safetyHash, (string)hash_file('sha256', $safetyPath))) {
            return recovery_mixed_block('evidence_changed_during_review');
        }
        // Also independently reclassify active photos against each complete
        // ZIP manifest after the second DB read. The full-tree fingerprint
        // above detects changes even when both classifications stay false.
        $active = recovery_mixed_photo_sets(
            ['active' => snap_uploads()], $safetyHashes, $targetHashes
        )['active'];
        if ($photos['active'] !== $active) {
            return recovery_mixed_block('active_photo_set_changed_during_review');
        }
        if ($initialDb['all_safety'] && $active['matches_safety']) {
            $classification = 'safety_state_matches_but_keep_maintenance';
        } elseif ($initialDb['all_target'] && $active['matches_target']) {
            $classification = 'target_state_matches_but_keep_maintenance';
        } elseif (!$initialDb['all_safety'] && !$initialDb['all_target']) {
            $classification = 'database_not_equal_to_either_archive';
        } else {
            $classification = 'database_and_photo_state_differ';
        }
        return [
            'status' => $classification,
            'read_only' => true,
            'will_modify_data' => false,
            'maintenance_must_remain' => true,
            'operator_review_required' => true,
            'journal_phase_is_not_commit_proof' => true,
            'journal_phase' => $journal['phase'],
            'target_snapshot_sha256_verified' => true,
            'safety_snapshot_sha256' => $safetyHash,
            'offsite_backup_independently_verified' => false,
            'zip_photos_hash_verified' => true,
            'target_zip_photo_count' => $targetPhotoCount,
            'safety_zip_photo_count' => $safetyPhotoCount,
            'database_matches_safety' => $initialDb['all_safety'],
            'database_matches_target' => $initialDb['all_target'],
            'database_table_states' => $initialDb['table_states'],
            'database_table_row_counts' => $initialDb['table_counts'],
            'photo_set_states' => $photos,
            'next_step' => 'KEEP_MAINTENANCE_AND_REQUIRE_SEPARATE_INCIDENT_APPROVAL',
        ];
    } finally {
        catalog_data_unlock($pdo);
    }
}
