<?php
declare(strict_types=1);
require dirname(__DIR__) . '/private/visitor_geo_report.php';
$rows = [
 ['country_code'=>'BE','region_code'=>'VOV','city_name'=>'Lokeren','visitor_days'=>3,'views'=>5],
 ['country_code'=>'BE','region_code'=>'VOV','city_name'=>'Gent','visitor_days'=>2,'views'=>4],
 ['country_code'=>'NL','region_code'=>'','city_name'=>'','visitor_days'=>1,'views'=>1],
];
$countries = visitor_geo_group($rows, 'country');
if (count($countries) !== 2 || $countries[0]['label'] !== 'BE' || $countries[0]['visitor_days'] !== 5 || $countries[0]['views'] !== 9) {
 throw new RuntimeException('Country totals incorrect');
}
$regions = visitor_geo_group($rows, 'region');
if ($regions[0]['label'] !== 'BE/VOV' || $regions[0]['visitor_days'] !== 5) {
 throw new RuntimeException('Region totals incorrect');
}
$cities = visitor_geo_group($rows, 'city');
if (count($cities) !== 3) throw new RuntimeException('City grouping incorrect');
try { visitor_geo_group($rows, 'ip'); throw new RuntimeException('Invalid level accepted'); }
catch (InvalidArgumentException $expected) {}

// Unknown location remains a separate bucket and is never attributed to Belgium.
$withUnknown = array_merge($rows, [
 ['country_code'=>'ZZ','region_code'=>'','city_name'=>'','visitor_days'=>4,'views'=>7],
]);
$unknownCountries = visitor_geo_group($withUnknown, 'country');
$unknown = array_values(array_filter($unknownCountries, static fn(array $group): bool => $group['label'] === 'ZZ'));
if (count($unknown) !== 1 || $unknown[0]['visitor_days'] !== 4 || $unknown[0]['views'] !== 7) {
 throw new RuntimeException('Unknown country bucket incorrect');
}
$unknownCities = visitor_geo_group($withUnknown, 'city');
if (!in_array('ZZ/?/?', array_column($unknownCities, 'label'), true)) {
 throw new RuntimeException('Unknown city bucket missing');
}

$percentages = visitor_geo_percentages(visitor_geo_group($withUnknown, 'country'));
$byLabel = array_column($percentages, 'percentage', 'label');
if (count($percentages) !== 3 || $byLabel['BE'] !== 50.0 || $byLabel['ZZ'] !== 40.0 || $byLabel['NL'] !== 10.0) {
 throw new RuntimeException('Country percentage distribution incorrect');
}
if (abs(array_sum(array_column($percentages, 'percentage')) - 100.0) > 0.11) {
 throw new RuntimeException('Percentages must sum to 100 within rounding');
}
if (array_keys($percentages[0]) !== ['label', 'percentage']) {
 throw new RuntimeException('Absolute counts leaked into report presentation');
}
if (visitor_geo_percentages([]) !== []) throw new RuntimeException('Empty percentage report incorrect');
if (visitor_geo_percentages([['label'=>'ZZ','visitor_days'=>0]]) !== []) {
 throw new RuntimeException('Zero denominator must not divide by zero');
}

$beRegions = visitor_geo_belgian_percentages($withUnknown, 'region');
if (count($beRegions) !== 1 || $beRegions[0]['label'] !== 'BE/VOV' || $beRegions[0]['percentage'] !== 100.0) {
 throw new RuntimeException('Belgian province denominator incorrectly includes foreign/unknown visitors');
}
$beCities = visitor_geo_belgian_percentages($withUnknown, 'city');
$beShares = array_column($beCities, 'percentage', 'label');
if (($beShares['BE/VOV/Lokeren'] ?? null) !== 60.0 || ($beShares['BE/VOV/Gent'] ?? null) !== 40.0) {
 throw new RuntimeException('Belgian city shares incorrect');
}
echo "Geographic report grouping OK\n";
