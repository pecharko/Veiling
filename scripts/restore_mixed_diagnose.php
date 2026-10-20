<?php
declare(strict_types=1);

/**
 * Read-only CLI incident evidence report.
 *
 * This script has NO option to restore SQL, rename images, clear a journal,
 * or unlock maintenance. Use on a protected OFFLINE incident copy.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if ($argc !== 4 || $argv[1] !== '--plan' ||
    $argv[2] !== '--safety-snapshot') {
    fwrite(STDERR,
        "Usage: php scripts/restore_mixed_diagnose.php ".
        "--plan --safety-snapshot snapshot-YYYYMMDD-HHMMSS-HEX.zip\n".
        "Offline forensic diagnosis ONLY. No restore option is available.\n");
    exit(64);
}
try {
    require dirname(__DIR__) . '/private/bootstrap.php';
    require dirname(__DIR__) . '/private/restore_mixed_diagnosis.php';
    $report = restore_mixed_diagnose(db(), $argv[3]);
    echo json_encode(
        $report,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . "\n";
    // A diagnosis is never a production recovery or acceptance.
    exit($report['status'] === 'blocked_unverified' ? 3 : 2);
} catch (Throwable $error) {
    // Never reveal private paths, DB rows, passwords or stack traces.
    fwrite(STDERR,
        "Hersteldiagnose onvolledig. Bewaar de huidige gegevens en ".
        "laat de onderhoudsblokkering actief tot deskundige beoordeling.\n");
    exit(3);
}
