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
echo "Geographic report grouping OK\n";
