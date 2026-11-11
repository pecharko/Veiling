<?php
declare(strict_types=1);
require_once __DIR__ . '/lot_bank.php';
require_once __DIR__ . '/lot_artist.php';

/** Seller records contain personal and banking information: never use on public pages. */
function seller_can_manage(string $role): bool { return $role === 'administrator'; }

function seller_clean(mixed $input): array {
  if (!is_array($input)) throw new InvalidArgumentException('Ongeldige verkopersgegevens.');
  $types = ['kunstenaar','particulier'];
  $type = $input['seller_type'] ?? '';
  if (!is_string($type) || !in_array($type, $types, true)) throw new InvalidArgumentException('Kies kunstenaar of particulier.');
  $limits = ['name'=>150,'email'=>254,'street'=>150,'house_number'=>20,'postal_code'=>20,'municipality'=>120,'bank_account_name'=>100,'bank_account_iban'=>42,'artist_name'=>50,'artist_url'=>2048,'artist_intro'=>500];
  $fields = [];
  foreach ($limits as $key=>$limit) {
    $value=$input[$key] ?? '';
    if (!is_string($value)) throw new InvalidArgumentException('Ongeldige invoer.');
    $value=trim($value);
    if (mb_strlen($value,'UTF-8')>$limit || preg_match('/[\x00-\x1F\x7F]/u',$value)) {
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
  return $pdo->query('SELECT s.*, (SELECT COUNT(*) FROM lots l WHERE l.seller_id=s.id) AS lot_count FROM lot_sellers s ORDER BY s.name,s.id')->fetchAll(PDO::FETCH_ASSOC);
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
function seller_save(PDO $pdo, ?int $id, array $input): int {
  $data=seller_clean($input);
  $keys=array_keys($data);
  $pdo->beginTransaction();
  try {
    if ($id!==null) {
      $check=$pdo->prepare('SELECT id FROM lot_sellers WHERE id=? FOR UPDATE');
      $check->execute([$id]);
      if (!$check->fetchColumn()) throw new InvalidArgumentException('Verkoper niet gevonden.');
      $sets=implode(',',array_map(static fn($key)=>$key.'=?',$keys));
      $stmt=$pdo->prepare('UPDATE lot_sellers SET '.$sets.' WHERE id=?');
      $stmt->execute([...array_values($data),$id]);
    } else {
      $stmt=$pdo->prepare('INSERT INTO lot_sellers ('.implode(',',$keys).') VALUES ('.implode(',',array_fill(0,count($keys),'?')).')');
      $stmt->execute(array_values($data));
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
  $pdo->beginTransaction();
  try {
    $stmt=$pdo->prepare('SELECT id FROM lot_sellers WHERE id=? FOR UPDATE');
    $stmt->execute([$id]);
    if (!$stmt->fetchColumn()) throw new InvalidArgumentException('Verkoper niet gevonden.');
    $used=$pdo->prepare('SELECT COUNT(*) FROM lots WHERE seller_id=?');
    $used->execute([$id]);
    if ((int)$used->fetchColumn()>0) throw new InvalidArgumentException('Deze verkoper is gekoppeld aan kavels. Verwijder eerst de koppeling.');
    $delete=$pdo->prepare('DELETE FROM lot_sellers WHERE id=?');
    $delete->execute([$id]);
    $pdo->commit();
  } catch(Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $ex;
  }
}
