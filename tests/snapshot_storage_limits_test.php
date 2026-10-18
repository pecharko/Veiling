<?php
declare(strict_types=1);

/**
 * T-016/T-017: deterministic warning boundaries and snapshot-capacity checks.
 * Only executable on disposable MySQL in GitHub Actions, never production.
 */
if (getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('CI_SNAPSHOT_TEST') !== 'yes'
    || getenv('CI_DB_HOST') !== '127.0.0.1'
    || getenv('CI_DB_NAME') !== 'auction_test') {
    throw new RuntimeException('Storage limits tests require an isolated CI environment.');
}
require dirname(__DIR__) . '/private/bootstrap.php';
require dirname(__DIR__) . '/private/snapshots.php';
$pdo=db();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'auction_test') {
    throw new RuntimeException('Unexpected MySQL database: refusing capacity tests.');
}
$passed=0;
function capacity_check(bool $condition,string $title): void {
    global $passed;
    if (!$condition) throw new RuntimeException('FAIL: '.$title);
    $passed++;
    echo "PASS: {$title}\n";
}
function capacity_fails(callable $action,string $expected,string $title): void {
    try {
        $action();
    } catch (Throwable $e) {
        capacity_check(str_contains($e->getMessage(),$expected),$title);
        return;
    }
    capacity_check(false,$title.' (unexpected success)');
}

// T-016 — boundaries from the same classifier used by admin/backup.php.
foreach ([
  [0.0,'ok'], [79.0,'ok'], [79.999,'ok'], [80.0,'warning'],
  [89.0,'warning'], [89.999,'warning'], [90.0,'critical'],
  [95.0,'critical'], [100.0,'critical'],
] as [$pct,$level]) {
    capacity_check(snap_storage_level($pct)===$level,"T-016 storage {$pct}% -> {$level}");
}

// T-017 — unit-level estimates with and without scanning warnings.
$margin=20*1024*1024;
$photos=3072;
$dbsize=8192;
$required=$margin+$photos+$dbsize;
$valid=['free'=>$required,'warnings'=>[]];
capacity_check(snap_has_estimated_capacity($valid,$photos,$dbsize),
    'T-017 estimated free space at exact boundary allows a snapshot');
$valid['free']=$required-1;
capacity_check(!snap_has_estimated_capacity($valid,$photos,$dbsize),
    'T-017 one byte less than required refuses a snapshot');
$valid['free']=$required+100;
capacity_check(snap_has_estimated_capacity($valid,$photos,$dbsize),
    'T-017 sufficient estimated free space allows a snapshot');
$valid['warnings']=['File scan incomplete'];
capacity_check(!snap_has_estimated_capacity($valid,$photos,$dbsize),
    'T-017 incomplete file scan fails closed even when quota looks adequate');
capacity_check(!snap_has_estimated_capacity(['free'=>null,'warnings'=>[]],$photos,$dbsize),
    'T-017 missing quota estimate fails closed');
capacity_check(!snap_has_estimated_capacity(['free'=>$required,'warnings'=>[]],-1,$dbsize),
    'T-017 invalid negative upload size fails closed');

capacity_check(!snap_has_physical_capacity(0,$photos),
    'T-017 zero actual free disk bytes blocks snapshot even if quota estimate is healthy');
capacity_check(!snap_has_physical_capacity($margin+$photos-1,$photos),
    'T-017 physical disk one byte below margin blocks snapshot');
capacity_check(snap_has_physical_capacity($margin+$photos,$photos),
    'T-017 physical disk exactly at required margin allows snapshot');
capacity_check(snap_has_physical_capacity(false,$photos),
    'T-017 unavailable disk_free_space leaves quota preflight responsible');

// Real snap_create with simulated quota. No large files / production storage.
$storage=snap_storage_stats();
$assets=snap_assets();
$actualPhotoBytes=0;
foreach ($assets as $path) $actualPhotoBytes+=(int)filesize($path);
$tables=[];
foreach (SNAP_TABLES as $t) {
    $tables[$t]=$pdo->query('SELECT * FROM '.$t)->fetchAll(PDO::FETCH_ASSOC);
}
$dbJson=json_encode(
    ['format'=>'41lokeren-v1','created_at'=>gmdate('c'),'tables'=>$tables],
    JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE
);
$exactRequirement=$margin+$actualPhotoBytes+strlen($dbJson);
$startingCount=count(snap_listing());
$startingLotTitle=(string)$pdo->query('SELECT title FROM lots WHERE lot_number=101')->fetchColumn();
$quotaOneByteShort=$storage['total']+$exactRequirement-1;
capacity_fails(
    fn() => snap_create($pdo,$quotaOneByteShort),
    'Onvoldoende geschatte hostingruimte',
    'T-017 actual snap_create refuses simulated quota one byte short of margin'
);
capacity_check(count(snap_listing())===$startingCount,
    'T-017 insufficient quota does not add a snapshot ZIP');
capacity_check(!(glob(snap_dir().'/.building-*.zip') ?: []),
    'T-017 refused snapshot leaves no temporary ZIP');
capacity_check((string)$pdo->query('SELECT title FROM lots WHERE lot_number=101')->fetchColumn()===$startingLotTitle,
    'T-017 refused snapshot does not modify any lot');
capacity_fails(
    fn() => snap_create($pdo,0),
    'Ongeldige hostinglimiet',
    'T-017 zero quota is rejected before writing files'
);
capacity_check(count(snap_listing())===$startingCount,
    'T-017 invalid quota leaves snapshot list untouched');

// Exact boundary allowed: the 20 MiB safety margin is included in requirement.
$quotaExact=$storage['total']+$exactRequirement;
$new=snap_create($pdo,$quotaExact);
capacity_check(snap_name($new) && is_file(snap_path($new)),
    'T-017 actual snap_create succeeds exactly at required quota boundary');
[$database,$hashes]=snap_verified($new);
capacity_check(isset($database['tables']['lots']) && isset($hashes['database.json']),
    'T-017 boundary snapshot is valid and checksum-verifiable');
capacity_check(count(snap_listing())===$startingCount+1,
    'T-017 only successful capacity case adds one ZIP');
capacity_check((string)$pdo->query('SELECT title FROM lots WHERE lot_number=101')->fetchColumn()===$startingLotTitle,
    'T-017 successful snapshot is read-only for database');

echo "Storage threshold and capacity: {$passed} checks passed in isolated CI.\n";
