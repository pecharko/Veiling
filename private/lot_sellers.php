<?php
declare(strict_types=1);
require_once __DIR__ . '/lot_bank.php';
require_once __DIR__ . '/lot_artist.php';
require_once __DIR__ . '/seller_mobile.php';
require_once __DIR__ . '/photo_delete_safety.php';

/** Seller records contain personal and banking information: never use on public pages. */
function seller_can_manage(string $role): bool { return in_array($role,['administrator','veilingmeester','assistant'],true); }

function seller_clean(mixed $input): array {
  if (!is_array($input)) throw new InvalidArgumentException('Ongeldige verkopersgegevens.');
  $types = ['kunstenaar','particulier'];
  $type = $input['seller_type'] ?? '';
  if (!is_string($type) || !in_array($type, $types, true)) throw new InvalidArgumentException('Kies kunstenaar of particulier.');
  $limits = ['name'=>150,'email'=>254,'mobile_number'=>30,'street'=>150,'house_number'=>20,'postal_code'=>20,'municipality'=>120,'bank_account_name'=>100,'bank_account_iban'=>42,'artist_name'=>50,'artist_url'=>2048,'artist_intro'=>500];
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
  $requiredLabels = ['name'=>'Naam','email'=>'E-mail','street'=>'Straat','house_number'=>'Huisnummer',
    'postal_code'=>'Postcode','municipality'=>'Gemeente'];
  foreach ($requiredLabels as $key=>$label) {
    if ($fields[$key]==='') throw new InvalidArgumentException($label.' is verplicht.');
  }
  if (filter_var($fields['email'],FILTER_VALIDATE_EMAIL)===false) throw new InvalidArgumentException('Vul een geldig e-mailadres in.');
  $fields['mobile_number']=seller_mobile_validate($fields['mobile_number']);
  [$fields['bank_account_name'],$fields['bank_account_iban']]=lot_bank_validate($fields['bank_account_name'],$fields['bank_account_iban']);
  if ($type==='kunstenaar') {
    if ($fields['artist_name']==='') throw new InvalidArgumentException('Kunstenaarsnaam is verplicht voor een kunstenaar.');
    if ($fields['artist_url']==='') throw new InvalidArgumentException('Website kunstenaar is verplicht voor een kunstenaar.');
    [$fields['artist_name'],$fields['artist_url']]=lot_artist_validate($fields['artist_name'],$fields['artist_url']);
  } else {
    $fields['artist_name']='';
    $fields['artist_url']='';
    $fields['artist_intro']='';
  }
  return ['seller_type'=>$type]+$fields;
}

function seller_list(PDO $pdo): array {
  return $pdo->query("SELECT s.*, COALESCE(NULLIF(creator.name,''),creator.email) AS creator_name, COALESCE(NULLIF(editor.name,''),editor.email) AS editor_name, (SELECT COUNT(*) FROM lots l WHERE l.seller_id=s.id) AS lot_count FROM lot_sellers s LEFT JOIN admins creator ON creator.id=s.created_by_admin_id LEFT JOIN admins editor ON editor.id=s.updated_by_admin_id ORDER BY s.name,s.id")->fetchAll(PDO::FETCH_ASSOC);
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

/** Caller must have checked admin role, CSRF and catalog mutex. */
function seller_save(PDO $pdo, ?int $id, array $input, int $actorId): int {
  if ($actorId<1) throw new InvalidArgumentException('Ongeldige ingelogde beheerder.');
  $data=seller_clean($input);
  $keys=array_keys($data);
  $pdo->beginTransaction();
  try {
    if ($id!==null) {
      $check=$pdo->prepare('SELECT id FROM lot_sellers WHERE id=? FOR UPDATE');
      $check->execute([$id]);
      if (!$check->fetchColumn()) throw new InvalidArgumentException('Verkoper niet gevonden.');
      $sets=implode(',',array_map(static fn($key)=>$key.'=?',$keys));
      $stmt=$pdo->prepare('UPDATE lot_sellers SET '.$sets.',updated_by_admin_id=?,updated_at=UTC_TIMESTAMP() WHERE id=?');
      $stmt->execute([...array_values($data),$actorId,$id]);
    } else {
      $stmt=$pdo->prepare('INSERT INTO lot_sellers ('.implode(',',$keys).',created_by_admin_id,created_at,updated_by_admin_id,updated_at) VALUES ('.implode(',',array_fill(0,count($keys),'?')).',?,UTC_TIMESTAMP(),?,UTC_TIMESTAMP())');
      $stmt->execute([...array_values($data),$actorId,$actorId]);
      $id=(int)$pdo->lastInsertId();
    }
    $pdo->commit();
    return $id;
  } catch(Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $ex;
  }
}

function seller_delete(PDO $pdo, int $id): void {
  $photos=[];
  $pdo->beginTransaction();
  try {
    $stmt=$pdo->prepare('SELECT id FROM lot_sellers WHERE id=? FOR UPDATE');
    $stmt->execute([$id]);
    if (!$stmt->fetchColumn()) throw new InvalidArgumentException('Verkoper niet gevonden.');
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
