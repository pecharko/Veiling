<?php
/** Real MySQL tests, exclusively disposable claim_ci on 127.0.0.1. */
declare(strict_types=1);
require __DIR__ . '/../scripts/ovh_controller_claim_store.php';

$dsn = getenv('CLAIM_TEST_DSN') ?: '';
$user = getenv('CLAIM_TEST_USER') ?: '';
$pass = getenv('CLAIM_TEST_PASSWORD') ?: '';
if ($dsn !== 'mysql:host=127.0.0.1;port=3306;dbname=claim_ci;charset=utf8mb4' || $user !== 'ci') {
    fwrite(STDERR, "Disposable local CI datasource only\n"); exit(2);
}
function connectFixture(): PDO {
    global $dsn, $user, $pass;
    return new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
}
function check(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
}
function resetFixture(PDO $db): void {
    $db->exec('DELETE FROM controller_release_claim_bindings');
    $db->exec('DELETE FROM controller_release_claims');
}
function rejected(callable $action): void {
    $caught = false;
    try { $action(); } catch (Throwable $e) { $caught = true; }
    check($caught, 'Expected fail-closed rejection');
}

if (($argv[1] ?? '') === '--worker') {
    $fixture = json_decode(file_get_contents($argv[2]), true, 32, JSON_THROW_ON_ERROR);
    $limit = microtime(true) + 10;
    while (!file_exists($fixture['barrier'])) {
        if (microtime(true) > $limit) throw new RuntimeException('Fixture barrier timeout');
        usleep(10000);
    }
    $store = new OvhMysqlClaimStore(connectFixture());
    echo json_encode($store->reserve($fixture['binding']), JSON_THROW_ON_ERROR);
    exit(0);
}

$db = connectFixture();
$proposal = file_get_contents(__DIR__ . '/../database/controller_release_claim_bindings_proposal.sql');
if ($proposal === false) throw new RuntimeException('Missing proposal');
$db->exec($proposal);
$store = new OvhMysqlClaimStore($db);
$binding = ['repository'=>'pecharko/Veiling','owner_id'=>123,'issue_id'=>512,'comment_id'=>77,
    'nonce'=>str_repeat('e',64),'release_id'=>'ovh-claims-ci','main_sha'=>str_repeat('b',40),
    'manifest_sha256'=>str_repeat('c',64),'approval_sha256'=>str_repeat('d',64),
    'files'=>[['path'=>'catalogus-public/index.php','source_sha256'=>str_repeat('a',64),'remote_sha256'=>'ABSENT']],
    'push_ci_run_ids'=>[800,801,900]];

// Three independent PHP/PDO processes race behind a single release barrier.
$before = (int)$db->query('SELECT COUNT(*) FROM controller_release_claims')->fetchColumn();
$fixturePath = tempnam(sys_get_temp_dir(), 'ovh-claim-fixture-');
if ($fixturePath === false) throw new RuntimeException('Temporary fixture unavailable');
$barrier = $fixturePath . '.ready';
file_put_contents($fixturePath, json_encode(['binding'=>$binding,'barrier'=>$barrier], JSON_THROW_ON_ERROR));
$workers = [];
try {
    for ($i=0; $i<3; $i++) {
        $pipes = [];
        $process = proc_open([PHP_BINARY,__FILE__,'--worker',$fixturePath],
            [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Worker unavailable');
        $workers[] = [$process,$pipes];
    }
    touch($barrier);
    $wins = 0;
    foreach ($workers as [$process,$pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        check(proc_close($process) === 0 && $stderr === '', 'Concurrent worker failed');
        if (json_decode($stdout, true, 32, JSON_THROW_ON_ERROR) === true) $wins++;
    }
    check($wins === 1, 'At most one concurrent SQL reservation');
} finally {
    if (file_exists($fixturePath)) unlink($fixturePath);
    if (file_exists($barrier)) unlink($barrier);
}
check((int)$db->query('SELECT COUNT(*) FROM controller_release_claims')->fetchColumn() === $before+1,
      'Conflict must roll back parent insert');
check($store->read($binding)['state'] === 'RESERVED', 'Read exact reservation');

foreach ([['release_id'=>'another-release','nonce'=>str_repeat('f',64)],
          ['comment_id'=>78,'nonce'=>str_repeat('f',64)],
          ['comment_id'=>78,'release_id'=>'another-release','nonce'=>str_repeat('f',64)]] as $change) {
    check($store->reserve(array_replace($binding,$change)) === false, 'Independent uniqueness/global gate');
}
$drift = $binding; $drift['files'][0]['remote_sha256'] = str_repeat('f',64);
rejected(fn()=>$store->read($drift));
rejected(fn()=>$store->transition($binding,'RESERVED','ACCEPTED'));
$store->transition($binding,'RESERVED','ABORTED');
check($store->reserve($binding) === false, 'Aborted release remains consumed');

// Old pre-binding claims must remain protected by the existing parent indexes.
$legacy = array_replace($binding,['release_id'=>'release-demo','nonce'=>str_repeat('f',64),'comment_id'=>79]);
check($store->reserve($legacy) === false, 'No bypass of existing legacy release claim');
$second = array_replace($binding,['release_id'=>'ovh-second-ci','nonce'=>str_repeat('f',64),'comment_id'=>78]);
check($store->reserve($second) === true, 'Aborted active slot may admit a different release');
$store->transition($second,'RESERVED','ATTEMPTED');
$store->transition($second,'ATTEMPTED','ACCEPTED');
check($store->read($second)['state'] === 'ACCEPTED', 'Accepted protocol result persisted');
$q=$db->prepare('SELECT claim_state FROM controller_release_claims WHERE release_id=?');
$q->execute([$second['release_id']]);
check($q->fetchColumn() === 'DISPATCH_UNKNOWN', '204 must never imply confirmed workflow');
rejected(fn()=>$store->transition($second,'RESERVED','ATTEMPTED'));
rejected(fn()=>$store->transition($second,'ACCEPTED','RESERVED'));

// SQL alone is deliberately NOT a restore-safe authority: the Python wrapper
// must block this replay using an independent earlier reservation.
resetFixture($db);
check($store->reserve($binding), 'Restore scenario initial reserve');
resetFixture($db);
check($store->reserve($binding), 'Demonstrate replay vulnerability of SQL restore alone');

final class CommitUnknownPDO extends PDO {
    public bool $failCommit = false;
    public function commit(): bool {
        $ok = parent::commit();
        if ($this->failCommit) { $this->failCommit=false; throw new RuntimeException('synthetic lost commit ack'); }
        return $ok;
    }
}
resetFixture($db);
$unknown = new CommitUnknownPDO($dsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$unknownStore = new OvhMysqlClaimStore($unknown);
$unknown->failCommit = true;
rejected(fn()=>$unknownStore->reserve($binding));
check($store->read($binding)['state'] === 'RESERVED', 'Ambiguous commit can have succeeded');
check(!$store->reserve($binding), 'Never retry a possibly committed claim');

// A failure after parent insertion must roll back both parts of the claim.
resetFixture($db);
final class FailBindingStatement extends PDOStatement {
    protected function __construct() {}
    public function execute(?array $params = null): bool {
        if (str_starts_with($this->queryString, 'INSERT INTO controller_release_claim_bindings ')) {
            throw new RuntimeException('synthetic failure after actual parent insert');
        }
        return parent::execute($params);
    }
}
$failureDb = connectFixture();
$failureDb->setAttribute(PDO::ATTR_STATEMENT_CLASS, [FailBindingStatement::class, []]);
$failureStore = new OvhMysqlClaimStore($failureDb);
rejected(fn()=>$failureStore->reserve($binding));
check((int)$db->query('SELECT COUNT(*) FROM controller_release_claims')->fetchColumn() === 0,
      'No orphan parent after transaction rollback');
check((int)$db->query('SELECT COUNT(*) FROM controller_release_claim_bindings')->fetchColumn() === 0,
      'No partial binding after transaction rollback');
echo "PASS isolated OVH claim SQL: concurrency, dual-table atomicity, replay, state and ambiguous commits\n";
