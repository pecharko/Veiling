<?php
declare(strict_types=1);
require_once __DIR__ . '/lot_bank.php';
require_once __DIR__ . '/lot_artist.php';
require_once __DIR__ . '/seller_mobile.php';
require_once __DIR__ . '/seller_audit.php';
require_once __DIR__ . '/photo_delete_safety.php';

/** Seller records contain personal and banking information: never use on public pages. */
function seller_can_manage(string $role): bool { return in_array($role,['administrator','veilingmeester','assistant'],true); }
/** Artist publication is distinct from ordinary seller editing and is never an assistant right. */
function seller_can_publish_artist(string $role): bool {
  return in_array($role,['administrator','veilingmeester'],true);
}
/** Only a stored, safe, physically available artist portrait permits publication. */
function seller_artist_has_portrait_path(string $path): bool {
  return preg_match('~^uploads/[0-9]{4}/[0-9]{2}/[a-f0-9]{32}\\.(?:webp|jpg|png)$~D',$path)===1
    && is_file(dirname(__DIR__).'/catalogus-public/'.$path);
}

/**
 * Publication-readiness indicator for an artist, independent of live publication status.
 * A missing stored portrait always takes precedence over profile completeness.
 * Values are kept stable for the artist-list filter and for regression tests.
 */
function seller_artist_publication_readiness(array $seller,bool $hasPortrait,bool $hasWork): string {
  if (!$hasPortrait) return 'no_portrait';
  if (trim((string)($seller['artist_slogan']??''))==='' || trim((string)($seller['artist_intro']??''))==='') return 'missing_text';
  return $hasWork ? 'complete' : 'texts_ready';
}

/** Caller validates CSRF and acquires the catalog lock; only an artist may be published. */
function seller_set_artist_publication(PDO $pdo,int $sellerId,int $status,string $role,int $actorId): void {
  if ($sellerId<1 || $actorId<1 || !in_array($status,[0,1,2],true) || !seller_can_publish_artist($role)) {
    throw new InvalidArgumentException('Geen rechten om kunstenaars te publiceren.');
  }
  $pdo->beginTransaction();
  try {
    $st=$pdo->prepare('SELECT seller_type,artist_published FROM lot_sellers WHERE id=? FOR UPDATE');
    $st->execute([$sellerId]);
    $seller=$st->fetch(PDO::FETCH_ASSOC);
    if (!$seller || $seller['seller_type']!=='kunstenaar') throw new InvalidArgumentException('Kunstenaar niet gevonden.');
    if ($status===1) {
      $portraitQuery=$pdo->prepare("SELECT path FROM seller_images WHERE seller_id=? AND image_kind='portrait' AND slot=1");
      $portraitQuery->execute([$sellerId]);
      $portrait=$portraitQuery->fetchColumn();
      if (!is_string($portrait) || !seller_artist_has_portrait_path($portrait)) {
        throw new InvalidArgumentException('Publiceren kan alleen met een opgeslagen portretfoto van de kunstenaar.');
      }
    }
    $publicationChanged=(int)$seller['artist_published']!==$status;
    if ($publicationChanged) {
      $name=seller_actor_name($pdo,$actorId);
      $update=$pdo->prepare('UPDATE lot_sellers SET artist_published=?,updated_by_admin_id=?,updated_by_name=?,updated_at=UTC_TIMESTAMP() WHERE id=?');
      $update->execute([$status,$actorId,$name,$sellerId]);
      if ($status===1) {
        $pdo->prepare('INSERT INTO artist_publication_mail_queue (seller_id) VALUES (?)')->execute([$sellerId]);
      } else {
        $pdo->prepare('DELETE FROM artist_publication_mail_queue WHERE seller_id=?')->execute([$sellerId]);
      }
    }
    $pdo->commit();
  } catch(Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $ex;
  }
  if ($publicationChanged && $status===1) {
    seller_send_pending_artist_publication_mail($pdo);
  }
}

function seller_artist_publication_mail_enabled(PDO $pdo): bool {
  $enabled=$pdo->query('SELECT enabled FROM artist_publication_mail_settings WHERE id=1')->fetchColumn();
  return $enabled===false || (int)$enabled===1;
}

/** Queue a profile change inside its transaction, under the catalogue lock. */
function seller_queue_artist_profile_update(PDO $pdo, int $sellerId): void {
  // A pending publication takes precedence. Any pending mail links to the latest profile.
  $stmt=$pdo->prepare("INSERT INTO artist_publication_mail_queue (seller_id,is_profile_update)
    SELECT s.id,1 FROM lot_sellers s
    WHERE s.id=? AND s.seller_type='kunstenaar' AND s.artist_published=1
      AND NOT EXISTS (SELECT 1 FROM artist_publication_mail_queue q WHERE q.seller_id=s.id)");
  $stmt->execute([$sellerId]);
}

/** Called after saving public settings or committing artist changes, under the catalogue lock. */
function seller_send_pending_artist_publication_mail(PDO $pdo, ?callable $send=null, ?int $onlySellerId=null): void {
  try {
    $settings=$pdo->query('SELECT is_site_online,show_artists_overview FROM settings WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    if ((int)($settings['is_site_online']??0)!==1 || (int)($settings['show_artists_overview']??0)!==1) return;
    if (!seller_artist_publication_mail_enabled($pdo)) return;
    if ($send===null && (getenv('LOCAL_PREVIEW_ISOLATED')==='1'
        || getenv('GITHUB_ACTIONS')==='true'
        || in_array(cfg('setup_key'),['local-preview-only-nonproduction','ci-test-only-nonproduction'],true))) return;

    $pending=$pdo->prepare("SELECT q.id AS queue_id,s.id,s.artist_name,s.email,q.is_profile_update FROM artist_publication_mail_queue q
      JOIN lot_sellers s ON s.id=q.seller_id
      WHERE s.seller_type='kunstenaar' AND s.artist_published=1 AND TRIM(s.artist_name)<>''
        AND (? IS NULL OR s.id=?)");
    $pending->execute([$onlySellerId,$onlySellerId]);
    $remove=$pdo->prepare('DELETE FROM artist_publication_mail_queue WHERE id=?');
    $record=$pdo->prepare('INSERT INTO artist_publication_mail_history (artist_name,is_profile_update,sent_at) VALUES (?,?,?)');
    $prune=$pdo->prepare('DELETE FROM artist_publication_mail_history WHERE id<?');
    foreach ($pending->fetchAll(PDO::FETCH_ASSOC) as $artist) {
      $pdo->beginTransaction();
      $remove->execute([$artist['queue_id']]);
      if ($remove->rowCount()!==1 || !seller_send_artist_publication_mail(
        (int)$artist['id'],(string)$artist['artist_name'],(string)$artist['email'],$send,(int)$artist['is_profile_update']===1
      )) {
        $pdo->rollBack();
        continue;
      }
      $record->execute([$artist['artist_name'],$artist['is_profile_update'],gmdate('Y-m-d H:i:s')]);
      $oldestKept=$pdo->query('SELECT id FROM artist_publication_mail_history ORDER BY id DESC LIMIT 1 OFFSET 9')->fetchColumn();
      if ($oldestKept!==false) $prune->execute([$oldestKept]);
      $pdo->commit();
    }
  } catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Artist publication notification queue failed.');
  }
}

/** Send only after the change commits. Mail failure leaves the saved change intact. */
function seller_send_artist_publication_mail(int $sellerId, string $artistName, string $email, ?callable $send=null, bool $isProfileUpdate=false): bool {
  if ($send===null && (getenv('LOCAL_PREVIEW_ISOLATED')==='1'
      || getenv('GITHUB_ACTIONS')==='true'
      || in_array(cfg('setup_key'),['local-preview-only-nonproduction','ci-test-only-nonproduction'],true))) return false;
  $email=trim($email);
  if (!filter_var($email,FILTER_VALIDATE_EMAIL)) return false;

  $link='https://41lokeren.be/kunstenaar.php?id='.$sellerId;
  $greeting=trim($artistName)==='' ? 'Dag,' : 'Dag '.trim($artistName).',';
  $message=$greeting."\n\n"
    ."Je kunstenaarspagina staat online op de website van de kunstveiling van 41 Lokeren. Bekijk je persoonlijke pagina hier:\n"
    .$link."\n\n"
    ."Deel je pagina met je vrienden, familie en volgers. Laat hen jouw werk ontdekken en nodig hen uit op onze kunstveiling voor het goede doel. Klik op ‘Deel deze kunstenaar’ op je pagina om de link meteen door te sturen of op je sociale media te plaatsen.\n\n"
    ."Wil je iets laten aanpassen aan je pagina? Neem contact op met je buddy of mail naar veiling@41lokeren.be. We brengen het zo snel mogelijk in orde.\n\n"
    ."Bedankt dat je meedoet!\n\n"
    ."Hartelijke groeten,\nHet veilingteam van 41 Lokeren\n";
  $subject='Je kunstenaarspagina staat online';
  if ($isProfileUpdate) {
    $subject='Je kunstenaarspagina is bijgewerkt';
    $message=$greeting."\n\n"
      ."We hebben je kunstenaarspagina op de website van de kunstveiling van 41 Lokeren bijgewerkt. Bekijk je pagina even en controleer of alles klopt:\n"
      .$link."\n\n"
      ."Heb je opmerkingen of wil je nog iets laten aanpassen? Neem contact op met je buddy of mail naar veiling@41lokeren.be. We brengen het zo snel mogelijk in orde.\n\n"
      ."Hartelijke groeten,\nHet veilingteam van 41 Lokeren\n";
  }
  $headers=[
    'From'=>'41 Lokeren <noreply@41lokeren.be>',
    'Reply-To'=>'veiling@41lokeren.be',
    'Content-Type'=>'text/plain; charset=UTF-8',
    'Auto-Submitted'=>'auto-generated',
  ];
  $send??='mail';
  try {
    if ($send($email,$subject,$message,$headers)) return true;
  } catch (Throwable $ex) {
    // Do not expose addresses, content or transport details.
  }
  error_log('Artist publication notification failed.');
  return false;
}

/** Only creator ownership gives assistants seller edit/delete rights. */
function seller_can_edit(string $role,int $actorId,array $seller): bool {
  if (!seller_can_manage($role) || $actorId<=0) return false;
  if ($role==='administrator' || $role==='veilingmeester') return true;
  // Even the creator loses all edit, photo and delete rights once published.
  if (($seller['seller_type']??'')==='kunstenaar' && (int)($seller['artist_published']??0)===1) return false;
  return isset($seller['created_by_admin_id']) && (int)$seller['created_by_admin_id']===$actorId;
}
function seller_require_edit(PDO $pdo,int $sellerId,string $role,int $actorId): array {
  if ($sellerId<1) throw new InvalidArgumentException('Verkoper niet gevonden.');
  $st=$pdo->prepare('SELECT * FROM lot_sellers WHERE id=?');
  $st->execute([$sellerId]);
  $seller=$st->fetch(PDO::FETCH_ASSOC);
  if (!$seller) { http_response_code(404); exit('Verkoper niet gevonden.'); }
  if (!seller_can_edit($role,$actorId,$seller)) {
    http_response_code(403);
    exit('Geen bewerkingsrechten voor deze verkoper.');
  }
  return $seller;
}

function seller_audit_time(?string $utc): string {
  if ($utc===null || $utc==='') return 'Niet geregistreerd';
  $when=new DateTimeImmutable($utc,new DateTimeZone('UTC'));
  return $when->setTimezone(new DateTimeZone('Europe/Brussels'))->format('d/m/Y H:i');
}


function seller_clean(mixed $input): array {
  if (!is_array($input)) throw new InvalidArgumentException('Ongeldige verkopersgegevens.');
  $types = ['kunstenaar','particulier'];
  $type = $input['seller_type'] ?? '';
  if (!is_string($type) || !in_array($type, $types, true)) throw new InvalidArgumentException('Kies kunstenaar of particulier.');
  $limits = ['name'=>150,'email'=>254,'mobile_number'=>30,'street'=>150,'house_number'=>20,'postal_code'=>20,'municipality'=>120,'bank_account_name'=>100,'bank_account_iban'=>42,'artist_name'=>50,'artist_url'=>2048,'artist_instagram_url'=>2048,'artist_intro'=>500,'artist_slogan'=>250];
  $fields = [];
  foreach ($limits as $key=>$limit) {
    $value=$input[$key] ?? '';
    if (!is_string($value)) throw new InvalidArgumentException('Ongeldige invoer.');
    if ($key==='artist_intro') $value=str_replace(["\r\n","\r"],"\n",$value);
    $value=trim($value);
    $controlPattern=$key==='artist_intro' ? '/[\x00-\x09\x0B-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u';
    if (mb_strlen($value,'UTF-8')>$limit || preg_match($controlPattern,$value)) {
      throw new InvalidArgumentException('Ongeldige of te lange invoer voor '.str_replace('_',' ',$key).'.');
    }
    $fields[$key]=$value;
  }
  $requiredLabels = ['name'=>'Naam','email'=>'E-mail'];
  foreach ($requiredLabels as $key=>$label) {
    if ($fields[$key]==='') throw new InvalidArgumentException($label.' is verplicht.');
  }
  if (filter_var($fields['email'],FILTER_VALIDATE_EMAIL)===false) throw new InvalidArgumentException('Vul een geldig e-mailadres in.');
  $fields['mobile_number']=seller_mobile_validate($fields['mobile_number']);
  [$fields['bank_account_name'],$fields['bank_account_iban']]=lot_bank_validate($fields['bank_account_name'],$fields['bank_account_iban']);
  if ($type==='kunstenaar') {
    if ($fields['artist_name']==='') throw new InvalidArgumentException('Kunstenaarsnaam is verplicht voor een kunstenaar.');
    [$fields['artist_name'],$fields['artist_url']]=lot_artist_validate($fields['artist_name'],$fields['artist_url']);
    $fields['artist_instagram_url']=artist_instagram_validate($fields['artist_instagram_url']);
  } else {
    $fields['artist_name']='';
    $fields['artist_url']='';
    $fields['artist_instagram_url']='';
    $fields['artist_intro']='';
    $fields['artist_slogan']='';
  }
  return ['seller_type'=>$type]+$fields;
}

function seller_list(PDO $pdo): array {
  return $pdo->query("SELECT s.*, COALESCE(NULLIF(s.created_by_name,''),NULLIF(creator.name,''),creator.email) AS creator_name, COALESCE(NULLIF(s.updated_by_name,''),NULLIF(editor.name,''),editor.email) AS editor_name, (SELECT COUNT(*) FROM lots l WHERE l.seller_id=s.id) AS lot_count FROM lot_sellers s LEFT JOIN admins creator ON creator.id=s.created_by_admin_id LEFT JOIN admins editor ON editor.id=s.updated_by_admin_id ORDER BY s.name,s.id")->fetchAll(PDO::FETCH_ASSOC);
}

function seller_choices(PDO $pdo): array {
  return $pdo->query('SELECT id,name,seller_type FROM lot_sellers ORDER BY name,id')->fetchAll(PDO::FETCH_ASSOC);
}

function seller_valid_id(PDO $pdo, ?int $id): bool {
  if ($id===null) return true;
  $stmt=$pdo->prepare('SELECT 1 FROM lot_sellers WHERE id=?');
  $stmt->execute([$id]);
  return $stmt->fetchColumn()!==false;
}

function seller_has_bank_details(PDO $pdo, int $id): bool {
  $stmt=$pdo->prepare("SELECT 1 FROM lot_sellers WHERE id=? AND TRIM(bank_account_name)<>'' AND TRIM(bank_account_iban)<>''");
  $stmt->execute([$id]);
  return $stmt->fetchColumn()!==false;
}

/** Caller must have checked admin role, CSRF and catalog mutex. */
function seller_save(PDO $pdo, ?int $id, array $input, int $actorId, string $role): int {
  if ($actorId<1 || !seller_can_manage($role)) throw new InvalidArgumentException('Ongeldige bewerkingsrechten.');
  $data=seller_clean($input);
  $actorName=seller_actor_name($pdo,$actorId);
  $keys=array_keys($data);
  $pdo->beginTransaction();
  try {
    if ($id!==null) {
      $check=$pdo->prepare('SELECT * FROM lot_sellers WHERE id=? FOR UPDATE');
      $check->execute([$id]);
      $current=$check->fetch(PDO::FETCH_ASSOC);
      if (!$current) throw new InvalidArgumentException('Verkoper niet gevonden.');
      if (!seller_can_edit($role,$actorId,$current)) throw new InvalidArgumentException('Geen bewerkingsrechten voor deze verkoper.');
      // Seller type is immutable after creation, for every role (including administrator).
      if ($current['seller_type']!==$data['seller_type']) {
        throw new InvalidArgumentException('Het type verkoper kan na aanmaak niet worden gewijzigd.');
      }
      // Compare canonical validated values against the locked DB row. A plain
      // save of an unchanged form must not advance the last-editor audit fields.
      $changed=false;
      foreach($data as $key=>$value) {
        if ((string)($current[$key]??'')!==(string)$value) { $changed=true; break; }
      }
      if (!$changed) {
        $pdo->commit();
        return $id;
      }
      $sets=implode(',',array_map(static fn($key)=>$key.'=?',$keys));
      $stmt=$pdo->prepare('UPDATE lot_sellers SET '.$sets.',updated_by_admin_id=?,updated_by_name=?,updated_at=UTC_TIMESTAMP() WHERE id=?');
      $stmt->execute([...array_values($data),$actorId,$actorName,$id]);
      if ($current['seller_type']==='kunstenaar' && (int)$current['artist_published']===1) {
        foreach (['artist_name','artist_slogan','artist_intro','artist_url','artist_instagram_url'] as $key) {
          if ((string)($current[$key]??'')!==$data[$key]) {
            seller_queue_artist_profile_update($pdo,$id);
            break;
          }
        }
      }
    } else {
      // Status 2 means 'Nieuw'. Existing statuses 0 and 1 remain unchanged.
      $data['artist_published']=$data['seller_type']==='kunstenaar' ? 2 : 0;
      $keys=array_keys($data);
      $stmt=$pdo->prepare('INSERT INTO lot_sellers ('.implode(',',$keys).',created_by_admin_id,created_by_name,created_at,updated_by_admin_id,updated_by_name,updated_at) VALUES ('.implode(',',array_fill(0,count($keys),'?')).',?,?,UTC_TIMESTAMP(),?,?,UTC_TIMESTAMP())');
      $stmt->execute([...array_values($data),$actorId,$actorName,$actorId,$actorName]);
      $id=(int)$pdo->lastInsertId();
    }
    $pdo->commit();
    return $id;
  } catch(Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $ex;
  }
}

function seller_delete(PDO $pdo, int $id, string $role, int $actorId): void {
  $photos=[];
  $pdo->beginTransaction();
  try {
    $stmt=$pdo->prepare('SELECT * FROM lot_sellers WHERE id=? FOR UPDATE');
    $stmt->execute([$id]);
    $record=$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$record) throw new InvalidArgumentException('Verkoper niet gevonden.');
    if (!seller_can_edit($role,$actorId,$record)) throw new InvalidArgumentException('Geen bewerkingsrechten voor deze verkoper.');
    if ($record['seller_type']==='kunstenaar' && (int)$record['artist_published']===1
        && !seller_can_publish_artist($role)) {
      throw new InvalidArgumentException('Enkel veilingmeester of administrator kan een gepubliceerde kunstenaar verwijderen.');
    }
    $used=$pdo->prepare('SELECT COUNT(*) FROM lots WHERE seller_id=?');
    $used->execute([$id]);
    if ((int)$used->fetchColumn()>0) throw new InvalidArgumentException('Deze verkoper is gekoppeld aan kavels. Verwijder eerst de koppeling.');
    $paths=$pdo->prepare('SELECT path FROM seller_images WHERE seller_id=?');
    $paths->execute([$id]);
    $photos=$paths->fetchAll(PDO::FETCH_COLUMN);
    $delete=$pdo->prepare('DELETE FROM lot_sellers WHERE id=?');
    $delete->execute([$id]);
    $pdo->commit();
    foreach($photos as $path) catalog_safe_unlink_photo((string)$path,dirname(__DIR__).'/catalogus-public');
  } catch(Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $ex;
  }
}
