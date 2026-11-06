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
if ((int)$pdo->query('SELECT COUNT(*) FROM lots')->fetchColumn() !== 0) {
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
    echo "Six synthetic lots and preview admin prepared.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}
