<?php
declare(strict_types=1);

/**
 * Technical operator tool — incident PLAN ONLY.
 *
 * Run on an isolated clone, or from an authorized PHP CLI if available.
 * No --restore / --clear / --rollback command exists.
 * Never print database values, passwords, images or private file paths.
 */
if (PHP_SAPI !== 'cli' || $argc !== 2 || $argv[1] !== '--plan') {
    fwrite(STDERR, "Usage: php scripts/restore_diagnose.php --plan\n");
    exit(64);
}
try {
    require dirname(__DIR__).'/private/bootstrap.php';
    require dirname(__DIR__).'/private/restore_diagnosis.php';
    $result=restore_recovery_diagnose(db());
    echo json_encode($result,
        JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    // Pending and uncertain recovery means an operator must not assume success.
    exit($result['status']==='clear' ? 0 : 2);
} catch (Throwable $error) {
    fwrite(STDERR,
        "Recovery diagnosis could not safely complete. Keep maintenance on; manual technical review required.\n");
    exit(3);
}
