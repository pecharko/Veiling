<?php
declare(strict_types=1);
/** 41 Lokeren snapshot engine; lives outside the public document root. */
require_once __DIR__ . '/catalog_mutex.php';
require_once __DIR__ . '/restore_journal.php';
require_once __DIR__ . '/visitor_geo_snapshot.php';
const SNAP_TABLES = ['settings','lot_categories','lot_sellers','lots','lot_images','seller_images'];
const SNAP_INSERT = [
 'settings'=>['id','title','subtitle','introduction','event_date','event_time','location','address','contact_email','practical_info','is_published','is_site_online','catalog_split_active','show_report_issue_link','show_artists_overview','updated_at'],
 'lot_categories'=>['id','name'],
 'lot_sellers'=>['id','seller_type','name','email','mobile_number','street','house_number','postal_code','municipality','bank_account_name','bank_account_iban','artist_name','artist_url','artist_intro','artist_slogan','artist_published','created_by_admin_id','created_by_name','created_at','updated_by_admin_id','updated_by_name','updated_at'],
 'lots'=>['id','lot_number','title','subtitle','category','description','details','estimate_min','estimate_max','market_price','starting_bid','auction_only','created_by_admin_id','status','sale_location','auction_number','seller_id','created_at','updated_at','last_edited_by_admin_id','last_edited_at'],
 'lot_images'=>['id','lot_id','path','sort_order','source_name','created_at'],
 'seller_images'=>['id','seller_id','image_kind','slot','path','created_at'],
];
function snap_dir(): string {return __DIR__.'/backups';}
function snap_uploads(): string {return dirname(__DIR__).'/catalogus-public/uploads';}
function snap_init(): void {
 if(!class_exists(ZipArchive::class)) throw new RuntimeException('PHP-extensie zip ontbreekt. Vraag OVHcloud om ZipArchive of gebruik een handmatige backup.');
 $d=snap_dir(); if(!is_dir($d) && !mkdir($d,0700,true)) throw new RuntimeException('Kan private/backups niet aanmaken.');
 if(!is_writable($d)) throw new RuntimeException('Map private/backups is niet schrijfbaar.');
}
function snap_name(string $s): bool {return (bool)preg_match('/^snapshot-20[0-9]{6}-[0-9]{6}-[a-f0-9]{12}\.zip$/D',$s);}
function snap_path(string $s): string {if(!snap_name($s))throw new RuntimeException('Ongeldige snapshotnaam.');return snap_dir().'/'.$s;}
/** Delete only a regular snapshot file inside the real private backup directory.
 * Caller must hold snap_lock(). Never follow symlinks for deletion.
 */
function snap_delete(string $name): void {
 if(!snap_name($name))throw new RuntimeException('Ongeldige snapshotnaam.');
 $dir=snap_dir();
 if(is_link($dir)||!is_dir($dir))throw new RuntimeException('Ongeldige snapshotmap.');
 $root=realpath($dir);
 if($root===false||$root!==$dir)throw new RuntimeException('Snapshotmap kan niet veilig worden gevalideerd.');
 $path=$root.DIRECTORY_SEPARATOR.$name;
 clearstatcache(true,$path);
 if(is_link($path)||!is_file($path)||realpath($path)!==$path)
  throw new RuntimeException('Snapshot niet gevonden of onveilig bestand.');
 if(!unlink($path))throw new RuntimeException('Kon snapshot niet verwijderen.');
}
function snap_listing(): array {
 $items=[]; if(!is_dir(snap_dir()))return [];
 foreach(new DirectoryIterator(snap_dir()) as $file){ if(!$file->isFile()||!snap_name($file->getFilename()))continue;$items[]=['name'=>$file->getFilename(),'size'=>$file->getSize(),'time'=>$file->getMTime()]; }
 usort($items,fn($a,$b)=>strcmp($b['name'],$a['name']));return $items;
}
function snap_assets(): array {
 $root=snap_uploads(); $result=[]; if(!is_dir($root))return $result;
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
 foreach($it as $file){if(!$file->isFile()||$file->isLink())continue;
  $rel=str_replace('\\','/',substr($file->getPathname(),strlen($root)+1));
  if(!preg_match('~^[0-9]{4}/[0-9]{2}/[a-f0-9]{32}\.(?:webp|jpg|png)$~D',$rel))continue;
  $result[$rel]=$file->getPathname();
 }
 ksort($result);return $result;
}
function snap_require_referenced_photos(array $tables, array $hashes): void {
 foreach(array_merge($tables['lot_images'] ?? [],$tables['seller_images'] ?? []) as $image){
  $path=$image['path'] ?? null;
  if(!is_string($path) || !preg_match('~^uploads/[0-9]{4}/[0-9]{2}/[a-f0-9]{32}\\.(webp|jpg|png)$~D',$path) || !isset($hashes[$path]))
   throw new RuntimeException('Snapshot bevat niet alle kavelfoto’s: ontbrekende of ongeldige fotoverwijzing.');
 }
}
function snap_lock(){ $fp=fopen(snap_dir().'/.operation.lock','c'); if(!$fp||!flock($fp,LOCK_EX|LOCK_NB))throw new RuntimeException('Er loopt al een backup- of herstelbewerking.');return $fp; }
/** Allow a simulated quota only through explicit internal calls (e.g. isolated CI tests). */
function snap_create(PDO $pdo,int $quotaBytes=1073741824): string {
 if($quotaBytes<=0)throw new InvalidArgumentException('Ongeldige hostinglimiet.');
 snap_init();$lock=snap_lock();$tmp=null;$catalogLocked=false;
 try{
  // Do not archive mixed or unverified data during an interrupted restore.
  restore_journal_require_clear();
  // Exclude every participating writer until the ZIP has been finalized.
  catalog_data_lock($pdo);$catalogLocked=true;
  // One InnoDB read view for ALL tables. Without this, concurrent commits can
  // make a snapshot contain old settings but newer lots or image references.
  // Our advisory mutex remains held while both DB rows and photos are archived.
  if($pdo->inTransaction())throw new RuntimeException('Snapshot vereist een nieuwe, afzonderlijke databasetransactie.');
  $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
  $pdo->beginTransaction();
  try{
   $rows=[];foreach(SNAP_TABLES as $table){$rows[$table]=$pdo->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);}
   $pdo->commit();
  }catch(Throwable $e){
   if($pdo->inTransaction())$pdo->rollBack();
   throw $e;
  }
  $assets=snap_assets(); $bytes=0;foreach($assets as $path)$bytes+=(int)filesize($path);
  if($bytes>350*1024*1024)throw new RuntimeException('Foto’s groter dan 350 MB. Maak eerst een handmatige kopie of vergroot hostingruimte.');
  $free=@disk_free_space(snap_dir());if(!snap_has_physical_capacity($free,$bytes))throw new RuntimeException('Te weinig vrije ruimte voor een snapshot.');
  // The catalog snapshot never captures visitor statistics, even when geo is installed.
  $format='41lokeren-v8-catalog-only';
  $payload=['format'=>$format,'created_at'=>gmdate('c'),'tables'=>$rows];
  $data=json_encode($payload,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
  if(strlen($data)>30*1024*1024)throw new RuntimeException('Snapshotdatabase te groot.');
  $space=snap_storage_stats($quotaBytes);
  if($space['warnings'])throw new RuntimeException('Opslaggebruik kan niet volledig worden bepaald; snapshot uit voorzorg geweigerd.');
  if(!snap_has_estimated_capacity($space,$bytes,strlen($data)))
   throw new RuntimeException('Onvoldoende geschatte hostingruimte voor deze snapshot (inclusief veiligheidsmarge). Download en verwijder eerst oude snapshots.');
  $name='snapshot-'.date('Ymd-His').'-'.bin2hex(random_bytes(6)).'.zip';$tmp=snap_dir().'/.building-'.bin2hex(random_bytes(8)).'.zip';
  $zip=new ZipArchive();if($zip->open($tmp,ZipArchive::CREATE|ZipArchive::EXCL)!==true)throw new RuntimeException('Kon ZIP niet aanmaken.');
  $hashes=[];
  try{
   $zip->addFromString('database.json',$data);$hashes['database.json']=hash('sha256',$data);
   foreach($assets as $relative=>$file){$entry='uploads/'.$relative;if(!$zip->addFile($file,$entry))throw new RuntimeException('Kon foto niet toevoegen: '.$relative);$hashes[$entry]=hash_file('sha256',$file);}
   snap_require_referenced_photos($rows,$hashes);
   $manifest=json_encode(['format'=>$format,'sha256'=>$hashes,'total_files'=>count($assets)],JSON_THROW_ON_ERROR);
   $zip->addFromString('manifest.json',$manifest);
  }finally{$zip->close();}
  if(!rename($tmp,snap_path($name)))throw new RuntimeException('Kon snapshot niet afronden.');$tmp=null;
  return $name;
 }finally{
  if($tmp&&is_file($tmp))@unlink($tmp);
  try{if($catalogLocked)catalog_data_unlock($pdo);}
  finally{flock($lock,LOCK_UN);fclose($lock);}
 }
}
function snap_verified(string $name): array {
 $path=snap_path($name);if(!is_file($path))throw new RuntimeException('Snapshot bestaat niet.');
 $zip=new ZipArchive();if($zip->open($path)!==true)throw new RuntimeException('ZIP onleesbaar.');
 try{
  $m=$zip->getFromName('manifest.json');$d=$zip->getFromName('database.json');
  if($m===false||$d===false||strlen($d)>30*1024*1024)throw new RuntimeException('Ongeldige snapshotinhoud.');
  $manifest=json_decode($m,true,512,JSON_THROW_ON_ERROR);$database=json_decode($d,true,512,JSON_THROW_ON_ERROR);
  if(($database['format']??null)!=='41lokeren-v8-catalog-only'||($manifest['format']??null)!==$database['format'])throw new RuntimeException('Niet-ondersteund snapshotformaat.');
  if(!isset($database['tables'])||array_keys($database['tables'])!==SNAP_TABLES)throw new RuntimeException('Onvolledige databasetabellen.');
  $hashes=$manifest['sha256']??null;if(!is_array($hashes)||count($hashes)>20000||!isset($hashes['database.json']))throw new RuntimeException('Ongeldige bestandslijst.');
  if(!hash_equals($hashes['database.json'],hash('sha256',$d)))throw new RuntimeException('Database checksum ongeldig.');
  // Never import historic analytics from a catalog snapshot, including former v2-geo files.
  if(array_key_exists('analytics',$database))throw new RuntimeException('Catalogussnapshot mag geen bezoekersstatistieken bevatten.');
  $size=0;
  foreach($hashes as $entry=>$hash){
   if($entry==='database.json')continue;
   if(!is_string($entry)||!preg_match('~^uploads/[0-9]{4}/[0-9]{2}/[a-f0-9]{32}\.(webp|jpg|png)$~D',$entry)||!preg_match('/^[a-f0-9]{64}$/D',(string)$hash))throw new RuntimeException('Ongeldige fotoverwijzing.');
   $st=$zip->statName($entry);if(!$st||$st['size']>12*1024*1024)throw new RuntimeException('Ontbrekende of te grote foto.');$size+=(int)$st['size'];
   if($size>350*1024*1024)throw new RuntimeException('Snapshot te groot.');
  }
  if($zip->numFiles!==count($hashes)+1)throw new RuntimeException('Snapshot bevat onverwachte bestanden.');
  snap_require_referenced_photos($database['tables'],$hashes);
  foreach(SNAP_TABLES as $t){if(!is_array($database['tables'][$t]))throw new RuntimeException('Ongeldige tabel '.$t);foreach($database['tables'][$t] as $row){if(!is_array($row)||array_diff(array_keys($row),SNAP_INSERT[$t])|| (array_diff(SNAP_INSERT[$t],array_keys($row)) && !($t==='settings' && !array_diff(array_diff(SNAP_INSERT[$t],array_keys($row)),['is_site_online','catalog_split_active','show_report_issue_link','show_artists_overview']))))throw new RuntimeException('Ongeldige kolommen bij '.$t);}}
  return [$database,$hashes,$size];
 }finally{$zip->close();}
}
/**
 * Move the complete prior photo tree atomically to the existing private
 * recovery-evidence location while the restore journal still blocks PHP.
 * Never recursively delete old photos before the journal has been cleared.
 */
function snap_preserve_previous_photos(string $old, array $journal): string {
 $runId=$journal['run_id']??null;
 $oldName=$journal['old_uploads']??null;
 if(!is_string($runId)||!preg_match('/^[a-f0-9]{32}$/D',$runId)
    ||!is_string($oldName)||!preg_match('/^\.uploads-before-restore-[a-f0-9]{16}$/D',$oldName)
    ||basename($old)!==$oldName||dirname($old)!==dirname(snap_uploads()))
   throw new RuntimeException('Ongeldige fotomap voor herstelbewijs.');
 $active=restore_journal_read();
 if($active['run_id']!==$runId||$active['phase']!=='database_committed')
   throw new RuntimeException('Databasecommit niet bevestigd in hersteljournal.');
 $private=restore_journal_dir();
 $preserved=$private.'/.restore-preserved-old-'.$runId;
 if(is_link($private)||!is_dir($private)||is_link($old)||!is_dir($old)
    ||file_exists($preserved)||is_link($preserved))
   throw new RuntimeException('Oude foto-evidence is onveilig of reeds aanwezig.');
 $oldStat=stat($old);$privateStat=stat($private);
 if($oldStat===false||$privateStat===false||$oldStat['dev']!==$privateStat['dev'])
   throw new RuntimeException('Oude foto-evidence kan niet atomair worden verplaatst.');
 if(!rename($old,$preserved))throw new RuntimeException('Kon vorige fotomap niet veilig bewaren.');
 return $preserved;
}
function snap_restore(PDO $pdo,string $name): void {
 snap_init();$lock=snap_lock();$stage=null;$old=null;$catalogLocked=false;
 $journal=null;$oldSaved=false;$newActive=false;$hadUploads=false;$preservedOld=null;
 $dbCommitted=false;$commitAttempted=false;
 try{
  restore_journal_require_clear();
  catalog_data_lock($pdo);$catalogLocked=true;
  [$database,$hashes,$size]=snap_verified($name);
  if(($database['format']??null)!=='41lokeren-v8-catalog-only')throw new RuntimeException('Oude snapshots worden na deze snapshotwijziging niet ondersteund.');
  $stage=dirname(snap_uploads()).'/.restore-'.bin2hex(random_bytes(8));
  if(!mkdir($stage,0700))throw new RuntimeException('Kan tijdelijke fotomap niet maken.');
  $zip=new ZipArchive();if($zip->open(snap_path($name))!==true)throw new RuntimeException('Kan snapshot niet openen.');
  try{foreach($hashes as $entry=>$hash){if($entry==='database.json')continue;
    $dest=$stage.'/'.substr($entry,strlen('uploads/'));$folder=dirname($dest);if(!is_dir($folder)&&!mkdir($folder,0700,true))throw new RuntimeException('Kan map niet maken.');
    $src=$zip->getStream($entry);$out=fopen($dest,'wb');if(!$src||!$out)throw new RuntimeException('Kon foto niet terugzetten.');
    $ctx=hash_init('sha256');while(!feof($src)){$part=fread($src,1024*1024);if($part===false)throw new RuntimeException('Leesfout in ZIP.');hash_update($ctx,$part);if(fwrite($out,$part)!==strlen($part))throw new RuntimeException('Schrijffout bij foto.');}fclose($out);fclose($src);
    if(!hash_equals($hash,hash_final($ctx)))throw new RuntimeException('Foto checksum ongeldig.');
   }}finally{$zip->close();}
  // Keep staging private until verification, then make the directory
  // hierarchy traversable for the HTTP server before activation.
  $directories=[$stage];
  $walk=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
  foreach($walk as $item)if($item->isDir()&&!$item->isLink())$directories[]=$item->getPathname();
  foreach($directories as $directory){
   if(!chmod($directory,0755))throw new RuntimeException('Kan leesrechten voor herstelde fotomap niet instellen.');
  }
  $uploads=snap_uploads();$hadUploads=is_dir($uploads);
  if(is_link($uploads))throw new RuntimeException('De actieve fotomap mag geen symlink zijn.');
  // Validate same-filesystem atomic preservation before modifying live photos.
  if($hadUploads){
   $uploadsStat=stat($uploads);$privateStat=stat(restore_journal_dir());
   if(is_link(restore_journal_dir())||$uploadsStat===false||$privateStat===false
      ||$uploadsStat['dev']!==$privateStat['dev'])
    throw new RuntimeException('Vereiste private foto-evidence ligt niet op hetzelfde bestandssysteem.');
  }
  $old=dirname($uploads).'/.uploads-before-restore-'.bin2hex(random_bytes(8));
  if($hadUploads){
    // The restored tree must retain existing webserver protections.
    if(is_file($uploads.'/.htaccess')&&!copy($uploads.'/.htaccess',$stage.'/.htaccess'))
      throw new RuntimeException('Kan foto-beveiligingsregel niet kopiëren.');
  }else{
    if(file_put_contents($stage.'/.htaccess',"Options -Indexes\n<FilesMatch \"(?i)\\.(?:php[0-9]*|phtml|phar|cgi|pl|sh|shtml|htaccess|sql|ini|env|log)$\">\nRequire all denied\n</FilesMatch>\n")===false)
      throw new RuntimeException('Kan foto-beveiligingsregel niet aanmaken.');
  }

  // Journal is atomically published BEFORE either photo-directory rename.
  // On SIGKILL it survives; bootstrap fails closed on subsequent PHP GETs.
  $journal=restore_journal_begin($name,$old,$stage,$hadUploads);
  if($hadUploads){
    if(!rename($uploads,$old))throw new RuntimeException('Kan huidige foto’s niet tijdelijk bewaren.');
    $oldSaved=true;
    restore_journal_phase($journal,'old_saved');
  }
  if(!rename($stage,$uploads))throw new RuntimeException('Kan nieuwe fotomap niet activeren.');$stage=null;
  $newActive=true;
  restore_journal_phase($journal,'photos_active');
  try{
   $pdo->beginTransaction();
   // Preserve live authentication state. Accounts, login history, invitations
   // and password resets are deliberately excluded from snapshot and restore.
   // Delete catalog children before parents; restore categories before lots.
   foreach(['lot_images','seller_images','lots','lot_sellers','lot_categories','settings'] as $table)
     $pdo->exec('DELETE FROM '.$table);
   foreach(SNAP_TABLES as $table){
    $columns=SNAP_INSERT[$table];
    if($table==='settings') {
     foreach(['is_site_online','catalog_split_active','show_report_issue_link','show_artists_overview'] as $optional) {
      if(!$pdo->query("SHOW COLUMNS FROM settings LIKE '".$optional."'")->fetch()) {
       $columns=array_values(array_filter($columns,fn($column)=>$column!==$optional));
      }
     }
    }
    $cols=implode(',',$columns);$marks=implode(',',array_fill(0,count($columns),'?'));
    $stmt=$pdo->prepare('INSERT INTO '.$table.' ('.$cols.') VALUES ('.$marks.')');
    foreach($database['tables'][$table] as $row)
     $stmt->execute(array_map(fn($col)=>$row[$col] ?? ($table==='settings' && in_array($col,['is_site_online','show_artists_overview'],true) ? 1 : ($table==='settings' && in_array($col,['catalog_split_active','show_report_issue_link'],true) ? 0 : null)), $columns));
   }
   // Keep permanent lot-number reservations monotonic across snapshot restores.
   // A restore may reintroduce lots deleted after the snapshot was taken.
   // Never clear the registry here: only the administrator's explicit full reset can.
   $pdo->exec('INSERT IGNORE INTO issued_lot_numbers (lot_number) SELECT lot_number FROM lots');
   // If commit throws, the outcome can be ambiguous. Keep maintenance on.
   $commitAttempted=true;
   if(!$pdo->commit())throw new RuntimeException('Databasecommit kon niet worden bevestigd.');
   $dbCommitted=true;
  }catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    throw $e;
  }
  restore_journal_phase($journal,'database_committed');
  if($oldSaved){$preservedOld=snap_preserve_previous_photos($old,$journal);$oldSaved=false;}
  // This clears maintenance only after the complete old tree was atomically
  // preserved in private; no recursive deletion is allowed before this point.
  restore_journal_finish();$journal=null;
  if($preservedOld!==null){
   try{snap_remove_tree($preservedOld);}
   catch(Throwable $cleanupError){
    // The restored live DB/photos are already consistent and maintenance
    // cleared; leave remaining private evidence for operator review.
    error_log('[41Lokeren restore] Private old-photo cleanup incomplete; review required.');
   }
  }
 }catch(Throwable $e){
  // Roll back only when SQL commit was definitely NOT attempted. If commit
  // outcome is unknown, do not guess between old and new data; fail closed.
  if($pdo->inTransaction())$pdo->rollBack();
  if($journal!==null&&!$dbCommitted&&!$commitAttempted){
    try{
      if($newActive){
        snap_remove_tree(snap_uploads());$newActive=false;
      }
      if($oldSaved){
        if(!rename($old,snap_uploads()))throw new RuntimeException('Oude fotomap kon niet veilig worden teruggezet.');
        $oldSaved=false;
      }
      restore_journal_finish();$journal=null;
    }catch(Throwable $rollbackError){
      error_log('[41Lokeren restore] Manual recovery required: '.$rollbackError->getMessage());
      // Leave the journal in place, and preserve remaining recovery assets.
    }
  }
  throw $e;
 }finally{
  // A pending journal is evidence needed for manual recovery. Never delete
  // staging or old photos until a verified safe state has been restored.
  if($journal===null&&$stage&&is_dir($stage))snap_remove_tree($stage);
  try{if($catalogLocked)catalog_data_unlock($pdo);}
  finally{flock($lock,LOCK_UN);fclose($lock);}
 }
}

function snap_remove_tree(string $dir): void {
 // A symlink at the root would make RecursiveDirectoryIterator traverse an
 // unrelated directory. Refuse before examining or deleting its children.
 if(is_link($dir))throw new RuntimeException('Symlink als fotomap is niet toegestaan.');
 if(!is_dir($dir))return;
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($it as $f){if($f->isDir()&&!$f->isLink())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($dir);
}

/** Hosting quota estimation, including all files reachable from the account root.
 * disk_free_space() on shared hosting may reflect shared volume, not the account quota.
 */
function snap_storage_stats(int $quotaBytes=1073741824): array {
 $root=dirname(__DIR__); $parts=['catalogus-public'=>0,'private'=>0,'www'=>0,'other'=>0];
 $count=0; $seen=[]; $warnings=[];
 try {
  $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::LEAVES_ONLY);
  foreach($it as $file){
   if($file->isLink()||!$file->isFile())continue;
   $path=$file->getPathname();
   $relative=substr($path,strlen($root)+1);
   $top=explode(DIRECTORY_SEPARATOR,$relative,2)[0];
   $section=isset($parts[$top])&&$top!=='other'?$top:'other';
   $size=$file->getSize(); if($size===false)continue;
   $parts[$section]+=(int)$size; $count++;
   if($count>80000){$warnings[]='Te veel bestanden om volledig te tellen; de schatting kan te laag zijn.';break;}
  }
 }catch(Throwable $e){$warnings[]='Niet alle bestanden konden gelezen worden. De schatting kan te laag zijn.';error_log('[41Lokeren storage] '.$e->getMessage());}
 $total=array_sum($parts);
 $backupBytes=0;foreach(snap_listing() as $item)$backupBytes+=(int)$item['size'];
 $uploadsBytes=0;foreach(snap_assets() as $path){$v=@filesize($path);if($v!==false)$uploadsBytes+=(int)$v;}
 $percent=$quotaBytes>0?($total/$quotaBytes*100):0;
 return ['quota'=>$quotaBytes,'total'=>$total,'free'=>max(0,$quotaBytes-$total),'percent'=>$percent,
  'parts'=>$parts,'snapshots'=>$backupBytes,'uploads'=>$uploadsBytes,'warnings'=>$warnings,'files'=>$count];
}
/** Shared disk-space guard: false means unknown and the estimated quota still applies. */
function snap_has_physical_capacity(int|float|false $diskFree,int $photoBytes): bool {
 if($photoBytes<0)return false;
 if($diskFree===false)return true;
 return $diskFree >= $photoBytes+20*1024*1024;
}
/** Consistent UI warning classification; no disk writes. */
function snap_storage_level(float $percent): string {
 if($percent>=90.0)return 'critical';
 if($percent>=80.0)return 'warning';
 return 'ok';
}
/** Estimated capacity preflight; fail closed when the file scan is incomplete. */
function snap_has_estimated_capacity(array $storage,int $photoBytes,int $databaseBytes): bool {
 if($photoBytes<0||$databaseBytes<0)return false;
 if(!empty($storage['warnings']))return false;
 $free=$storage['free']??null;
 if(!is_int($free)||$free<0)return false;
 return $free >= $photoBytes+$databaseBytes+20*1024*1024;
}

function snap_human_size(int|float $bytes): string {
 if($bytes>=1073741824)return number_format($bytes/1073741824,2,',','.').' GB';
 if($bytes>=1048576)return number_format($bytes/1048576,1,',','.').' MB';
 return number_format($bytes/1024,1,',','.').' KB';
}
