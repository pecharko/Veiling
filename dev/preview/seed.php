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
// Safe, idempotent schema update for the isolated synthetic Docker database only.
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
if (!$artistNameExists) {
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
if (!$bankNameExists) {
    $migration = file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20261110_lot_bank_fields.sql');
    if (!is_string($migration) || !str_contains($migration, 'ADD COLUMN bank_account_iban')) {
        throw new RuntimeException('Bank account migration missing in isolated preview.');
    }
    $pdo->exec($migration);
    echo "Bank fields added to isolated preview.\n";
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
    preview_seed_popularity($pdo);
    echo "Six synthetic lots and preview admin prepared.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}
