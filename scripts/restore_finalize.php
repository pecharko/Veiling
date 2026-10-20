<?php
declare(strict_types=1);

/**
 * Recovery operator CLI. NO automatic DB/photo restoration is available.
 *
 * Example PLAN:
 *   php scripts/restore_finalize.php --plan --safety-snapshot snapshot-....zip --operator staff-id
 *
 * In a separate human-approved step:
 *   php scripts/restore_finalize.php --finalize ... --run-id RUNID
 *      --challenge CHALLENGE --confirmation "IK KEUR HET AFRONDEN VAN DIT HERSTEL GOED"
 *      --external-copy-verified "IK HEB EEN EXTERNE KOPIE GECONTROLEERD"
 *
 * This can only finalize an already byte-for-byte verified target restore.
 * It NEVER imports SQL, overwrites current photos or deletes old evidence.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
function finalize_cli_usage(): never {
    fwrite(STDERR,
       "Usage: php scripts/restore_finalize.php --plan --safety-snapshot NAME --operator ID\n".
       "   OR: php scripts/restore_finalize.php --finalize --safety-snapshot NAME --operator ID ".
       "--run-id HEX32 --challenge HEX64 ".
       "--confirmation PHRASE --external-copy-verified PHRASE\n".
       "Never execute before independent review and approved external backups.\n");
    exit(64);
}
$args=$argv;array_shift($args);
$allowed=['--plan','--finalize','--safety-snapshot','--operator','--run-id',
          '--challenge','--confirmation','--external-copy-verified'];
$values=[];
while ($args) {
    $key=array_shift($args);
    if (!in_array($key,$allowed,true) || array_key_exists($key,$values)) {
        finalize_cli_usage();
    }
    if ($key==='--plan' || $key==='--finalize') {
        $values[$key]=true;continue;
    }
    if (!$args || str_starts_with($args[0],'--'))finalize_cli_usage();
    $values[$key]=array_shift($args);
}
$isPlan=isset($values['--plan']);$isFinal=isset($values['--finalize']);
if ($isPlan===$isFinal ||
    !isset($values['--safety-snapshot'],$values['--operator'])) {
    finalize_cli_usage();
}
if ($isPlan && count($values)!==3)finalize_cli_usage();
if ($isFinal && count($values)!==7)finalize_cli_usage();

try {
    require dirname(__DIR__).'/private/bootstrap.php';
    require dirname(__DIR__).'/private/restore_finalize.php';
    $pdo=db();
    $safety=$values['--safety-snapshot'];
    $operator=$values['--operator'];
    if ($isPlan) {
        $report=recovery_finalize_plan($pdo,$safety,$operator);
        echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
        // Never confuse a plan with a completed recovery.
        exit($report['ready'] ? 2 : 3);
    }
    if ($values['--external-copy-verified'] !==
        'IK HEB EEN EXTERNE KOPIE GECONTROLEERD') {
        throw new RuntimeException('External backup must be independently confirmed.');
    }
    $done=recovery_finalize_approved($pdo,$safety,$operator,
        $values['--run-id'],$values['--challenge'],$values['--confirmation']);
    echo json_encode($done,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    exit(0);
} catch (Throwable $error) {
    // Do not expose password data, arbitrary storage paths, or DB error text.
    fwrite(STDERR,
        "Herstelafronding geweigerd: controleer het plan, bewijs en goedkeuring. ".
        "Onderhoud moet actief blijven totdat een expert de situatie heeft beoordeeld.\n");
    exit(3);
}
