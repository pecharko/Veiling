<?php
/** Dormant private-executor library. No endpoint, config, connection or cron.
 * Direct execution is always disabled. PDOs/keys are supplied only by tests or
 * a future separately reviewed private runtime. Claims never authorize deploy.
 */
declare(strict_types=1);

function ovh_claim_canonical(mixed $value): string {
    $sort = function (mixed $v) use (&$sort): mixed {
        if (!is_array($v)) return $v;
        if (!array_is_list($v)) ksort($v, SORT_STRING);
        foreach ($v as $k => $item) $v[$k] = $sort($item);
        return $v;
    };
    return json_encode($sort($value), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function ovh_claim_keys(array $value, array $keys): void {
    $actual = array_keys($value); sort($actual); sort($keys);
    if ($actual !== $keys) throw new InvalidArgumentException('Unexpected keys');
}

function ovh_claim_hash(mixed $value): bool {
    return is_string($value) && preg_match('/^[0-9a-f]{64}$/D', $value) === 1;
}

function ovh_claim_binding(array $b): void {
    ovh_claim_keys($b, ['repository','owner_id','issue_id','comment_id','nonce','release_id',
                      'main_sha','manifest_sha256','approval_sha256','files','push_ci_run_ids']);
    if ($b['repository'] !== 'pecharko/Veiling' || !is_string($b['release_id'])
        || preg_match('/^[a-z0-9][a-z0-9-]{2,60}$/D', $b['release_id']) !== 1
        || !is_string($b['main_sha']) || preg_match('/^[0-9a-f]{40}$/D', $b['main_sha']) !== 1) {
        throw new InvalidArgumentException('Invalid immutable identity');
    }
    foreach (['owner_id','issue_id','comment_id'] as $k) {
        if (!is_int($b[$k]) || $b[$k] <= 0 || $b[$k] > 9007199254740991) {
            throw new InvalidArgumentException('Invalid account/event ID');
        }
    }
    foreach (['nonce','manifest_sha256','approval_sha256'] as $k) {
        if (!ovh_claim_hash($b[$k])) throw new InvalidArgumentException('Invalid digest');
    }
    $ci = $b['push_ci_run_ids'];
    if (!is_array($ci) || !array_is_list($ci) || count($ci) !== 3) {
        throw new InvalidArgumentException('Invalid CI identities');
    }
    foreach ($ci as $n) {
        if (!is_int($n) || $n <= 0 || $n > 9007199254740991) throw new InvalidArgumentException('Invalid CI ID');
    }
    if (count(array_unique($ci, SORT_REGULAR)) !== 3) throw new InvalidArgumentException('Duplicate CI ID');
    if (!is_array($b['files']) || !array_is_list($b['files']) || count($b['files']) < 1 || count($b['files']) > 25) {
        throw new InvalidArgumentException('Invalid file list');
    }
    $seen = [];
    foreach ($b['files'] as $f) {
        if (!is_array($f)) throw new InvalidArgumentException('Invalid file');
        ovh_claim_keys($f, ['path','source_sha256','remote_sha256']);
        $p = $f['path'];
        if (!is_string($p) || preg_match('~^(catalogus-public|private)/[A-Za-z0-9_./-]+\.(php|css|js|svg|webp|png|jpg|jpeg|html)$~D', $p) !== 1
            || isset($seen[$p]) || !ovh_claim_hash($f['source_sha256'])
            || ($f['remote_sha256'] !== 'ABSENT' && !ovh_claim_hash($f['remote_sha256']))) {
            throw new InvalidArgumentException('Invalid path/hash');
        }
        foreach (explode('/', $p) as $part) {
            if ($part === '' || str_starts_with($part, '.')) throw new InvalidArgumentException('Unsafe path');
        }
        foreach (['private/config','private/snapshots','private/visitor_stats_daily_cron.php'] as $blocked) {
            if (str_starts_with($p, $blocked)) throw new InvalidArgumentException('Protected path');
        }
        $seen[$p] = true;
    }
    if (strlen(ovh_claim_canonical($b)) > 65536) throw new InvalidArgumentException('Oversized binding');
}

function ovh_claim_transition(string $old, string $new): void {
    if (!in_array([$old,$new], [['RESERVED','ATTEMPTED'],['RESERVED','ABORTED'],
                               ['ATTEMPTED','ACCEPTED'],['ATTEMPTED','UNKNOWN']], true)) {
        throw new InvalidArgumentException('Forbidden state transition');
    }
}

interface OvhClaimStore {
    public function reserve(array $binding): bool;
    public function transition(array $binding, string $old, string $new): void;
    public function read(array $binding): array;
}

final class OvhMysqlClaimStore implements OvhClaimStore {
    public function __construct(private PDO $db) {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql' || $db->inTransaction()) {
            throw new InvalidArgumentException('Require separate MySQL connection');
        }
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    private function rollback(): void {
        if ($this->db->inTransaction()) $this->db->rollBack();
    }

    public function reserve(array $binding): bool {
        ovh_claim_binding($binding);
        try {
            $this->db->beginTransaction();
            $q = $this->db->prepare('INSERT INTO controller_release_claims '
                . '(repository,issue_id,comment_id,release_id,release_sha) VALUES (?,?,?,?,?)');
            $q->execute([$binding['repository'],$binding['issue_id'],$binding['comment_id'],
                         $binding['release_id'],$binding['main_sha']]);
            $id = $this->db->lastInsertId();
            $q = $this->db->prepare('INSERT INTO controller_release_claim_bindings '
                . '(claim_id,nonce,binding_sha256,binding_json,state) VALUES (?,?,?,?,?)');
            $json = ovh_claim_canonical($binding);
            $q->execute([$id,$binding['nonce'],hash('sha256',$json),$json,'RESERVED']);
            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            $this->rollback();
            if ($e instanceof PDOException && ($e->errorInfo[1] ?? null) === 1062) return false;
            // Includes failure after a possibly successful COMMIT. No retry.
            throw new RuntimeException('Claim result unknown; STOP, NO RETRY');
        }
    }

    private function record(array $binding, bool $lock = false): array {
        ovh_claim_binding($binding);
        $q = $this->db->prepare('SELECT b.claim_id,b.binding_sha256,b.binding_json,b.state,'
            . 'c.repository,c.issue_id,c.comment_id,c.release_id,c.release_sha '
            . 'FROM controller_release_claim_bindings b JOIN controller_release_claims c '
            . 'ON c.id=b.claim_id WHERE b.nonce=?' . ($lock ? ' FOR UPDATE' : ''));
        $q->execute([$binding['nonce']]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['binding_sha256'] !== hash('sha256', ovh_claim_canonical($binding))) {
            throw new RuntimeException('Missing/mismatched claim');
        }
        $stored = json_decode($row['binding_json'], true, 32, JSON_THROW_ON_ERROR);
        if (ovh_claim_canonical($stored) !== ovh_claim_canonical($binding)
            || $row['repository'] !== $binding['repository']
            || (int)$row['issue_id'] !== $binding['issue_id'] || (int)$row['comment_id'] !== $binding['comment_id']
            || $row['release_id'] !== $binding['release_id'] || $row['release_sha'] !== $binding['main_sha']) {
            throw new RuntimeException('Parent/binding drift');
        }
        return $row;
    }

    public function transition(array $binding, string $old, string $new): void {
        ovh_claim_transition($old, $new);
        try {
            $this->db->beginTransaction();
            $row = $this->record($binding, true);
            if ($row['state'] !== $old) throw new RuntimeException('Stale state');
            $q = $this->db->prepare('UPDATE controller_release_claim_bindings SET state=? WHERE claim_id=? AND state=?');
            $q->execute([$new,$row['claim_id'],$old]);
            if ($q->rowCount() !== 1) throw new RuntimeException('Non-exclusive update');
            // A 204 ACCEPTED is NOT a confirmed workflow run or production proof.
            $parentState = $new === 'ABORTED' ? 'CLOSED' : 'DISPATCH_UNKNOWN';
            $q = $this->db->prepare('UPDATE controller_release_claims SET claim_state=? WHERE id=?');
            $q->execute([$parentState,$row['claim_id']]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->rollback();
            throw new RuntimeException('Transition unknown; STOP, NO RETRY');
        }
    }

    public function read(array $binding): array {
        $row = $this->record($binding);
        return ['binding'=>$binding, 'state'=>$row['state']];
    }
}

function ovh_claim_mac(string $key, string $domain, string $raw): string {
    if (strlen($key) < 32 || strlen($key) > 1024) throw new InvalidArgumentException('Invalid signing key');
    return hash_hmac('sha256', $domain . $raw, $key);
}

function ovh_claim_handle(string $raw, string $signature, string $key, int $now, callable $factory): array {
    if (strlen($raw) < 1 || strlen($raw) > 131072 || !ovh_claim_hash($signature)
        || !hash_equals(ovh_claim_mac($key, "ovh-controller-claims/request/v1\n", $raw), $signature)) {
        throw new InvalidArgumentException('Invalid signed request');
    }
    $r = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($r) || ovh_claim_canonical($r) !== $raw) {
        // Canonical roundtrip also rejects duplicate properties and alternate
        // whitespace/number forms before any connection is constructed.
        throw new InvalidArgumentException('Noncanonical/duplicate request');
    }
    ovh_claim_keys($r, ['schema','operation','binding','issued_at','expires_at','request_nonce','from_state','to_state']);
    if ($r['schema'] !== 1 || !is_array($r['binding']) || !ovh_claim_hash($r['request_nonce'])
        || !is_int($r['issued_at']) || !is_int($r['expires_at']) || $r['issued_at'] <= 0
        || $r['expires_at'] - $r['issued_at'] < 1 || $r['expires_at'] - $r['issued_at'] > 300
        || $now < $r['issued_at'] - 30 || $now > $r['expires_at']) {
        throw new InvalidArgumentException('Invalid request identity/lifetime');
    }
    ovh_claim_binding($r['binding']);
    if (!in_array($r['operation'], ['reserve','transition','read'], true)) throw new InvalidArgumentException('Invalid operation');
    if ($r['operation'] === 'transition') {
        if (!is_string($r['from_state']) || !is_string($r['to_state'])) throw new InvalidArgumentException('Invalid states');
        ovh_claim_transition($r['from_state'], $r['to_state']);
    } elseif ($r['from_state'] !== null || $r['to_state'] !== null) {
        throw new InvalidArgumentException('Unexpected transition fields');
    }
    $outcome = 'UNKNOWN_STOP_NO_RETRY'; $record = null;
    try {
        $store = $factory();
        if (!$store instanceof OvhClaimStore) throw new RuntimeException('Missing private store');
        if ($r['operation'] === 'reserve') $outcome = $store->reserve($r['binding']) ? 'RESERVED' : 'CONFLICT';
        elseif ($r['operation'] === 'transition') {
            $store->transition($r['binding'], $r['from_state'], $r['to_state']); $outcome = $r['to_state'];
        } else { $record = $store->read($r['binding']); $outcome = 'RECORD'; }
    } catch (Throwable $e) {
        // Never include SQL, PDO messages, connection details or keys in output.
        $outcome = 'UNKNOWN_STOP_NO_RETRY'; $record = null;
    }
    $response = ovh_claim_canonical(['schema'=>1,'operation'=>$r['operation'],
        'request_nonce'=>$r['request_nonce'],
        'binding_sha256'=>hash('sha256',ovh_claim_canonical($r['binding'])),
        'outcome'=>$outcome,'record'=>$record]);
    return ['body'=>$response,'signature'=>ovh_claim_mac($key, "ovh-controller-claims/response/v1\n", $response)];
}

function ovh_claim_execute(): array {
    return ['status'=>'DISABLED','dispatch_started'=>false,'write_allowed'=>false,'retry_allowed'=>false];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    echo json_encode(ovh_claim_execute(), JSON_THROW_ON_ERROR), "\n";
    exit(78);
}
