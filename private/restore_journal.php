<?php
declare(strict_types=1);

/**
 * Private, persistent restore journal and fail-closed PHP maintenance gate.
 *
 * A journal is published BEFORE the first destructive photo-directory rename.
 * A terminated worker leaves the journal present: every PHP website/admin
 * request returns HTTP 503 instead of reading potentially mixed DB and photos.
 *
 * Phase 3A deliberately does NOT guess whether an interrupted DB COMMIT
 * happened and does NOT automatically delete or replace production files.
 * Recovery requires independently verified, operator-approved procedures.
 */
function restore_journal_dir(): string {
    return __DIR__ . '/backups';
}
function restore_journal_path(): string {
    return restore_journal_dir() . '/.restore-active.json';
}
function restore_journal_pending(): bool {
    $path = restore_journal_path();
    if (is_file($path) || is_link($path)) return true;
    // If PHP died while building an atomic journal update, fail closed too.
    if (glob(restore_journal_dir() . '/.restore-active.tmp-*')) return true;
    // Defense in depth: an orphan prior-uploads directory indicates a restore
    // may have moved live photos even if a storage crash lost the journal entry.
    return (bool)(glob(dirname(__DIR__) . '/catalogus-public/.uploads-before-restore-*') ?: []);
}
function restore_journal_require_clear(): void {
    if (restore_journal_pending()) {
        throw new RuntimeException(
            'Er is een onderbroken of nog actieve herstelbewerking. ' .
            'Start geen nieuwe back-up of herstel voordat de toestand veilig is onderzocht.'
        );
    }
}
/**
 * Web requests never bypass this check, even when a DB lock was released by a
 * killed worker. CLI remains available for operator diagnostics/isolated CI.
 * Static images are served by the webserver, outside this PHP guard.
 */
function restore_journal_gate_web(): void {
    if (PHP_SAPI === 'cli' || !restore_journal_pending()) return;
    http_response_code(503);
    header('Retry-After: 60');
    header('Cache-Control: no-store, private');
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="nl"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Tijdelijk onderhoud · 41 Lokeren</title></head>';
    echo '<body><main style="max-width:42rem;margin:10vh auto;padding:2rem;';
    echo 'font:1rem/1.6 system-ui,sans-serif"><h1>De catalogus is tijdelijk niet beschikbaar</h1>';
    echo '<p>Er wordt een herstelbewerking gecontroleerd. Probeer later opnieuw.</p>';
    echo '<p>Beheerder: neem contact op met de technische verantwoordelijke.';
    echo ' Start geen nieuw herstel of foto-upload.</p></main></body></html>';
    exit;
}
/** Strictly validate the tiny diagnostic journal; never read paths from it. */
function restore_journal_read(): array {
    $path = restore_journal_path();
    if (!is_file($path) || is_link($path)) {
        throw new RuntimeException('Hersteljournal ontbreekt of is niet veilig leesbaar.');
    }
    $bytes = file_get_contents($path);
    if ($bytes === false || strlen($bytes) > 4096) {
        throw new RuntimeException('Hersteljournal is niet geldig.');
    }
    $data = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($data)
        || ($data['format'] ?? '') !== '41lokeren-restore-journal-v1'
        || !is_string($data['run_id'] ?? null)
        || !preg_match('/^[a-f0-9]{32}$/D', $data['run_id'])
        || !is_string($data['snapshot'] ?? null)
        || !preg_match('/^snapshot-20[0-9]{6}-[0-9]{6}-[a-f0-9]{12}\.zip$/D', $data['snapshot'])
        || !is_string($data['snapshot_sha256'] ?? null)
        || !preg_match('/^[a-f0-9]{64}$/D', $data['snapshot_sha256'])
        || !is_string($data['old_uploads'] ?? null)
        || !preg_match('/^\.uploads-before-restore-[a-f0-9]{16}$/D', $data['old_uploads'])
        || !is_string($data['stage_uploads'] ?? null)
        || !preg_match('/^\.restore-[a-f0-9]{16}$/D', $data['stage_uploads'])
        || !is_bool($data['uploads_existed'] ?? null)
        || !in_array($data['phase'] ?? null,
            ['prepared','old_saved','photos_active','database_committed'], true)) {
        throw new RuntimeException('Onjuist hersteljournal; uitsluitend handmatige analyse toegestaan.');
    }
    return $data;
}
/**
 * Atomic same-directory replace. File fsync is required when available;
 * PHP cannot guarantee directory-entry fsync on all shared hosting volumes.
 * Any leftover temp file also triggers maintenance until an operator reviews.
 */
function restore_journal_write(array $data, bool $initial = false): void {
    $path = restore_journal_path();
    if (!is_dir(restore_journal_dir()) || is_link(restore_journal_dir())) {
        throw new RuntimeException('Private herstelmap ontbreekt of is onveilig.');
    }
    if ($initial) {
        restore_journal_require_clear();
    } elseif (!is_file($path) || is_link($path)) {
        throw new RuntimeException('Bestaand hersteljournal ontbreekt tijdens faseovergang.');
    }
    $tmp = restore_journal_dir() . '/.restore-active.tmp-' . bin2hex(random_bytes(8));
    $stream = fopen($tmp, 'x+b');
    if ($stream === false) throw new RuntimeException('Kan tijdelijk hersteljournal niet aanmaken.');
    $renamed = false;
    try {
        if (!chmod($tmp, 0600)) throw new RuntimeException('Kan hersteljournal niet afschermen.');
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > 4096) throw new RuntimeException('Hersteljournal te groot.');
        $offset = 0;
        while ($offset < strlen($json)) {
            $written = fwrite($stream, substr($json, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Kan hersteljournal niet volledig schrijven.');
            }
            $offset += $written;
        }
        if (!fflush($stream) || (function_exists('fsync') && !fsync($stream))) {
            throw new RuntimeException('Kan hersteljournal niet naar schijf wegschrijven.');
        }
        if (!rename($tmp, $path)) {
            throw new RuntimeException('Kan hersteljournal niet atomair activeren.');
        }
        $renamed = true;
    } finally {
        fclose($stream);
        if (!$renamed && is_file($tmp)) @unlink($tmp);
    }
}
function restore_journal_begin(string $snapshot, string $old, string $stage, bool $exists): array {
    $path = __DIR__ . '/backups/' . $snapshot;
    $hash = is_file($path) ? hash_file('sha256', $path) : false;
    if ($hash === false) throw new RuntimeException('Kan snapshotchecksum niet bepalen.');
    $journal = [
        'format' => '41lokeren-restore-journal-v1',
        'run_id' => bin2hex(random_bytes(16)),
        'started_at' => gmdate('c'),
        'snapshot' => $snapshot,
        'snapshot_sha256' => $hash,
        'old_uploads' => basename($old),
        'stage_uploads' => basename($stage),
        'uploads_existed' => $exists,
        'phase' => 'prepared',
    ];
    restore_journal_write($journal, true);
    // Fail before touching live assets if metadata cannot be read back.
    if (restore_journal_read()['run_id'] !== $journal['run_id']) {
        throw new RuntimeException('Hersteljournal is niet geverifieerd.');
    }
    return $journal;
}
function restore_journal_phase(array &$journal, string $phase): void {
    if (!in_array($phase, ['old_saved','photos_active','database_committed'], true)) {
        throw new InvalidArgumentException('Onbekende herstelfase.');
    }
    $journal['phase'] = $phase;
    restore_journal_write($journal);
}
function restore_journal_finish(): void {
    // Never silently erase a corrupt journal or an incomplete temp update.
    restore_journal_read();
    if (glob(restore_journal_dir() . '/.restore-active.tmp-*')
        || glob(dirname(__DIR__) . '/catalogus-public/.uploads-before-restore-*')) {
        throw new RuntimeException('Onvoltooid hersteljournal of achtergebleven fotomap; handmatige controle vereist.');
    }
    if (!unlink(restore_journal_path())) {
        throw new RuntimeException('Kon hersteljournal niet veilig beëindigen.');
    }
}
