<?php
declare(strict_types=1);

/**
 * Destructive snapshot/restore regression tests ONLY against isolated CI MySQL.
 * Production execution is deliberately refused.
 */
$root = dirname(__DIR__);
if (getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('CI_SNAPSHOT_TEST') !== 'yes'
    || getenv('CI_DB_HOST') !== '127.0.0.1'
    || getenv('CI_DB_NAME') !== 'auction_test') {
    throw new RuntimeException('Run exclusively in the isolated GitHub Actions test database.');
}

require $root . '/private/bootstrap.php';
require $root . '/private/snapshots.php';
$pdo = db();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'auction_test') {
    throw new RuntimeException('Refusing to test against an unexpected database.');
}
$count = 0;
function verify(bool $yes, string $message): void {
    global $count;
    ++$count;
    if (!$yes) throw new RuntimeException('FAILED: ' . $message);
    echo 'PASS: ' . $message . "\n";
}
function assert_throws(callable $fn, string $expected, string $message): void {
    try { $fn(); }
    catch (Throwable $e) {
        verify(str_contains($e->getMessage(), $expected), $message);
        return;
    }
    verify(false, $message . ' (unexpected success)');
}

$pdo->exec('ALTER TABLE admins ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
$pdo->exec("UPDATE admins SET name='CI Beheerder'");
$pdo->exec('ALTER TABLE settings ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
$pdo->prepare("INSERT INTO lot_sellers (seller_type,name,email,street,house_number,postal_code,municipality,bank_account_name,bank_account_iban,artist_name,artist_url) VALUES ('kunstenaar','Fictieve maker','maker@example.invalid','Teststraat','2','9160','Lokeren','Fictieve maker','BE68539007547034','Demo','https://example.org/')")->execute();
$sellerId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO lots (lot_number,title,description,details,status,sale_location,auction_number) VALUES (?,?,?,?,?,?,?)")
    ->execute([101,'Kunstwerk voor snapshot','Beschrijving','Details','published','veiling',1]);
$lotId = (int)$pdo->lastInsertId();
$pdo->prepare('UPDATE lots SET seller_id=? WHERE id=?')->execute([$sellerId,$lotId]);
$pdo->prepare("INSERT INTO login_attempts (ip_hash) VALUES (?)")
    ->execute([hash('sha256','nonproduction-test-ip')]);
$originalAdmin = $pdo->query('SELECT email FROM admins LIMIT 1')->fetchColumn();
$originalAdminName = $pdo->query('SELECT name FROM admins LIMIT 1')->fetchColumn();

$relative = '2026/10/' . str_repeat('a', 32) . '.jpg';
$extra = '2026/10/' . str_repeat('b', 32) . '.jpg';
$uploads = $root . '/catalogus-public/uploads';
$folder = $uploads . '/2026/10';
if (!is_dir($folder) && !mkdir($folder, 0700, true)) throw new RuntimeException('Cannot create test upload directory.');
$original = $uploads . '/' . $relative;
$image = imagecreatetruecolor(48, 48);
imagefill($image, 0, 0, imagecolorallocate($image, 160, 32, 64));
imagejpeg($image, $original, 85);
imagedestroy($image);
$checksum = hash_file('sha256', $original);
$pdo->prepare('INSERT INTO lot_images(lot_id,path,sort_order,source_name) VALUES (?,?,?,?)')
    ->execute([$lotId,'uploads/'.$relative,1,'snapshot-test.jpg']);

$initialStorage = snap_storage_stats();
verify($initialStorage['total'] > 0 && $initialStorage['uploads'] >= filesize($original),
       'Hosting size estimator includes snapshot photo without double-counting');
verify($initialStorage['snapshots'] === 0, 'No snapshot existed before isolated test');

require_once $root . '/private/analytics_control.php';
$pdo->prepare('INSERT INTO visitor_stats_ip_exclusions (ip_address,admin_id) VALUES (?,?)')->execute([analytics_ip_key('192.0.2.77'),1]);
$name = snap_create($pdo);
verify(!$pdo->inTransaction(), 'Successful snapshot ends consistent read transaction before archiving photos');
verify(snap_name($name) && is_file(snap_path($name)), 'Snapshot created with safe filename');

$missingPhoto = 'uploads/2026/10/' . str_repeat('f',32) . '.jpg';
$pdo->prepare('INSERT INTO lot_images(lot_id,path,sort_order,source_name) VALUES (?,?,?,?)')
    ->execute([$lotId,$missingPhoto,99,'missing-test.jpg']);
assert_throws(fn() => snap_create($pdo), 'ontbrekende of ongeldige fotoverwijzing',
    'Snapshot rejects DB photo references without corresponding file');
$pdo->prepare('DELETE FROM lot_images WHERE path=?')->execute([$missingPhoto]);

$listing = snap_listing();
verify(count($listing) === 1 && $listing[0]['name'] === $name && $listing[0]['size'] > 0,
       'Snapshot listed with nonzero exact ZIP size');
[$snapshot, $hashes, $photoBytes] = snap_verified($name);
verify(!isset($snapshot['tables']['visitor_stats_ip_exclusions']) && !isset($snapshot['tables']['visitor_stats_ip_exclusion_state'])
    && !str_contains(json_encode($snapshot), '192.0.2.77'), 'IP/admin exclusions and their switch are absent from snapshot data');
verify(count($snapshot['tables']['lots']) === 1 && count($snapshot['tables']['admins']) === 1,
       'Snapshot contains all intended database records');
verify($snapshot['format']==='41lokeren-v4-artists' && count(array_filter($snapshot['tables']['lot_sellers'],static fn($s)=>(int)$s['id']===$sellerId))===1
  && (int)$snapshot['tables']['lots'][0]['seller_id']===$sellerId,
  'Snapshot v3 stores seller details and reference in one consistent archive');
verify($snapshot['tables']['lots'][0]['sale_location'] === 'veiling' && (int)$snapshot['tables']['lots'][0]['auction_number'] === 1,
       'Snapshot contains sale location and auction number');
verify(isset($hashes['uploads/'.$relative]) && $photoBytes === filesize($original),
       'Snapshot includes the exact original photo');
$zip = new ZipArchive();
if ($zip->open(snap_path($name)) !== true) throw new RuntimeException('Could not open snapshot ZIP');
verify($zip->locateName('database.json') !== false && $zip->locateName('manifest.json') !== false,
       'Snapshot ZIP contains database JSON and checksum manifest');
$zip->close();

$after = snap_storage_stats();
verify($after['snapshots'] === filesize(snap_path($name)) && $after['total'] > $initialStorage['total'],
       'Hosting total and snapshot subtotal increase after creation');

$lock = snap_lock();
try {
    assert_throws(fn() => snap_create($pdo), 'Er loopt al', 'Concurrent snapshot creation blocked by snapshot lock');
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

// Never silently inherit or commit a caller's transaction.
$pdo->beginTransaction();
try {
    assert_throws(fn() => snap_create($pdo), 'afzonderlijke databasetransactie',
                  'Snapshot refuses an already active caller transaction');
    verify($pdo->inTransaction(), 'Rejected snapshot leaves caller transaction in place');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}

$badName = 'snapshot-20990101-000000-deadbeefdead.zip';
$corrupted = snap_path($badName);
if (!copy(snap_path($name), $corrupted)) throw new RuntimeException('Could not copy ZIP for corruption test');
$bad = new ZipArchive();
if ($bad->open($corrupted) !== true) throw new RuntimeException('Cannot edit corrupted snapshot fixture');
$databaseJson = $bad->getFromName('database.json');
if ($databaseJson === false) throw new RuntimeException('Missing db JSON');
$bad->addFromString('database.json', $databaseJson . ' ');
$bad->close();
assert_throws(fn() => snap_verified($badName), 'checksum', 'Modified database is rejected by checksum validation');

$pdo->prepare('UPDATE lots SET title=? WHERE id=?')->execute(['Gewijzigde titel', $lotId]);
$pdo->exec("UPDATE settings SET title='Veranderde veiling'");
$pdo->exec("UPDATE admins SET email='changed-ci-admin@example.invalid', name='Ongeldige snapshotnaam'");
$pdo->exec('DELETE FROM lot_images WHERE lot_id=' . $lotId);
unlink($original);
file_put_contents($uploads . '/' . $extra, 'New data that is not in original snapshot');

// A token issued after the snapshot must never survive restoration.
$pendingTokenHash=hash('sha256', 'disposable-snapshot-invite');
$pdo->prepare("INSERT INTO admin_invitations (name,email,role,token_hash,created_at,expires_at)
 VALUES ('Snapshot invite','ci-snapshot-invite@example.invalid','assistant',?,UTC_TIMESTAMP(),
 DATE_ADD(UTC_TIMESTAMP(),INTERVAL 48 HOUR))")->execute([$pendingTokenHash]);
verify((int)$pdo->query("SELECT COUNT(*) FROM admin_invitations")->fetchColumn() === 1,
       'Pending invitation exists before restoring snapshot');
analytics_ip_set_exclusion($pdo,false);
$pdo->prepare('INSERT INTO visitor_stats_ip_exclusions (ip_address,admin_id) VALUES (?,?)')->execute([analytics_ip_key('2001:db8::77'),99]);
snap_restore($pdo, $name);
verify((int)$pdo->query('SELECT COUNT(*) FROM visitor_stats_ip_exclusions')->fetchColumn() === 2
    && !analytics_ip_exclusion_enabled($pdo), 'Restore preserves current IP/admin associations and switch instead of resetting them');
verify((int)$pdo->query("SELECT COUNT(*) FROM admin_invitations")->fetchColumn() === 0,
       'Snapshot restore invalidates pending invitation tokens');
verify($pdo->query("SELECT last_login_at FROM admins LIMIT 1")->fetchColumn() === null,
       'Snapshot restores nullable last-login timestamp');

verify(!restore_journal_pending(), 'Successful restore removes maintenance journal and has no lingering temporary journal');

verify((fileperms($uploads) & 0005) === 0005,
       'Restored uploads root is traversable by webserver');
verify((fileperms($uploads . '/2026/10') & 0005) === 0005,
       'Restored photo subdirectories are readable and traversable by webserver');

verify((int)$pdo->query('SELECT seller_id FROM lots WHERE lot_number=101')->fetchColumn()===$sellerId
    && (int)$pdo->query('SELECT COUNT(*) FROM lot_sellers')->fetchColumn()===1,
    'Restore preserves seller records and lot foreign keys');
verify($pdo->query('SELECT title FROM lots WHERE lot_number=101')->fetchColumn() === 'Kunstwerk voor snapshot',
       'Restore returns the original lot title');
$restoredLot=$pdo->query('SELECT sale_location,auction_number FROM lots WHERE lot_number=101')->fetch(PDO::FETCH_ASSOC);
verify($restoredLot['sale_location']==='veiling' && (int)$restoredLot['auction_number']===1,
       'Restore preserves original sale location and auction number');
verify($pdo->query('SELECT title FROM settings WHERE id=1')->fetchColumn() === 'CI catalogus',
       'Restore returns the original event settings');
verify($pdo->query('SELECT email FROM admins LIMIT 1')->fetchColumn() === $originalAdmin,
       'Restore returns the original administrator');
verify($pdo->query('SELECT name FROM admins LIMIT 1')->fetchColumn() === $originalAdminName,
       'Restore preserves administrator display name');
verify((int)$pdo->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn() === 1,
       'Restore returns login attempts');
verify((int)$pdo->query('SELECT COUNT(*) FROM lot_images WHERE lot_id='.$lotId)->fetchColumn() === 1,
       'Restore returns image database record and foreign key');
verify(is_file($original) && hash_file('sha256', $original) === $checksum,
       'Restore returns original photo content');
verify(!is_file($uploads.'/'.$extra), 'Restore removes images added after snapshot');

$afterRestore = snap_storage_stats();
verify($afterRestore['snapshots'] === filesize(snap_path($name)) + filesize($corrupted),
       'Storage breakdown counts both snapshots');
verify($afterRestore['free'] === max(0, $afterRestore['quota'] - $afterRestore['total']),
       'Estimated free space agrees with nominal quota');

assert_throws(fn() => snap_path('../config.php'), 'Ongeldige snapshotnaam',
              'Snapshot path traversal rejected');
@unlink($corrupted);

// Schema-upgrade regression: restore a snapshot created before is_site_online existed.
$pdo->exec('ALTER TABLE settings ADD COLUMN is_site_online TINYINT(1) NOT NULL DEFAULT 1');
$pdo->exec('UPDATE settings SET is_site_online=0');
snap_restore($pdo, $name);
verify((int)$pdo->query('SELECT is_site_online FROM settings WHERE id=1')->fetchColumn() === 1,
       'Legacy snapshot restores safely online after schema upgrade');

// Snapshot and restore the explicitly offline state on the upgraded schema.
$pdo->exec('UPDATE settings SET is_site_online=0');
$newSchemaSnapshot = snap_create($pdo);
[$newSchemaData] = snap_verified($newSchemaSnapshot);
verify((int)$newSchemaData['tables']['settings'][0]['is_site_online'] === 0,
       'New schema snapshot stores the offline state');
$pdo->exec('UPDATE settings SET is_site_online=1');
snap_restore($pdo, $newSchemaSnapshot);
verify((int)$pdo->query('SELECT is_site_online FROM settings WHERE id=1')->fetchColumn() === 0,
       'New schema restore recovers the offline state');
// Keep the original one-snapshot fixture expected by the following HTTP integration test.
if (!unlink(snap_path($newSchemaSnapshot))) throw new RuntimeException('Could not clean schema regression snapshot');
// Old snapshots are intentionally unsupported after this schema migration.
// Exercise the security regression in the existing isolated snapshot CI suite.
require __DIR__.'/snapshot_delete_security_test.php';
echo "Snapshot integration: {$count} checks passed on isolated MySQL/PHP.\n";
