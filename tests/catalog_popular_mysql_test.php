<?php
declare(strict_types=1);
// The exact popularity SQL must be tested against disposable MySQL with archived/raw days.
require dirname(__DIR__) . '/private/bootstrap.php';
require dirname(__DIR__) . '/private/catalog_popular_lots.php';
$pdo = db();
$cfg = cfg('database');
if (($cfg['name'] ?? '') !== 'auction_test' || cfg('setup_key') !== 'ci-test-only-nonproduction') {
  throw new RuntimeException('Refusing to create test fixtures outside disposable CI database');
}
foreach (['visitor_stats.sql', 'visitor_stats_aggregates.sql'] as $filename) {
  $schema = file_get_contents(dirname(__DIR__) . '/database/' . $filename);
  if (!is_string($schema)) throw new RuntimeException('Missing isolated test schema');
  preg_match_all('/CREATE TABLE IF NOT EXISTS[\s\S]*?;/i', $schema, $statements);
  foreach ($statements[0] as $statement) $pdo->exec($statement);
}
$recordArchived = $pdo->prepare('INSERT INTO visitor_stats_lot_totals (visit_date,lot_id,visitor_days,views) VALUES (UTC_DATE() - INTERVAL 3 DAY,?,?,?)');
$recordActive = $pdo->prepare("INSERT INTO visitor_stats_daily (visit_date,visitor_id,page_kind,lot_id,views) VALUES (UTC_DATE(),?,'lot',?,?)");
$insertLot = $pdo->prepare("INSERT INTO lots (lot_number,title,category,description,details,status,sale_location) VALUES (?,?,'Test','','',?,?)");
$ids = ['veiling'=>[], 'markt'=>[]];
foreach (['veiling','markt'] as $kind) {
  for ($i=0; $i<7; $i++) {
    $number = ($kind === 'veiling' ? 910000 : 920000) + $i;
    $insertLot->execute([$number, 'Fictieve test '.$kind.' '.$i, $i === 6 ? 'draft' : 'published', $kind]);
    $id = (int)$pdo->lastInsertId();
    $ids[$kind][] = $id;
    $score = 1000 - $i * 25;
    $recordArchived->execute([$id, 2, $score - 10]);
    $recordActive->execute([hash('sha256', 'popularity-ci-'.$id, true), $id, 10]);
  }
}
$all = catalog_popular_lots($pdo, false);
if (array_keys($all) !== ['alle'] || count($all['alle']) !== 5) throw new RuntimeException('Expected single overall top five');
$groups = catalog_popular_lots($pdo, true);
if (array_keys($groups) !== ['veiling','markt'] || count($groups['veiling']) !== 5 || count($groups['markt']) !== 5) {
  throw new RuntimeException('Expected two independent top fives');
}
foreach ($groups as $group => $ranked) {
  $expected = array_slice($ids[$group], 0, 5);
  $actual = array_map(static fn($row) => (int)$row['lot_id'], $ranked);
  if ($actual !== $expected) throw new RuntimeException('Bad view ranking or draft filtering for '.$group);
  if ((int)$ranked[0]['total_views'] !== 1000) throw new RuntimeException('Archived and raw views not combined');
}
echo "PASS: live and archived seven-day views, split rankings and draft exclusions in isolated MySQL.\n";
