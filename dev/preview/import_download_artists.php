<?php
declare(strict_types=1);
// Idempotent local-only import. Place the twelve JPGs in dev/preview/local-artists/.
// Never run against a production database or use real seller details.
if (PHP_SAPI !== 'cli' || getenv('LOCAL_PREVIEW_ISOLATED') !== '1') {
    throw new RuntimeException('Only the isolated local Docker CLI may import preview artists.');
}
require dirname(__DIR__,2).'/private/bootstrap.php';
require_once dirname(__DIR__,2).'/private/images.php';
$pdo=db();
$database=cfg('database');
if (cfg('setup_key') !== 'local-preview-only-nonproduction'
    || ($database['host'] ?? '') !== 'preview-db'
    || ($database['name'] ?? '') !== 'auction_test'
    || $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'auction_test') {
    throw new RuntimeException('Refusing to import outside the isolated auction_test preview.');
}
$dir=__DIR__.'/local-artists';
$artists=[
    [
        'key'=>'mila_vermeer','name'=>'Mila Vermeer',
        'slogan'=>'Waar eenvoud en kleur elkaar ontmoeten',
        'intro'=>'Mila Vermeer is een fictieve kunstenaar die werkt met geometrische vormen, rustige kleurvlakken en subtiele texturen. Haar composities spelen met balans, ruimte en warme aardetinten.',
        'lots'=>[
            [901201,'Terracotta in balans',720.00,180.00,1],
            [901202,'Stille geometrie',650.00,150.00,2],
        ],
    ],
    [
        'key'=>'elias_moreau','name'=>'Elias Moreau',
        'slogan'=>'Een stad vol kleur en beweging',
        'intro'=>'Elias Moreau is een fictieve schilder met een expressieve, kleurrijke stijl. Zijn portretten en stadsgezichten combineren krachtige penseelstreken met een levendige sfeer.',
        'lots'=>[
            [901203,'Kleur van de stad',920.00,240.00,2],
            [901204,'Avondtram',1100.00,300.00,3],
        ],
    ],
    [
        'key'=>'noor_azevedo','name'=>'Noor Azevedo',
        'slogan'=>'Dromerige werelden tussen natuur en verbeelding',
        'intro'=>'Noor Azevedo is een fictieve kunstenaar die bloemen, dieren en fantasielandschappen samenbrengt. Met rijke kleuren en verhalende details creëert ze betoverende, sprookjesachtige taferelen.',
        'lots'=>[
            [901205,'De geheime tuin',860.00,210.00,1],
            [901206,'Maanlicht over het water',980.00,250.00,2],
        ],
    ],
];
// Preflight every image before a database write, including magic bytes and dimensions.
$files=[];
foreach ($artists as $artist) {
    foreach (['portret'=>['portrait',1],'werk_1'=>['work',1],
              'werk_2'=>['work',2],'werk_3'=>['work',3]] as $suffix=>$slot) {
        $name=$artist['key'].'_'.$suffix.'.jpg';
        $file=$dir.'/'.$name;
        if (!is_file($file) || is_link($file) || filesize($file)===false
            || filesize($file)>16*1024*1024) {
            throw new RuntimeException('Foto ontbreekt of is ongeldig: '.$name);
        }
        $info=@getimagesize($file);
        if (!$info || $info['mime']!=='image/jpeg' || $info[0]<20 || $info[1]<20
            || (float)$info[0]*$info[1]>48000000) {
            throw new RuntimeException('Foto is geen geldige JPG: '.$name);
        }
        $files[$name]=$file;
    }
}
// Verify schema and prevent lot collisions before storing any photos.
foreach (['artist_slogan','artist_intro','created_by_admin_id'] as $column) {
    if (!$pdo->query("SHOW COLUMNS FROM lot_sellers LIKE ".$pdo->quote($column))->fetch()) {
        throw new RuntimeException('Isolated preview missing seller schema: '.$column);
    }
}
$admin=$pdo->query("SELECT id,COALESCE(NULLIF(name,''),'Previewbeheerder') AS name FROM admins ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$admin) throw new RuntimeException('Preview admin missing.');
$findSeller=$pdo->prepare('SELECT id FROM lot_sellers WHERE email=?');
$findLot=$pdo->prepare('SELECT id,seller_id FROM lots WHERE lot_number=?');
foreach ($artists as $artist) {
    $email='fixture-'.$artist['key'].'@example.invalid';
    $findSeller->execute([$email]);
    $sellerId=$findSeller->fetchColumn();
    foreach ($artist['lots'] as $lot) {
        $findLot->execute([$lot[0]]);
        $existing=$findLot->fetch(PDO::FETCH_ASSOC);
        if ($existing && (!$sellerId || (int)$existing['seller_id']!==(int)$sellerId)) {
            throw new RuntimeException('Lotnummer '.$lot[0].' is al in gebruik; geen gegevens overschreven.');
        }
    }
}
$createdImages=[];
try {
    $pdo->beginTransaction();
    $insertSeller=$pdo->prepare("INSERT INTO lot_sellers
        (seller_type,name,email,street,house_number,postal_code,municipality,
         artist_name,artist_url,artist_intro,artist_slogan,created_by_admin_id,created_by_name,
         updated_by_admin_id,updated_by_name)
        VALUES ('kunstenaar',? ,?,'Voorbeeldstraat','1','9160','Lokeren',?,
                'https://example.org/',?,?,?, ?,?,?)");
    $findImage=$pdo->prepare('SELECT id,path FROM seller_images WHERE seller_id=? AND image_kind=? AND slot=?');
    $addImage=$pdo->prepare('INSERT INTO seller_images (seller_id,image_kind,slot,path) VALUES (?,?,?,?)');
    $addLot=$pdo->prepare("INSERT INTO lots
        (lot_number,title,subtitle,category,description,details,status,sale_location,market_price,starting_bid,seller_id)
        VALUES (?,?,'Fictief kunstwerk','Moderne Kunst',?,'Uitsluitend voor de lokale testomgeving',
                'published','markt',?,?,?)");
    $addLotImage=$pdo->prepare('INSERT INTO lot_images (lot_id,path,sort_order) VALUES (?,?,1)');
    $findLotImage=$pdo->prepare('SELECT id FROM lot_images WHERE lot_id=? LIMIT 1');
    foreach ($artists as $artist) {
        $email='fixture-'.$artist['key'].'@example.invalid';
        $findSeller->execute([$email]);
        $sellerId=$findSeller->fetchColumn();
        if (!$sellerId) {
            $insertSeller->execute([
                $artist['name'],$email,$artist['name'],$artist['intro'],$artist['slogan'],
                (int)$admin['id'],$admin['name'],(int)$admin['id'],$admin['name']
            ]);
            $sellerId=(int)$pdo->lastInsertId();
        }
        $sellerId=(int)$sellerId;
        $workPaths=[];
        foreach (['portret'=>['portrait',1],'werk_1'=>['work',1],
                  'werk_2'=>['work',2],'werk_3'=>['work',3]] as $suffix=>$slot) {
            [$kind,$number]=$slot;
            $findImage->execute([$sellerId,$kind,$number]);
            $existing=$findImage->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                if ($kind==='work') $workPaths[$number]=(string)$existing['path'];
                continue; // Never replace a user's preview image.
            }
            $filename=$artist['key'].'_'.$suffix.'.jpg';
            $path=store_image($files[$filename],$filename);
            $createdImages[]=$path;
            $addImage->execute([$sellerId,$kind,$number,$path]);
            if ($kind==='work') $workPaths[$number]=$path;
        }
        foreach ($artist['lots'] as [$number,$title,$price,$start,$work]) {
            $findLot->execute([$number]);
            $lotId=$findLot->fetchColumn();
            if ($lotId) continue;
            $addLot->execute([$number,$title,$artist['intro'],$price,$start,$sellerId]);
            $lotId=(int)$pdo->lastInsertId();
            $findLotImage->execute([$lotId]);
            if (!$findLotImage->fetchColumn() && isset($workPaths[$work])) {
                $addLotImage->execute([$lotId,$workPaths[$work]]);
            }
        }
        echo 'Klaar: '.$artist['name']." (4 foto's, 2 kavels).\n";
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($createdImages as $path) {
        $root=dirname(__DIR__,2).'/catalogus-public/';
        if (preg_match('~^uploads/[0-9]{4}/[0-9]{2}/[a-f0-9]{32}\.webp$~D',$path)) {
            @unlink($root.$path);
        }
    }
    throw $e;
}
echo "IMPORT OK: drie extra fictieve kunstenaars, twaalf foto's, zes kavels.\n";
