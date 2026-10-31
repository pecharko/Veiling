<?php
declare(strict_types=1);
require dirname(__DIR__).'/private/visitor_geo_dashboard.php';
$rows=[
 ['country_code'=>'BE','region_code'=>'VOV','city_name'=>'Lokeren','visitor_days'=>6,'views'=>99],
 ['country_code'=>'BE','region_code'=>'VOV','city_name'=>'Rare village','visitor_days'=>1,'views'=>1],
 ['country_code'=>'BE','region_code'=>'','city_name'=>'','visitor_days'=>1,'views'=>1],
 ['country_code'=>'NL','region_code'=>'NH','city_name'=>'','visitor_days'=>1,'views'=>1],
 ['country_code'=>'ZZ','region_code'=>'','city_name'=>'','visitor_days'=>1,'views'=>1],
];
$countries=array_column(visitor_geo_display_percentages($rows,'country'),'percentage','label');
if($countries!==['België'=>80.0,'Onbekend'=>10.0,'Overige (kleine groepen)'=>10.0])throw new RuntimeException('Country suppression changed denominator');
$cities=visitor_geo_display_percentages($rows,'city');
if(array_sum(array_column($cities,'percentage'))!==100.0)throw new RuntimeException('Belgian city denominator incorrect');
$html=visitor_geo_render_dashboard(['30 dagen'=>['status'=>'ready','since'=>'2026-10-31','rows'=>$rows]]);
if(str_contains($html,'Rare village')||str_contains($html,'visitor_days')||str_contains($html,'99'))throw new RuntimeException('Small group or absolute counts leaked');
if(!str_contains($html,'Lokeren · Oost-Vlaanderen')||!str_contains($html,'Onbekende stad'))throw new RuntimeException('Human-readable labels or unknown missing');
$blocked=visitor_geo_render_dashboard(['30 dagen'=>['status'=>'partial','rows'=>$rows]]);
if(str_contains($blocked,'<table')||!str_contains($blocked,'onvolledig'))throw new RuntimeException('Partial coverage displayed misleading percentages');
$inactive=visitor_geo_render_dashboard(['<unsafe>'=>['status'=>'inactive']]);
if(str_contains($inactive,'<unsafe>')||!str_contains($inactive,'nog niet ingeschakeld'))throw new RuntimeException('Inactive state or escaping incorrect');
echo "Geo dashboard suppression, labels, coverage states and percentage-only checks OK\n";
