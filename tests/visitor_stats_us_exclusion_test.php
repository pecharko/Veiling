<?php
declare(strict_types=1);
// Pure contract test; no database, GeoIP downloads, production data or credentials.
require dirname(__DIR__) . '/private/visitor_stats.php';
$cases = [
    [['country_code'=>'US','region_code'=>'CA','city_name'=>''], true],
    [['country_code'=>'BE','region_code'=>'VOV','city_name'=>'Gent'], false],
    [['country_code'=>'CA','region_code'=>'ON','city_name'=>''], false],
    [['country_code'=>'ZZ','region_code'=>'','city_name'=>''], false],
    [[], false],
];
foreach ($cases as [$geo, $expected]) {
    if (visitor_stats_exclude_country($geo) !== $expected) {
        throw new RuntimeException('Unexpected US exclusion decision');
    }
}
$source = file_get_contents(dirname(__DIR__) . '/private/visitor_stats.php');
if (!is_string($source)) throw new RuntimeException('Missing tracking source');
$resolve = strpos($source, '$resolvedGeo = visitor_geo_resolve($ip);');
$exclude = strpos($source, 'if (visitor_stats_exclude_country($resolvedGeo)) return;');
$hash = strpos($source, '$identifier = hash_hmac(');
$insert = strpos($source, "INSERT INTO visitor_stats_daily");
$geoWrite = strpos($source, 'visitor_geo_record_view(');
if ($resolve === false || $exclude === false || $hash === false || $insert === false || $geoWrite === false
    || !($resolve < $exclude && $exclude < $hash && $hash < $insert && $insert < $geoWrite)) {
    throw new RuntimeException('US exclusion must occur before pseudonymization and both writes');
}
if (!str_contains($source, 'visitor_geo_resolve($ip)') || str_contains($source, '$_SERVER[\'HTTP_X_FORWARDED_FOR\']')) {
    throw new RuntimeException('Exclusion must use the trusted remote IP, not spoofable headers');
}
echo "PASS US country exclusion before all visitor/lot/geo writes; unknown countries retained\n";
