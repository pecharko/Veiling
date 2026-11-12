<?php
declare(strict_types=1);
// Synthetic local preview only. No imported photos or OVH data.
if (getenv('LOCAL_PREVIEW_ISOLATED') !== '1') {
    throw new RuntimeException('Preview seed is disabled outside isolated Docker.');
}
require dirname(__DIR__, 2) . '/private/bootstrap.php';
$database = cfg('database');
if (cfg('setup_key') !== 'local-preview-only-nonproduction'
    || ($database['host'] ?? '') !== 'preview-db'
    || ($database['name'] ?? '') !== 'auction_test'
    || db()->query('SELECT DATABASE()')->fetchColumn() !== 'auction_test') {
    throw new RuntimeException('Refusing to seed a non-preview database.');
}
$pdo = db();
$sellerSchemaAlreadyPresent = (bool)$pdo->query("SHOW COLUMNS FROM lots LIKE 'seller_id'")->fetch();
// Safe, idempotent schema update for the isolated synthetic Docker database only.
if (!$pdo->query("SHOW COLUMNS FROM settings LIKE 'is_site_online'")->fetch()) {
    $pdo->exec("ALTER TABLE settings ADD COLUMN is_site_online TINYINT(1) NOT NULL DEFAULT 1");
}
if (!$pdo->query("SHOW COLUMNS FROM settings LIKE 'show_report_issue_link'")->fetch()) {
    $pdo->exec("ALTER TABLE settings ADD COLUMN show_report_issue_link TINYINT(1) NOT NULL DEFAULT 0");
}
if (!$pdo->query("SHOW COLUMNS FROM settings LIKE 'catalog_split_active'")->fetch()) {
    $pdo->exec("ALTER TABLE settings ADD COLUMN catalog_split_active TINYINT(1) NOT NULL DEFAULT 0");
}
// Bring existing isolated previews forward to the reviewed artist schema
// without reseeding lots, deleting uploaded photos, or touching production.
$artistNameExists = (bool)$pdo->query("SHOW COLUMNS FROM lots LIKE 'artist_name'")->fetch();
$artistUrlExists = (bool)$pdo->query("SHOW COLUMNS FROM lots LIKE 'artist_url'")->fetch();
if ($artistNameExists !== $artistUrlExists) {
    throw new RuntimeException('Partial artist schema in preview; refusing an automatic repair.');
}
if (!$artistNameExists && !$sellerSchemaAlreadyPresent) {
    $migration = file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20261108_lot_artist_fields.sql');
    if (!is_string($migration) || !str_contains($migration, 'chk_lots_artist_url_requires_name')) {
        throw new RuntimeException('Reviewed artist migration file missing.');
    }
    $pdo->exec($migration);
    echo "Artist fields added to isolated preview; existing lots and photos retained.\n";
}
// Apply the reviewed category migration only to the isolated, synthetic preview DB.
// This preserves existing test lots/photos and clears old category assignments once.
$categoriesTable = $pdo->query("SHOW TABLES LIKE 'lot_categories'")->fetchColumn();
if ($categoriesTable === false) {
    $migrationPath = dirname(__DIR__, 2) . '/database/migrations/20261110_lot_categories.sql';
    $migration = file_get_contents($migrationPath);
    if (!is_string($migration)
        || !str_contains($migration, "VALUES ('Moderne Kunst'), ('Oude Meesters')")
        || !str_contains($migration, "UPDATE lots SET category='' WHERE category<>'';")) {
        throw new RuntimeException('Reviewed category migration missing in local preview.');
    }
    $migration = preg_replace('/^\s*--[^\n]*$/m', '', $migration);
    foreach (explode(';', (string)$migration) as $statement) {
        if (trim($statement) !== '') $pdo->exec($statement);
    }
    echo "Managed categories added to isolated preview; previous lot categories cleared once.\\n";
} else {
    $columns = $pdo->query("SHOW COLUMNS FROM lot_categories")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('id', $columns, true) || !in_array('name', $columns, true)) {
        throw new RuntimeException('Partial category schema in preview; refusing automatic repair.');
    }
}
// Apply the bank migration only in the isolated synthetic preview database.
$bankNameExists = (bool)$pdo->query("SHOW COLUMNS FROM lots LIKE 'bank_account_name'")->fetch();
$bankIbanExists = (bool)$pdo->query("SHOW COLUMNS FROM lots LIKE 'bank_account_iban'")->fetch();
if ($bankNameExists !== $bankIbanExists) {
    throw new RuntimeException('Partial bank schema in preview; refusing automatic repair.');
}
if (!$bankNameExists && !$sellerSchemaAlreadyPresent) {
    $migration = file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20261110_lot_bank_fields.sql');
    if (!is_string($migration) || !str_contains($migration, 'ADD COLUMN bank_account_iban')) {
        throw new RuntimeException('Bank account migration missing in isolated preview.');
    }
    $pdo->exec($migration);
    echo "Bank fields added to isolated preview.\n";
}
// Never attempt this schema change outside the verified isolated preview database.
$sellersExist = $pdo->query("SHOW TABLES LIKE 'lot_sellers'")->fetchColumn() !== false;
$sellerColumn = (bool)$pdo->query("SHOW COLUMNS FROM lots LIKE 'seller_id'")->fetch();
if ($sellersExist !== $sellerColumn) {
    throw new RuntimeException('Partial seller schema in preview; refusing automatic repair.');
}
if (!$sellersExist) {
    $migration = file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20261111_lot_sellers.sql');
    if (!is_string($migration) || !str_contains($migration, 'fk_lots_seller')) {
        throw new RuntimeException('Reviewed seller migration is missing.');
    }
    $migration = preg_replace('/^\s*--[^\n]*$/m', '', $migration);
    foreach (explode(';', (string)$migration) as $statement) {
        if (trim($statement) !== '') $pdo->exec($statement);
    }
    echo "Seller schema added only to isolated preview.\n";
}
// Only this isolated database may lose the four obsolete, duplicated columns.
$oldArtist = (bool)$pdo->query("SHOW COLUMNS FROM lots LIKE 'artist_name'")->fetch();
$oldBank = (bool)$pdo->query("SHOW COLUMNS FROM lots LIKE 'bank_account_name'")->fetch();
if ($oldArtist !== $oldBank) throw new RuntimeException('Partial legacy seller fields; refusing destructive migration.');
if ($oldArtist) {
    $dedup = file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20261111_lot_seller_dedup.sql');
    if (!is_string($dedup) || !str_contains($dedup, 'DROP CHECK chk_lots_artist_url_requires_name')
        || !str_contains($dedup,'DROP COLUMN bank_account_iban')) {
        throw new RuntimeException('Reviewed seller dedup migration missing.');
    }
    $dedup = preg_replace('/^\s*--[^\n]*$/m', '', $dedup);
    $pdo->exec(trim((string)$dedup));
    echo "Old per-lot seller fields removed only in isolated preview.\n";
}
// Artist introduction and gallery migration only in verified isolated preview.
$introPresent=(bool)$pdo->query("SHOW COLUMNS FROM lot_sellers LIKE 'artist_intro'")->fetch();
$photoTable=(bool)$pdo->query("SHOW TABLES LIKE 'seller_images'")->fetchColumn();
if ($introPresent !== $photoTable) throw new RuntimeException('Partial seller gallery migration; refusing automatic repair.');
if (!$introPresent) {
    $sql=file_get_contents(dirname(__DIR__,2).'/database/migrations/20261111_seller_artist_profile.sql');
    if (!is_string($sql) || !str_contains($sql,'fk_seller_images_seller') || !str_contains($sql,'artist_intro VARCHAR(500)')) {
        throw new RuntimeException('Reviewed artist profile migration missing.');
    }
    $sql=preg_replace('/^\s*--[^\n]*$/m','',$sql);
    foreach(explode(';',(string)$sql) as $statement) if(trim($statement)!=='') $pdo->exec($statement);
    echo "Artist biography and image slots added to isolated preview.\n";
}
// Add optional artist slogan to the isolated, synthetic preview database only.
if (!$pdo->query("SHOW COLUMNS FROM lot_sellers LIKE 'artist_slogan'")->fetch()) {
    $sloganSql=file_get_contents(dirname(__DIR__,2).'/database/migrations/20261112_seller_artist_slogan.sql');
    if (!is_string($sloganSql) || !str_contains($sloganSql, "ADD COLUMN artist_slogan VARCHAR(250) NOT NULL DEFAULT ''")) {
        throw new RuntimeException('Reviewed artist slogan migration missing.');
    }
    $sloganSql=preg_replace('/^\s*--[^\n]*$/m','',$sloganSql);
    $pdo->exec(trim((string)$sloganSql));
    echo "Optional artist slogan added to isolated preview.\n";
}
// Add optional private seller mobile number only to the isolated Docker database.
if (!$pdo->query("SHOW COLUMNS FROM lot_sellers LIKE 'mobile_number'")->fetch()) {
    $mobileSql=file_get_contents(dirname(__DIR__,2).'/database/migrations/20261111_seller_mobile.sql');
    if (!is_string($mobileSql) || !str_contains($mobileSql,'ADD COLUMN mobile_number VARCHAR(16)')) {
        throw new RuntimeException('Reviewed seller mobile migration missing.');
    }
    $mobileSql=preg_replace('/^\s*--[^\n]*$/m','',$mobileSql);
    $pdo->exec(trim((string)$mobileSql));
    echo "Optional seller mobile column added to isolated preview.\n";
}
// Add creator/last-editor audit columns only to the isolated local preview.
$createdAudit=(bool)$pdo->query("SHOW COLUMNS FROM lot_sellers LIKE 'created_by_admin_id'")->fetch();
$updatedAudit=(bool)$pdo->query("SHOW COLUMNS FROM lot_sellers LIKE 'updated_by_admin_id'")->fetch();
if ($createdAudit !== $updatedAudit) throw new RuntimeException('Partial seller audit migration; refusing automatic repair.');
if (!$createdAudit) {
    $auditSql=file_get_contents(dirname(__DIR__,2).'/database/migrations/20261111_seller_audit.sql');
    if (!is_string($auditSql) || !str_contains($auditSql,'ADD COLUMN updated_by_admin_id')) {
        throw new RuntimeException('Reviewed seller audit migration missing.');
    }
    $auditSql=preg_replace('/^\s*--[^\n]*$/m','',$auditSql);
    $pdo->exec(trim((string)$auditSql));
    echo "Seller creator and last-editor tracking added to isolated preview.\n";
}
// Audit fields for lots; no historical edit is inferred from created_at/updated_at.
$lotAuditEditor=(bool)$pdo->query("SHOW COLUMNS FROM lots LIKE 'last_edited_by_admin_id'")->fetch();
$lotAuditTime=(bool)$pdo->query("SHOW COLUMNS FROM lots LIKE 'last_edited_at'")->fetch();
if ($lotAuditEditor!==$lotAuditTime) throw new RuntimeException('Partial lot audit migration; refusing automatic repair.');
if (!$lotAuditEditor) {
    $lotAuditSql=file_get_contents(dirname(__DIR__,2).'/database/migrations/20261111_lot_audit.sql');
    if (!is_string($lotAuditSql) || !str_contains($lotAuditSql,'ADD COLUMN last_edited_at')) {
        throw new RuntimeException('Reviewed lot audit migration missing.');
    }
    $lotAuditSql=preg_replace('/^\s*--[^\n]*$/m','',$lotAuditSql);
    $pdo->exec(trim((string)$lotAuditSql));
    echo "Lot edit auditing added to isolated preview.\n";
}
// Use fake names and e-mail addresses only; never import actual seller information.
if ((int)$pdo->query('SELECT COUNT(*) FROM lot_sellers')->fetchColumn()===0) {
    $seller=$pdo->prepare('INSERT INTO lot_sellers (seller_type,name,email,street,house_number,postal_code,municipality,bank_account_name,bank_account_iban,artist_name,artist_url) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $seller->execute(['kunstenaar','Voorbeeldkunstenaar','kunst@example.invalid','Voorbeeldstraat','1','9160','Lokeren','Voorbeeldkunstenaar','BE68539007547034','Fictieve Ateliernaam','https://example.org']);
    $seller->execute(['particulier','Voorbeeldparticulier','particulier@example.invalid','Voorbeeldstraat','2','9160','Lokeren','Voorbeeldparticulier','BE68539007547034','','']);
    echo "Two synthetic sellers added to local preview.\n";
}
// Link one fictitious published lot to the fictitious artist so the public
// profile can be reviewed locally, without copying production records.
function preview_seed_public_artist(PDO $pdo): void {
    $seller = $pdo->prepare("SELECT id FROM lot_sellers WHERE email='kunst@example.invalid' AND seller_type='kunstenaar' LIMIT 1");
    $seller->execute();
    $sellerId = $seller->fetchColumn();
    if (!$sellerId) return;
    $pdo->prepare("UPDATE lot_sellers SET artist_slogan='Kunst die verwondert' WHERE id=? AND artist_slogan=''")
        ->execute([(int)$sellerId]);
    $pdo->prepare("UPDATE lot_sellers SET artist_intro='Een fictieve maker, uitsluitend voor de lokale preview.' WHERE id=? AND artist_intro=''")
        ->execute([(int)$sellerId]);
    $pdo->prepare("UPDATE lots SET seller_id=? WHERE lot_number=101 AND status='published' AND seller_id IS NULL")
        ->execute([(int)$sellerId]);
}


// Populate only the verified isolated preview with fictional artist portraits,
// artworks and linked lots. Existing sellers, photos and lots are never overwritten.
function preview_seed_artist_gallery(PDO $pdo): void {
    if (getenv('LOCAL_PREVIEW_ISOLATED') !== '1'
        || cfg('setup_key') !== 'local-preview-only-nonproduction'
        || db()->query('SELECT DATABASE()')->fetchColumn() !== 'auction_test'
        || !function_exists('imagewebp')) {
        throw new RuntimeException('Fictieve kunstenaars uitsluitend in de lokale preview.');
    }
    $artists = [
        ['Mira Veld','abstract','Abstracte kleurvelden en ritmische composities.',
            ['Kleurstroom','Ritme in blauw'],[901101,901102]],
        ['Noor Steen','ceramic','Sculpturale vormen geïnspireerd op klei en natuur.',
            ['Stille vormen','Aardlagen'],[901103,901104]],
        ['Elias Wolk','graphic','Grafische kunst met geometrie en contrast.',
            ['Lichtlijnen','Stad in beweging'],[901105,901106]],
    ];
    $findSeller=$pdo->prepare('SELECT id FROM lot_sellers WHERE email=? LIMIT 1');
    $insertSeller=$pdo->prepare('INSERT INTO lot_sellers (seller_type,name,email,street,house_number,postal_code,municipality,artist_name,artist_url,artist_intro,artist_slogan) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $findLot=$pdo->prepare('SELECT id FROM lots WHERE lot_number=? LIMIT 1');
    $addLot=$pdo->prepare("INSERT INTO lots (lot_number,title,subtitle,category,description,details,status,sale_location,market_price,starting_bid,seller_id) VALUES (?,?,'Fictief kunstwerk','Moderne Kunst',?,'Enkel testgegevens','published','markt',?,?,?)");
    $findImage=$pdo->prepare('SELECT id FROM seller_images WHERE seller_id=? AND image_kind=? AND slot=?');
    $addImage=$pdo->prepare('INSERT INTO seller_images (seller_id,image_kind,slot,path) VALUES (?,?,?,?)');
    $findLotImage=$pdo->prepare('SELECT id FROM lot_images WHERE lot_id=? LIMIT 1');
    $addLotImage=$pdo->prepare('INSERT INTO lot_images (lot_id,path,sort_order) VALUES (?,?,1)');
    $dir=dirname(__DIR__,2).'/catalogus-public/uploads/preview-fixtures';
    if (!is_dir($dir) && !mkdir($dir,0755,true) && !is_dir($dir)) throw new RuntimeException('Preview fotomap onbeschikbaar.');
    foreach ($artists as $index=>$artist) {
        [$name,$style,$intro,$titles,$numbers]=$artist;
        $email='preview-artist-'.($index+1).'@example.invalid';
        $findSeller->execute([$email]); $sellerId=$findSeller->fetchColumn();
        if (!$sellerId) {
            $insertSeller->execute(['kunstenaar',$name,$email,'Fictiestraat',(string)($index+1),'9160','Lokeren',$name,'https://example.org/',$intro,'Fictieve kunstenaar · lokale test']);
            $sellerId=(int)$pdo->lastInsertId();
        }
        $sellerId=(int)$sellerId;
        foreach (['portrait'=>1,'work'=>3] as $kind=>$slots) {
            for ($slot=1;$slot<=$slots;$slot++) {
                $findImage->execute([$sellerId,$kind,$slot]);
                if ($findImage->fetchColumn()) continue;
                $relative='uploads/preview-fixtures/artist-'.($index+1).'-'.$kind.'-'.$slot.'.webp';
                $absolute=dirname(__DIR__,2).'/catalogus-public/'.$relative;
                if (!is_file($absolute)) {
                    $image=imagecreatetruecolor(600,450);
                    if (!$image) throw new RuntimeException('Preview image allocation failed.');
                    $palette=[['#204E5F','#E5B45A','#F0785C'],['#56654A','#E2C8A3','#AD7255'],['#26274A','#E7DCD0','#E85B73']][$index];
                    $hex=static function(string $color) use ($image): int {
                        return imagecolorallocate($image,hexdec(substr($color,1,2)),hexdec(substr($color,3,2)),hexdec(substr($color,5,2)));
                    };
                    $background=$hex($palette[0]); $accent=$hex($palette[1]); $highlight=$hex($palette[2]);
                    imagefilledrectangle($image,0,0,599,449,$background);
                    if ($kind==='portrait') {
                        imagefilledellipse($image,300,180,150,168,$accent);
                        imagefilledellipse($image,300,405,310,270,$highlight);
                        imagefilledrectangle($image,240,330,360,450,$highlight);
                    } elseif ($style==='abstract') {
                        for ($shape=0;$shape<9;$shape++) imagefilledellipse($image,100+($shape*71+$slot*34)%480,70+($shape*61)%340,90+$shape*7,130,($shape%2)?$accent:$highlight);
                    } elseif ($style==='ceramic') {
                        for ($shape=0;$shape<5;$shape++) {
                            imagefilledellipse($image,140+$shape*80,295-$shape*27,110+$slot*14,190+$shape*12,$accent);
                            imagefilledellipse($image,140+$shape*80,230-$shape*24,70,28,$highlight);
                        }
                    } else {
                        for ($shape=0;$shape<13;$shape++) {
                            $x=($shape*67+$slot*39)%600;
                            imageline($image,$x,0,($x+280)%600,449,($shape%2)?$accent:$highlight);
                            imagefilledrectangle($image,$x,($shape*43)%400,$x+13,($shape*43)%400+56,$accent);
                        }
                    }
                    if (!imagewebp($image,$absolute,82)) { imagedestroy($image); throw new RuntimeException('Preview image generation failed.'); }
                    imagedestroy($image);
                }
                $addImage->execute([$sellerId,$kind,$slot,$relative]);
            }
        }
        foreach ($numbers as $n=>$lotNumber) {
            $findLot->execute([$lotNumber]);
            $lotId=$findLot->fetchColumn();
            if ($lotId) continue; // Never overwrite user-created preview lots.
            $addLot->execute([$lotNumber,$titles[$n],$intro,380+$index*240+$n*100,90+$index*45+$n*20,$sellerId]);
            $lotId=(int)$pdo->lastInsertId();
            $findLotImage->execute([$lotId]);
            if (!$findLotImage->fetchColumn()) {
                $addLotImage->execute([$lotId,'uploads/preview-fixtures/artist-'.($index+1).'-work-'.($n+1).'.webp']);
            }
        }
    }
    echo "Three synthetic artists, up to twelve images and six linked lots available in isolated preview.\n";
}

// Synthetic popularity statistics exclusively inside the guarded disposable preview DB.
// Both active and archived sources are seeded to exercise the real seven-day ranking.
function preview_seed_popularity(PDO $pdo): void {
    foreach (['visitor_stats.sql', 'visitor_stats_aggregates.sql'] as $schemaFile) {
        $ddl = file_get_contents(dirname(__DIR__, 2) . '/database/' . $schemaFile);
        if (!is_string($ddl) || preg_match_all('/CREATE TABLE IF NOT EXISTS[\\s\\S]*?;/i', $ddl, $statements) < 1) {
            throw new RuntimeException('Preview analytics fixture schema missing.');
        }
        foreach ($statements[0] as $statement) $pdo->exec($statement);
    }
    $raw = (int)$pdo->query('SELECT COUNT(*) FROM visitor_stats_daily')->fetchColumn();
    $archived = (int)$pdo->query('SELECT COUNT(*) FROM visitor_stats_lot_totals')->fetchColumn();
    if ($raw > 0 || $archived > 0) return; // Keep any existing local test data.
    $lots = $pdo->query("SELECT id, lot_number FROM lots WHERE lot_number IN (101,102,103,105) AND status IN ('published','sold')")->fetchAll(PDO::FETCH_ASSOC);
    $insertArchived = $pdo->prepare('INSERT IGNORE INTO visitor_stats_lot_totals (visit_date,lot_id,visitor_days,views) VALUES (UTC_DATE() - INTERVAL 4 DAY, ?, ?, ?)');
    $insertRaw = $pdo->prepare("INSERT IGNORE INTO visitor_stats_daily (visit_date,visitor_id,page_kind,lot_id,views) VALUES (UTC_DATE(),?,'lot',?,?)");
    $archivedTotal = 0;
    foreach ($lots as $lot) {
        $number = (int)$lot['lot_number'];
        $score = [101=>18, 102=>13, 103=>10, 105=>6][$number];
        $archivedTotal += $score;
        $insertArchived->execute([(int)$lot['id'], 2, $score]);
        $insertRaw->execute([hash('sha256', 'fake-preview-visitor-' . $number, true), (int)$lot['id'], 2]);
    }
    if ($lots) {
        $pdo->prepare('INSERT IGNORE INTO visitor_stats_day_totals (visit_date,visitor_days,views,lot_views) VALUES (UTC_DATE() - INTERVAL 4 DAY, 2, ?, ?)')->execute([$archivedTotal, $archivedTotal]);
        echo "Fictieve populaire kavels voorbereid voor de lokale preview.\n";
    }
}
if ((int)$pdo->query('SELECT COUNT(*) FROM lots')->fetchColumn() !== 0) {
    preview_seed_public_artist($pdo);
    preview_seed_artist_gallery($pdo);
    preview_seed_popularity($pdo);
    echo "Preview fixtures already present.\n";
    return;
}
$pdo->beginTransaction();
try {
    $pdo->exec("UPDATE settings SET title='41 Lokeren · lokale preview', subtitle='Fictieve voorbeeldkavels', introduction='Veilige ontwikkelomgeving met fictieve gegevens', practical_info='Alleen lokaal beschikbaar', is_published=1 WHERE id=1");
    $pdo->prepare("UPDATE admins SET name=? WHERE email=?")->execute(['Previewbeheerder', 'ci-admin@example.invalid']);
    $insert = $pdo->prepare('INSERT INTO lots (lot_number,title,subtitle,category,description,details,status,sale_location,auction_number,market_price,starting_bid) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $samples = [
        [101,'Abstract landschap','Fictief werk','Kunst','Demonstratiekavel zonder echte eigenaar','Alle gegevens zijn verzonnen','published','veiling',1,850,250],
        [102,'Vintage designstoel','Voorbeeldobject','Design','Fictieve collectie','Geen productiedata','published','veiling',2,400,90],
        [103,'Keramische vaas','Voorbeeldobject','Interieur','Een fictieve beschrijving','Voor preview','published','markt',null,175,40],
        [104,'Grafische compositie','Fictief werk','Kunst','Een voorbeeld voor de catalogus','Geen echte prijs','published','markt',null,600,100],
        [105,'Fotografie in lijst','Fictief werk','Fotografie','Fictieve fotokavel','Geen echte inzending','sold','veiling',3,300,75],
        [106,'Ontwerplamp','Niet gepubliceerd','Design','Alleen zichtbaar voor beheer','Voorbeeldconcept','draft','markt',null,220,50],
    ];
    foreach ($samples as $row) $insert->execute($row);
    $pdo->commit();
    preview_seed_public_artist($pdo);
    preview_seed_popularity($pdo);
    echo "Six synthetic lots and preview admin prepared.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}
