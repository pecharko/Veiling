<?php
declare(strict_types=1);
require dirname(__DIR__) . '/private/visitor_geo_record.php';
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE visitor_geo_daily (
 visit_date TEXT NOT NULL, visitor_id BLOB NOT NULL, country_code TEXT NOT NULL,
 region_code TEXT NOT NULL, city_name TEXT NOT NULL, views INTEGER NOT NULL,
 PRIMARY KEY(visit_date,visitor_id))');
$id = str_repeat('x', 32);
$geo = ['country_code'=>'BE','region_code'=>'VOV','city_name'=>'Lokeren'];
try {
 visitor_geo_record_view($pdo, '2026-10-29', $id, $geo);
 throw new RuntimeException('Missing transaction accepted');
} catch (LogicException $expected) {}
$pdo->beginTransaction();
visitor_geo_record_view($pdo, '2026-10-29', $id, $geo);
visitor_geo_record_view($pdo, '2026-10-29', $id, $geo);
$pdo->commit();
$row = $pdo->query('SELECT COUNT(*) AS n, SUM(views) AS views FROM visitor_geo_daily')->fetch(PDO::FETCH_ASSOC);
if ((int)$row['n'] !== 1 || (int)$row['views'] !== 2) {
 throw new RuntimeException('Geo deduplication or views increment failed');
}
foreach ([
 ['day'=>'2026-10-99','geo'=>$geo],
 ['day'=>'2026-10-29','geo'=>['country_code'=>'NL','region_code'=>'','city_name'=>'Gent']],
] as $invalid) {
 $pdo->beginTransaction();
 try {
  visitor_geo_record_view($pdo, $invalid['day'], $id, $invalid['geo']);
  throw new RuntimeException('Invalid geo input accepted');
 } catch (InvalidArgumentException $expected) {
  $pdo->rollBack();
 }
}
echo "Geographic daily transaction and deduplication OK\n";
