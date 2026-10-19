<?php
declare(strict_types=1);

/**
 * Application-wide MySQL advisory mutex for the auction's mutable DB and photos.
 *
 * A MySQL named lock works across PHP workers/hosting nodes connected to the
 * same database (unlike an NFS-dependent flock). EVERY application write path
 * that touches snapshot tables/uploads must participate. It is NOT a durable
 * recovery journal and it cannot block direct phpMyAdmin/SFTP modifications.
 *
 * MySQL GET_LOCK is connection-scoped, not transaction-scoped, and is
 * re-entrant on a single connection. Always balance acquire/release calls.
 */
const CATALOG_DATA_LOCK_NAME = '41lokeren_catalog_data_v1';

function catalog_data_lock(PDO $pdo, int $waitSeconds = 20): void {
    if ($waitSeconds < 0 || $waitSeconds > 60) {
        throw new InvalidArgumentException('Ongeldige wachttijd voor catalogusvergrendeling.');
    }
    $st = $pdo->prepare('SELECT GET_LOCK(?, ?)');
    $st->execute([CATALOG_DATA_LOCK_NAME, $waitSeconds]);
    if ((int)$st->fetchColumn() !== 1) {
        throw new RuntimeException('De catalogus is momenteel bezig met een andere bewerking. Probeer het straks opnieuw.');
    }
}

function catalog_data_unlock(PDO $pdo): void {
    $st = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    $st->execute([CATALOG_DATA_LOCK_NAME]);
    if ((int)$st->fetchColumn() !== 1) {
        throw new RuntimeException('De catalogusvergrendeling kon niet worden vrijgegeven.');
    }
}

/** For POST handlers that may redirect/exit: release the mutex on PHP shutdown. */
function catalog_lock_for_request(PDO $pdo): void {
    try {
        catalog_data_lock($pdo);
    } catch (Throwable $e) {
        error_log('[41Lokeren catalog lock] ' . $e->getMessage());
        http_response_code(503);
        header('Retry-After: 10');
        exit('De catalogus is tijdelijk bezig met een back-up of een andere bewerking. Vernieuw de pagina en probeer opnieuw.');
    }
    register_shutdown_function(static function () use ($pdo): void {
        try {
            catalog_data_unlock($pdo);
        } catch (Throwable $e) {
            // MySQL drops connection-scoped locks when the connection closes.
            error_log('[41Lokeren catalog unlock] ' . $e->getMessage());
        }
    });
}
