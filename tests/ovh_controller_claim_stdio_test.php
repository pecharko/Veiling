<?php
/** Synthetic private-CLI harness only: no PDO, config or network. */
declare(strict_types=1);
require __DIR__ . '/../scripts/ovh_controller_claim_executor.php';

final class StdioFixtureStore implements OvhClaimStore {
    public function reserve(array $binding): bool { return true; }
    public function transition(array $binding, string $old, string $new): void {}
    public function read(array $binding): array { return ['binding'=>$binding,'state'=>'RESERVED']; }
}
$now = (int)(getenv('OVH_CLAIM_TEST_NOW') ?: '0');
if ($now <= 0) throw new RuntimeException('Synthetic test time required');
$timeout = ($argv[1] ?? '') === '--test-short-timeout' ? 0.05 : 15.0;
exit(ovh_claim_stdio(STDIN, STDOUT, 'offline-ovh-claims-signing-key-123456789', $now,
    fn()=>new StdioFixtureStore(), $timeout));
