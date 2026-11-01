<?php
/** Dormant private CLI framing. No config, credential or DB connection loader.
 * No HTTP endpoint. Direct execution always stops DISABLED without stdin I/O.
 */
declare(strict_types=1);
require_once __DIR__ . '/ovh_controller_claim_store.php';

function ovh_claim_stdio($input, $output, string $key, int $now, callable $factory, float $readTimeout = 15.0): int {
    if (PHP_SAPI !== 'cli') return 78;
    try {
        if (!is_finite($readTimeout) || $readTimeout <= 0 || $readTimeout > 20) {
            throw new InvalidArgumentException('Invalid read bound');
        }
        $deadline = microtime(true) + $readTimeout;
        $frame = '';
        stream_set_blocking($input, false);
        while (!feof($input)) {
            if (microtime(true) >= $deadline) throw new RuntimeException('Input timeout');
            $chunk = fread($input, min(8192, 262145-strlen($frame)));
            if ($chunk === false) throw new RuntimeException('Input failure');
            $frame .= $chunk;
            if (strlen($frame) > 262144) throw new RuntimeException('Input limit');
            if ($chunk === '') usleep(10000);
        }
        if (!str_ends_with($frame, "\n")) throw new InvalidArgumentException('Missing frame terminator');
        $raw = substr($frame, 0, -1);
        $request = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($request) || ovh_claim_canonical($request) !== $raw) {
            throw new InvalidArgumentException('Noncanonical frame');
        }
        ovh_claim_keys($request, ['body','signature']);
        if (!is_string($request['body']) || !is_string($request['signature'])) {
            throw new InvalidArgumentException('Invalid frame fields');
        }
        // Existing request authentication/validation precedes the supplied
        // store factory. No config/PDO factory is installed by this proposal.
        $result = ovh_claim_handle($request['body'], $request['signature'], $key, $now, $factory);
        $wire = ovh_claim_canonical($result) . "\n";
        if (strlen($wire) > 262144 || fwrite($output, $wire) !== strlen($wire)) {
            throw new RuntimeException('Output uncertain');
        }
        return 0;
    } catch (Throwable $e) {
        // No payload, SQL, connection string, HMAC or exception in output/logs.
        fwrite($output, "{\"dispatch_started\":false,\"retry_allowed\":false,\"status\":\"REJECTED\"}\n");
        return 65;
    }
}

function ovh_claim_executor_main(): int {
    echo json_encode(ovh_claim_execute(), JSON_THROW_ON_ERROR), "\n";
    return 78;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(ovh_claim_executor_main());
}
