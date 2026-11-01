<?php
/** Disposable local claim_ci ONLY; never a runtime/config/PDO loader. */
declare(strict_types=1);
require __DIR__ . '/../scripts/ovh_controller_claim_executor.php';

if (getenv('OVH_CLAIM_FULLCHAIN_TEST') !== '1') exit(78);
$mode = $argv[1] ?? '';
$now = filter_var($argv[2] ?? '', FILTER_VALIDATE_INT);
if (!in_array($mode, ['reset','inspect','normal','reserve-unknown','accepted-unknown'], true)
    || !is_int($now) || $now <= 0) exit(78);

final class FullChainFixturePDO extends PDO {
    public bool $loseAck = false;
    public function commit(): bool {
        $ok = parent::commit();
        if ($this->loseAck) {
            $this->loseAck = false;
            throw new RuntimeException('Synthetic lost local commit acknowledgement');
        }
        return $ok;
    }
}
function fullChainFixtureConnection(): FullChainFixturePDO {
    // Literal disposable CI credentials. No production/env datasource accepted.
    return new FullChainFixturePDO(
        'mysql:host=127.0.0.1;port=3306;dbname=claim_ci;charset=utf8mb4',
        'ci', 'local-claims-ci', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
}
try {
    if ($mode === 'reset') {
        $db = fullChainFixtureConnection();
        $db->beginTransaction();
        $db->exec('DELETE FROM controller_release_claim_bindings');
        $db->exec('DELETE FROM controller_release_claims');
        $db->commit();
        echo "{\"reset\":true}\n";
        exit(0);
    }
    if ($mode === 'inspect') {
        $db = fullChainFixtureConnection();
        $rows = $db->query('SELECT b.state, p.claim_state FROM controller_release_claim_bindings b '
            . 'JOIN controller_release_claims p ON p.id=b.claim_id')->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows, JSON_THROW_ON_ERROR), "\n";
        exit(0);
    }
    // Request HMAC/expiry/binding validation in ovh_claim_stdio precedes PDO.
    $factory = function () use ($mode): OvhClaimStore {
        $db = fullChainFixtureConnection();
        $db->loseAck = $mode !== 'normal';
        return new OvhMysqlClaimStore($db);
    };
    exit(ovh_claim_stdio(STDIN, STDOUT, 'offline-ovh-claims-signing-key-123456789', $now, $factory));
} catch (Throwable $e) {
    // Even test failures must not dump connection or protocol details.
    fwrite(STDERR, "Isolated full-chain fixture failed\n");
    exit(65);
}
