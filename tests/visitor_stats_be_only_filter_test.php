<?php
declare(strict_types=1);

// Offline contract: no database, GeoIP download, credentials or production writes.
require dirname(__DIR__) . '/private/visitor_stats.php';

$cases = [
    [['country_code' => 'BE', 'region_code' => 'VOV', 'city_name' => 'Lokeren'], false],
    [['country_code' => 'BE', 'region_code' => '', 'city_name' => ''], false],
    [['country_code' => 'NL', 'region_code' => '', 'city_name' => ''], true],
    [['country_code' => 'FR', 'region_code' => '', 'city_name' => ''], true],
    [['country_code' => 'DE', 'region_code' => '', 'city_name' => ''], true],
    [['country_code' => 'US', 'region_code' => 'CA', 'city_name' => ''], true],
    [['country_code' => 'ZZ', 'region_code' => '', 'city_name' => ''], true],
    [['country_code' => 'be', 'region_code' => '', 'city_name' => ''], true],
    [['country_code' => null, 'region_code' => '', 'city_name' => ''], true],
    [[], true],
];
foreach ($cases as [$geo, $excluded]) {
    if (visitor_stats_exclude_country($geo) !== $excluded) {
        throw new RuntimeException('BE-only analytics filter accepted or rejected an unexpected country');
    }
}
$source = file_get_contents(dirname(__DIR__) . '/private/visitor_stats.php');
if (!is_string($source)) throw new RuntimeException('Missing visitor tracker source');
$ip = strpos($source, "$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');");
$resolve = strpos($source, '$resolvedGeo = visitor_geo_resolve($ip);');
$gate = strpos($source, 'if (visitor_stats_exclude_country($resolvedGeo)) return;');
$hash = strpos($source, '$identifier = hash_hmac(');
$general = strpos($source, 'INSERT INTO visitor_stats_daily');
$geoWrite = strpos($source, 'visitor_geo_record_view(');
if ($ip === false || $resolve === false || $gate === false || $hash === false
    || $general === false || $geoWrite === false
    || !($ip < $resolve && $resolve < $gate && $gate < $hash
         && $hash < $general && $general < $geoWrite)) {
    throw new RuntimeException('BE-only check must precede HMAC, all visitor/lot and geo writes');
}
if (str_contains($source, "$_SERVER['HTTP_X_FORWARDED_FOR']")
    || !str_contains($source, 'visitor_geo_collection_enabled($state)')) {
    throw new RuntimeException('Country determination must use the trusted remote IP and preserve geo switch');
}

echo "PASS Belgian-origin-only filter before all visitor, lot and geographic statistics writes; unknown rejected\n";
