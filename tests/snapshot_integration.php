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
// Exercise the new protected-release adapter strictly offline inside CI's
// disposable snapshot test job; no workflow or historical release gate changes.
$offlineCommand = [
  'python3', '-B', '-m', 'unittest', 'discover', '-s', 'tests',
  '-p', 'deploy_artist_publication_snapshot_test.py', '-v'
];
$offlineProcess = proc_open(
  $offlineCommand,
  [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],
  $offlinePipes,
  $root
);
if (!is_resource($offlineProcess)) throw new RuntimeException('Offline snapshot release test could not start.');
fclose($offlinePipes[0]);
$offlineOutput = stream_get_contents($offlinePipes[1]);
$offlineErrors = stream_get_contents($offlinePipes[2]);
fclose($offlinePipes[1]);
fclose($offlinePipes[2]);
$offlineExit = proc_close($offlineProcess);
echo (string)$offlineOutput, (string)$offlineErrors;
if ($offlineExit !== 0) throw new RuntimeException('Offline artist publication release adapter checks failed.');
$workflowCommand = [
  'python3', '-B', '-m', 'unittest', 'discover', '-s', 'tests',
  '-p', 'artist_publication_snapshot_workflow_test.py', '-v'
];
$workflowProcess = proc_open(
  $workflowCommand,
  [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],
  $workflowPipes,
  $root
);
if (!is_resource($workflowProcess)) throw new RuntimeException('Offline workflow test could not start.');
fclose($workflowPipes[0]);
$workflowOutput = stream_get_contents($workflowPipes[1]);
$workflowErrors = stream_get_contents($workflowPipes[2]);
fclose($workflowPipes[1]);
fclose($workflowPipes[2]);
$workflowExit = proc_close($workflowProcess);
echo (string)$workflowOutput, (string)$workflowErrors;
if ($workflowExit !== 0) throw new RuntimeException('Artist publication workflow security contracts failed.');


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
$pdo->exec("INSERT INTO lot_categories (name) VALUES ('CI Eenmalige categorie')");
$categoryId=(int)$pdo->lastInsertId();
$pdo->prepare('UPDATE lots SET category=? WHERE id=?')->execute(['CI Eenmalige categorie',$lotId]);
$pdo->prepare("UPDATE lots SET last_edited_by_admin_id=1,last_edited_at='2026-11-11 07:30:00' WHERE id=?")->execute([$lotId]);
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
$sellerRelative='2026/10/'.str_repeat('c',32).'.jpg';
$sellerPhoto=$uploads.'/'.$sellerRelative;
if (!copy($original,$sellerPhoto)) throw new RuntimeException('Cannot create synthetic artist photo');
$pdo->prepare("UPDATE lot_sellers SET artist_intro='Fictieve maker uit Lokeren', artist_slogan='Kunst met karakter', artist_instagram_url='https://www.instagram.com/testmaker/', artist_published=1, updated_by_admin_id=1, updated_by_name='CI Beheerder', updated_at='2026-11-11 07:45:00' WHERE id=?")->execute([$sellerId]);
$pdo->prepare("INSERT INTO seller_images (seller_id,image_kind,slot,path) VALUES (?,'work',1,?)")
  ->execute([$sellerId,'uploads/'.$sellerRelative]);
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
verify(count($snapshot['tables']['lots'])===1 && !isset($snapshot['tables']['admins'])
    && !isset($snapshot['tables']['login_attempts'])
    && count($snapshot['tables']['lot_categories'])===3
    && count(array_filter($snapshot['tables']['lot_categories'],
      static fn($cat)=>(int)$cat['id']===$categoryId && $cat['name']==='CI Eenmalige categorie'))===1
    && !str_contains(json_encode($snapshot),'ci-admin@example.invalid'),
    'V8 snapshot exports catalog categories and excludes administrator and login records');
verify($snapshot['format']==='41lokeren-v8-catalog-only' && count(array_filter($snapshot['tables']['lot_sellers'],static fn($s)=>(int)$s['id']===$sellerId && $s['artist_intro']==='Fictieve maker uit Lokeren' && $s['artist_slogan']==='Kunst met karakter' && $s['artist_instagram_url']==='https://www.instagram.com/testmaker/' && (int)$s['artist_published']===1))===1
  && count(array_filter($snapshot['tables']['seller_images'],static fn($row)=>(int)$row['seller_id']===$sellerId && $row['image_kind']==='work'))===1
  && (int)$snapshot['tables']['lots'][0]['seller_id']===$sellerId
  && (int)$snapshot['tables']['lots'][0]['last_edited_by_admin_id']===1
  && $snapshot['tables']['lots'][0]['last_edited_at']==='2026-11-11 07:30:00',
  'Snapshot v8 stores linked sellers and last lot editor');
verify($snapshot['tables']['lots'][0]['sale_location'] === 'veiling' && (int)$snapshot['tables']['lots'][0]['auction_number'] === 1,
       'Snapshot contains sale location and auction number');
verify(isset($hashes['uploads/'.$sellerRelative]) && is_file($sellerPhoto),
  'Snapshot includes one referenced artist work photo');
verify(isset($hashes['uploads/'.$relative]) && $photoBytes === filesize($original)+filesize($sellerPhoto),
       'Snapshot includes the exact original photo');
$zip = new ZipArchive();
if ($zip->open(snap_path($name)) !== true) throw new RuntimeException('Could not open snapshot ZIP');
verify($zip->locateName('database.json') !== false && $zip->locateName('manifest.json') !== false,
       'Snapshot ZIP contains database JSON and checksum manifest');
$zip->close();

// A ZIP with an internally valid CRC can still contain photo bytes that do
// not match the SHA-256 manifest. All destructive restore activity must refuse
// the archive before renaming the active uploads tree.
$badPhotoName='snapshot-20990102-000000-deadbeefdead.zip';
$badPhotoPath=snap_path($badPhotoName);
if(!copy(snap_path($name),$badPhotoPath))
    throw new RuntimeException('Cannot create disposable photo-corruption fixture');
$badPhotoZip=new ZipArchive();
if($badPhotoZip->open($badPhotoPath)!==true)
    throw new RuntimeException('Cannot modify disposable photo fixture');
$photoEntry='uploads/'.$relative;
$photoBytes=$badPhotoZip->getFromName($photoEntry);
if($photoBytes===false)throw new RuntimeException('Missing fixture photo in ZIP');
if(!$badPhotoZip->addFromString($photoEntry,$photoBytes.'CI modified photo bytes'))
    throw new RuntimeException('Cannot add edited photo ZIP fixture');
if(!$badPhotoZip->close())throw new RuntimeException('Cannot close edited photo ZIP fixture');
try{
    assert_throws(fn()=>snap_verified($badPhotoName),'Foto checksum',
        'T-061 rejects CRC-valid ZIP with photo SHA-256 different from manifest');
    assert_throws(fn()=>snap_restore($pdo,$badPhotoName),'Foto checksum',
        'T-061 refuses tampered photo ZIP before any DB or upload directory swap');
    verify(!restore_journal_pending() && is_file($original)
        && is_file($sellerPhoto) && hash_file('sha256',$original)===$checksum,
        'T-061 corrupt archive cannot enter maintenance or alter live fixture photos');
}finally{
    if(is_file($badPhotoPath))unlink($badPhotoPath);
}

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
 
// Construct an authentic-looking, checksum-consistent older format in a
// disposable ZIP. Existing customer snapshots remain untouched.
$legacyName='snapshot-20990103-000000-deadbeefdead.zip';
$legacyPath=snap_path($legacyName);
if (!copy(snap_path($name),$legacyPath)) throw new RuntimeException('Cannot create disposable legacy fixture');
$legacyZip=new ZipArchive();
if($legacyZip->open($legacyPath)!==true)throw new RuntimeException('Cannot edit legacy fixture');
$legacyPayload=json_decode((string)$legacyZip->getFromName('database.json'),true,512,JSON_THROW_ON_ERROR);
$legacyManifest=json_decode((string)$legacyZip->getFromName('manifest.json'),true,512,JSON_THROW_ON_ERROR);
$legacyPayload['format']='41lokeren-v7-lot-audit';
$legacyManifest['format']='41lokeren-v7-lot-audit';
$legacyDatabaseJson=json_encode($legacyPayload,JSON_THROW_ON_ERROR);
$legacyManifest['sha256']['database.json']=hash('sha256',$legacyDatabaseJson);
$legacyZip->addFromString('database.json',$legacyDatabaseJson);
$legacyZip->addFromString('manifest.json',json_encode($legacyManifest,JSON_THROW_ON_ERROR));
$legacyZip->close();
assert_throws(fn()=>snap_verified($legacyName),'Niet-ondersteund snapshotformaat',
    'Older v7 snapshot is refused even when its archive checksums are valid');
assert_throws(fn()=>snap_restore($pdo,$legacyName),'Niet-ondersteund snapshotformaat',
    'Older v7 snapshot cannot enter the restore photo or database swap');
verify(!restore_journal_pending() && is_file($original) && is_file($sellerPhoto),
    'Rejected v7 restore preserves the active photo tree and leaves no maintenance journal');
verify(snap_verified($name)[0]['format']==='41lokeren-v8-catalog-only',
    'Rejected old format does not modify the current v8 snapshot');
if(!unlink($legacyPath))throw new RuntimeException('Cannot delete disposable legacy fixture');


$pdo->prepare('UPDATE lots SET title=? WHERE id=?')->execute(['Gewijzigde titel', $lotId]);
$pdo->prepare('UPDATE lot_sellers SET artist_published=0 WHERE id=?')->execute([$sellerId]);
$pdo->exec("UPDATE settings SET title='Veranderde veiling'");
$pdo->exec("UPDATE admins SET email='changed-ci-admin@example.invalid', name='Live beheerder behouden'");
$pdo->prepare('INSERT INTO login_attempts (ip_hash) VALUES (?)')
    ->execute([hash('sha256','post-snapshot-test-ip')]);
$pdo->prepare('UPDATE lot_categories SET name=? WHERE id=?')
    ->execute(['CI Later gewijzigde categorie',$categoryId]);
$pdo->exec("INSERT INTO lot_categories (name) VALUES ('CI Categorie na snapshot')");
$pdo->exec('DELETE FROM lot_images WHERE lot_id=' . $lotId);
unlink($original);
unlink($sellerPhoto);
file_put_contents($uploads . '/' . $extra, 'New data that is not in original snapshot');

// Invitations issued after a catalog snapshot must remain valid after restoration.
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
verify((int)$pdo->query("SELECT COUNT(*) FROM admin_invitations")->fetchColumn() === 1,
       'Catalog restore preserves current pending admin invitation');
verify($pdo->query("SELECT email FROM admins LIMIT 1")->fetchColumn() === 'changed-ci-admin@example.invalid'
    && $pdo->query("SELECT name FROM admins LIMIT 1")->fetchColumn() === 'Live beheerder behouden',
    'Catalog restore preserves current administrators and login credentials');
verify((int)$pdo->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn() === 2,
    'Catalog restore preserves login attempts created after the snapshot');

verify(!restore_journal_pending(), 'Successful restore removes maintenance journal and has no lingering temporary journal');
verify((glob(snap_dir().'/.restore-preserved-old-*') ?: []) === [],
       'Successful restore cleans prior private photos after the journal has cleared');

verify((fileperms($uploads) & 0005) === 0005,
       'Restored uploads root is traversable by webserver');
verify((fileperms($uploads . '/2026/10') & 0005) === 0005,
       'Restored photo subdirectories are readable and traversable by webserver');

verify(is_file($sellerPhoto)
  && $pdo->query('SELECT artist_intro FROM lot_sellers WHERE id='.$sellerId)->fetchColumn()==='Fictieve maker uit Lokeren'
  && $pdo->query('SELECT artist_slogan FROM lot_sellers WHERE id='.$sellerId)->fetchColumn()==='Kunst met karakter'
  && $pdo->query('SELECT artist_instagram_url FROM lot_sellers WHERE id='.$sellerId)->fetchColumn()==='https://www.instagram.com/testmaker/'
  && (int)$pdo->query('SELECT artist_published FROM lot_sellers WHERE id='.$sellerId)->fetchColumn()===1
  && (int)$pdo->query('SELECT COUNT(*) FROM seller_images WHERE seller_id='.$sellerId)->fetchColumn()===1,
  'Snapshot restores published artist status, introduction and work photo');
verify((int)$pdo->query('SELECT last_edited_by_admin_id FROM lots WHERE lot_number=101')->fetchColumn()===1
  && $pdo->query('SELECT last_edited_at FROM lots WHERE lot_number=101')->fetchColumn()==='2026-11-11 07:30:00',
  'Snapshot restores effective lot audit editor and timestamp');
verify((int)$pdo->query('SELECT updated_by_admin_id FROM lot_sellers WHERE id='.$sellerId)->fetchColumn()===1
  && $pdo->query('SELECT updated_by_name FROM lot_sellers WHERE id='.$sellerId)->fetchColumn()==='CI Beheerder'
  && $pdo->query('SELECT updated_at FROM lot_sellers WHERE id='.$sellerId)->fetchColumn()==='2026-11-11 07:45:00',
  'Snapshot preserves seller audit attribution and timestamp');
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
verify($pdo->query('SELECT name FROM lot_categories WHERE id='.$categoryId)->fetchColumn() === 'CI Eenmalige categorie'
    && $pdo->query('SELECT category FROM lots WHERE lot_number=101')->fetchColumn() === 'CI Eenmalige categorie'
    && (int)$pdo->query("SELECT COUNT(*) FROM lot_categories WHERE name='CI Categorie na snapshot'")->fetchColumn()===0,
    'Restore returns exactly the archived category registry, removing later changes without affecting users');
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

// Exercise the exact artist overview migration and the new switch in isolated MySQL.
// Older snapshot archives are not rewritten or deleted by this schema change.
$migrationSql=file_get_contents($root.'/database/migrations/20261116_artist_overview_publication.sql');
if(!is_string($migrationSql))throw new RuntimeException('Artist overview migration missing');
$pdo->exec($migrationSql);
verify((int)$pdo->query('SELECT show_artists_overview FROM settings WHERE id=1')->fetchColumn()===1,
       'Additive artist overview migration defaults to visible');
$pdo->exec('UPDATE settings SET show_artists_overview=0');
$artistOverviewSnapshot=snap_create($pdo);
[$artistOverviewData]=snap_verified($artistOverviewSnapshot);
verify((int)$artistOverviewData['tables']['settings'][0]['show_artists_overview']===0,
       'New snapshot verifies and stores disabled artist overview');
$pdo->exec('UPDATE settings SET show_artists_overview=1');
snap_restore($pdo,$artistOverviewSnapshot);
verify((int)$pdo->query('SELECT show_artists_overview FROM settings WHERE id=1')->fetchColumn()===0,
       'Restore recovers disabled artist overview switch');
$pdo->exec('UPDATE settings SET show_artists_overview=1');
$artistOverviewOnSnapshot=snap_create($pdo);
[$artistOverviewOnData]=snap_verified($artistOverviewOnSnapshot);
verify((int)$artistOverviewOnData['tables']['settings'][0]['show_artists_overview']===1,
       'New snapshot verifies and stores enabled artist overview');
$pdo->exec('UPDATE settings SET show_artists_overview=0');
snap_restore($pdo,$artistOverviewOnSnapshot);
verify((int)$pdo->query('SELECT show_artists_overview FROM settings WHERE id=1')->fetchColumn()===1,
       'Restore recovers enabled artist overview switch');
// Both homepage switches survive snapshots. Archives from before the migration
// restore the original behavior with both home sections visible.
$homepageSwitchMigration = file_get_contents($root.'/database/migrations/20261122_homepage_recruitment_visibility.sql');
if (!is_string($homepageSwitchMigration)) throw new RuntimeException('Homepage switch migration missing');
$pdo->exec($homepageSwitchMigration);
$homepageVisibility = static function () use ($pdo): array {
    $row = $pdo->query('SELECT show_artist_recruitment, show_artwork_recruitment FROM settings WHERE id=1')->fetch();
    return [(int)$row['show_artist_recruitment'], (int)$row['show_artwork_recruitment']];
};
verify($homepageVisibility() === [1, 1], 'Homepage section migration defaults both sections to visible');
$pdo->exec('UPDATE settings SET show_artist_recruitment=0, show_artwork_recruitment=1 WHERE id=1');
$homepageSnapshot = snap_create($pdo);
$pdo->exec('UPDATE settings SET show_artist_recruitment=1, show_artwork_recruitment=0 WHERE id=1');
snap_restore($pdo, $homepageSnapshot);
verify($homepageVisibility() === [0, 1], 'Restore preserves both homepage section switches independently');
snap_restore($pdo, $artistOverviewOnSnapshot);
verify($homepageVisibility() === [1, 1], 'Legacy snapshots restore with both homepage sections visible');
if (!unlink(snap_path($homepageSnapshot))) throw new RuntimeException('Could not clean isolated homepage test snapshot');

foreach([$artistOverviewSnapshot,$artistOverviewOnSnapshot] as $testArchive) {
    if(!unlink(snap_path($testArchive)))throw new RuntimeException('Could not clean isolated artist overview test snapshot');
}
// Keep the original one-snapshot fixture expected by the following HTTP integration test.
if (!unlink(snap_path($newSchemaSnapshot))) throw new RuntimeException('Could not clean schema regression snapshot');
// Old snapshots are intentionally unsupported; no existing ZIP is modified.
// A symlink supplied as the root of a recursive cleanup must never make
// RecursiveDirectoryIterator walk or erase its target, even within the CI fixture.
$protectedDir=snap_dir().'/.ci-protected-photos-'.bin2hex(random_bytes(6));
$symlinkRoot=snap_dir().'/.ci-photo-root-link-'.bin2hex(random_bytes(6));
if(!mkdir($protectedDir,0700))throw new RuntimeException('Cannot set up protected-photo CI directory');
$protectedMarker=$protectedDir.'/keep.txt';
try{
 if(file_put_contents($protectedMarker,'unaltered')===false
    || !symlink($protectedDir,$symlinkRoot))
  throw new RuntimeException('Cannot set up symlink-root CI fixture');
 assert_throws(fn()=>snap_remove_tree($symlinkRoot),'Symlink als fotomap',
               'Recursive cleanup rejects symlinked root instead of traversing target');
 verify(is_link($symlinkRoot) && is_file($protectedMarker)
    && file_get_contents($protectedMarker)==='unaltered',
    'Symlink cleanup rejection preserves all target bytes');
}finally{
 if(is_link($symlinkRoot))unlink($symlinkRoot);
 if(is_file($protectedMarker))unlink($protectedMarker);
 if(is_dir($protectedDir))rmdir($protectedDir);
}
// Exercise the security regression in the existing isolated snapshot CI suite.
require __DIR__.'/snapshot_delete_security_test.php';
// Test cleanup, only in the isolated auction_test database. The catalog
// restore itself must never mutate these authentication records.
$pdo->prepare('UPDATE admins SET email=?,name=? WHERE id=1')
    ->execute([$originalAdmin,$originalAdminName]);
$pdo->prepare('DELETE FROM login_attempts WHERE ip_hash=?')
    ->execute([hash('sha256','post-snapshot-test-ip')]);
$pdo->prepare('DELETE FROM admin_invitations WHERE token_hash=?')
    ->execute([$pendingTokenHash]);
echo "Snapshot integration: {$count} checks passed on isolated MySQL/PHP.\n";
