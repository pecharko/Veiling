<?php
declare(strict_types=1);

/**
 * OFFLINE operator-only incident tool for a confirmed pre-commit crash.
 *
 * No database writes, photo deletes, automatic replay or journal bypass.
 * The photo-only rollback is permitted only if the current MySQL DB equals
 * the COMPLETE pre-restore safety ZIP and the two entire photo sets match
 * their independently verified original and target ZIPs.
 *
 * Run with --plan first; inspect evidence and preserve external backup;
 * only then consider a SEPARATE --rollback invocation with fresh challenge.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
function precommit_cli_usage(): never {
    fwrite(STDERR,
        "Usage: php scripts/restore_precommit.php --plan ".
        "--safety-snapshot SNAPSHOT --operator ID\n".
        "   OR: php scripts/restore_precommit.php --rollback ".
        "--safety-snapshot SNAPSHOT --operator ID --run-id HEX32 ".
        "--challenge HEX64 --confirmation PHRASE ".
        "--external-copy-verified PHRASE --external-copy-sha256 HEX64\n".
        "No SQL restore or automatic incident rollback is supported.\n");
    exit(64);
}
$args = $argv;
array_shift($args);
$allowed = [
    '--plan','--rollback','--safety-snapshot','--operator',
    '--run-id','--challenge','--confirmation',
    '--external-copy-verified','--external-copy-sha256',
];
$values = [];
while ($args) {
    $key = array_shift($args);
    if (!in_array($key, $allowed, true) || array_key_exists($key, $values)) {
        precommit_cli_usage();
    }
    if ($key === '--plan' || $key === '--rollback') {
        $values[$key] = true;
        continue;
    }
    if (!$args || str_starts_with($args[0], '--')) precommit_cli_usage();
    $values[$key] = array_shift($args);
}
$isPlan = isset($values['--plan']);
$isRollback = isset($values['--rollback']);
if ($isPlan === $isRollback ||
    !isset($values['--safety-snapshot'], $values['--operator'])) {
    precommit_cli_usage();
}
if ($isPlan && count($values) !== 3) precommit_cli_usage();
if ($isRollback && count($values) !== 8) precommit_cli_usage();
try {
    require dirname(__DIR__) . '/private/bootstrap.php';
    require dirname(__DIR__) . '/private/restore_precommit.php';
    $pdo = db();
    $name = $values['--safety-snapshot'];
    $operator = $values['--operator'];
    if ($isPlan) {
        $plan = recovery_precommit_plan($pdo, $name, $operator);
        echo json_encode($plan,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        exit($plan['ready'] ? 2 : 3);
    }
    if ($values['--external-copy-verified'] !==
        'IK HEB EEN EXTERNE KOPIE GECONTROLEERD') {
        throw new RuntimeException('Offsite safety archive has not been verified.');
    }
    $result = recovery_precommit_approved(
        $pdo, $name, $operator, $values['--external-copy-sha256'],
        $values['--run-id'], $values['--challenge'], $values['--confirmation']
    );
    echo json_encode($result,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    exit(0);
} catch (Throwable $error) {
    // Never leak DB rows, ZIP paths, credentials or source stack traces.
    fwrite(STDERR,
        "Veilige fotoherstelactie geweigerd. Laat de website in onderhoud, ".
        "bewaar alle bestanden en vraag technische incidentreview.\n");
    exit(3);
}
