<?php
declare(strict_types=1);
require dirname(__DIR__) . '/private/visitor_geo_record.php';

/** Offline input/transaction guards; actual MySQL upsert requires MySQL integration. */
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$id = str_repeat('x', 32);
$geo = ['country_code'=>'BE','region_code'=>'VOV','city_name'=>'Lokeren'];
try {
    visitor_geo_record_view($pdo, '2026-10-29', $id, $geo);
    throw new RuntimeException('Missing transaction accepted');
} catch (LogicException $expected) {}
foreach ([
    ['day'=>'2026-10-99', 'id'=>$id, 'geo'=>$geo],
    ['day'=>'2026-10-29', 'id'=>'short', 'geo'=>$geo],
    ['day'=>'2026-10-29', 'id'=>$id, 'geo'=>['country_code'=>'NL','region_code'=>'','city_name'=>'Gent']],
    ['day'=>'2026-10-29', 'id'=>$id, 'geo'=>['country_code'=>'BE','region_code'=>'VOV','city_name'=>str_repeat('A', 121)]],
    ['day'=>'2026-10-29', 'id'=>$id, 'geo'=>['country_code'=>'BE','region_code'=>'VOV','city_name'=>"\xFF"]],
] as $invalid) {
    $pdo->beginTransaction();
    try {
        visitor_geo_record_view($pdo, $invalid['day'], $invalid['id'], $invalid['geo']);
        throw new RuntimeException('Invalid geo input accepted');
    } catch (InvalidArgumentException $expected) {
        $pdo->rollBack();
    }
}
$source = file_get_contents(dirname(__DIR__) . '/private/visitor_geo_record.php');
if (!is_string($source) || !str_contains($source, 'ON DUPLICATE KEY UPDATE views=views+1')
    || !str_contains($source, 'PRIMARY') && !str_contains($source, 'visitor_geo_daily')) {
    throw new RuntimeException('MySQL daily upsert contract missing');
}
echo "Geographic daily input and transaction guards OK (MySQL upsert integration pending)\n";
