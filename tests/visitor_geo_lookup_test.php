<?php
declare(strict_types=1);
require dirname(__DIR__) . '/private/visitor_geo_lookup.php';

function assert_unknown_geo(string $ip): void {
    $result = visitor_geo_resolve($ip, '/does/not/exist/GeoLite2-City.mmdb');
    if ($result !== ['country_code'=>'ZZ','region_code'=>'','city_name'=>'']) {
        throw new RuntimeException('Unexpected geo location for ' . $ip);
    }
}
foreach (['', 'invalid', '127.0.0.1', '10.0.0.1', '192.168.1.1', '::1', '2001:db8::1', '8.8.8.8'] as $ip) {
    assert_unknown_geo($ip);
}
echo "Geo lookup fallback checks OK\n";
