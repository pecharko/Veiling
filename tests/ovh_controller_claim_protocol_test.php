<?php
/** Synthetic stdin protocol harness. No datasource, network or production config. */
declare(strict_types=1);
require __DIR__ . '/../scripts/ovh_controller_claim_store.php';

final class ProtocolClaimStore implements OvhClaimStore {
    public function __construct(private bool $fail) {}
    public function reserve(array $binding): bool {
        if ($this->fail) throw new RuntimeException('synthetic-private-error');
        return true;
    }
    public function transition(array $binding, string $old, string $new): void {
        if ($this->fail) throw new RuntimeException('synthetic-private-error');
    }
    public function read(array $binding): array { return ['binding'=>$binding,'state'=>'RESERVED']; }
}

$input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
if (($input['key'] ?? null) !== 'offline-ovh-claims-signing-key-123456789') {
    throw new RuntimeException('Synthetic fixture key only');
}
$results = [];
foreach ($input['cases'] as $case) {
    $calls = 0;
    try {
        $response = ovh_claim_handle($case['body'], $case['signature'], $input['key'], $case['now'],
            function () use (&$calls, $case): OvhClaimStore {
                $calls++; return new ProtocolClaimStore(($case['mode'] ?? '') === 'throw');
            });
        $results[] = ['accepted'=>true,'factory_calls'=>$calls,'response'=>$response];
    } catch (Throwable $e) {
        $results[] = ['accepted'=>false,'factory_calls'=>$calls];
    }
}
echo json_encode($results, JSON_THROW_ON_ERROR), "\n";
