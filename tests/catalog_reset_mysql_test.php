<?php
declare(strict_types=1);
// Disposable CI MySQL only. No production credentials or paths.
$host=getenv('CI_DB_HOST') ?: '127.0.0.1';
$db=getenv('CI_DB_NAME') ?: 'auction_test';
if ($host!=='127.0.0.1' || $db!=='auction_test') throw new RuntimeException('Refusing non-isolated database');
$pdo=new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4",getenv('CI_DB_USER') ?: 'root',getenv('CI_DB_PASSWORD') ?: '',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
require dirname(__DIR__).'/private/catalog_reset.php';
 // The preceding HTTP suite leaves disposable fixture lots in this same CI DB.
 // Isolate this test by clearing fixtures before inserting known numbers.
 $pdo->exec('DELETE FROM lot_images');
 $pdo->exec('DELETE FROM lots');
 $pdo->exec('DELETE FROM issued_lot_numbers');

$pdo->exec("INSERT INTO issued_lot_numbers (lot_number) VALUES (31),(32),(33)");
$st=$pdo->prepare("INSERT INTO lots (lot_number,title,description,details,status,sale_location,auction_number) VALUES (?,?,'','','draft',?,?)");
$st->execute([31,'one','veiling',1]);
$st->execute([32,'two','veiling',2]);
$st->execute([33,'three','veiling',3]);
$pdo->exec("INSERT INTO lot_images (lot_id,path,sort_order) SELECT id,'uploads/2026/10/".str_repeat('a',32).".webp',1 FROM lots WHERE lot_number=31");
$res=reset_catalog_lots($pdo);
if($res['deleted']!==3 || count($res['paths'])!==1)throw new RuntimeException('Reset did not report deleted lots/photos');
foreach(['lots','lot_images','issued_lot_numbers'] as $table) if((int)$pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn()!==0) throw new RuntimeException("Reset left rows in $table");
$pdo->exec("INSERT INTO issued_lot_numbers (lot_number) VALUES (1)");
$st->execute([1,'new','markt',null]);
if((int)$pdo->query('SELECT MIN(lot_number) FROM lots')->fetchColumn()!==1)throw new RuntimeException('Numbering did not restart');
echo "PASS isolated MySQL catalog reset, photos, reservations and restart at 1\n";
