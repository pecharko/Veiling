<?php
declare(strict_types=1);
// Reader contract fixture only; no MaxMind database/license or network is used.
namespace GeoIp2\Database {
    final class Reader {
        public static int $built;
        public static array $record = [];
        public static bool $fail = false;
        public static int $lookups = 0;
        public static int $closed = 0;
        public function __construct(string $path) {}
        public function metadata(): object { return (object)['buildEpoch'=>self::$built]; }
        public function city(string $ip): object {
            self::$lookups++;
            if (self::$fail) throw new \RuntimeException('Corrupt fixture');
            return (object)[
                'country'=>(object)['isoCode'=>self::$record['country'] ?? 'BE'],
                'mostSpecificSubdivision'=>(object)['isoCode'=>self::$record['region'] ?? 'VOV'],
                'city'=>(object)['name'=>self::$record['city'] ?? 'Lokeren'],
            ];
        }
        public function close(): void { self::$closed++; }
    }
}
namespace {
    require dirname(__DIR__) . '/private/visitor_geo_lookup.php';
    use GeoIp2\Database\Reader;
    $path = tempnam(sys_get_temp_dir(), 'geo-reader-contract-');
    if ($path === false) throw new RuntimeException('Fixture creation failed');
    $unknown = ['country_code'=>'ZZ','region_code'=>'','city_name'=>''];
    try {
        Reader::$built = time()-86400;
        $be = visitor_geo_resolve('8.8.8.8', $path);
        if ($be !== ['country_code'=>'BE','region_code'=>'VOV','city_name'=>'Lokeren']) throw new RuntimeException('Belgian location lost');
        Reader::$record = ['country'=>'nl','region'=>'NH','city'=>'Amsterdam'];
        if (visitor_geo_resolve('8.8.8.8',$path) !== ['country_code'=>'NL','region_code'=>'NH','city_name'=>'']) throw new RuntimeException('Foreign city retained');
        Reader::$record = ['city'=>"Gent\n\t" . str_repeat('é',130)];
        $clean = visitor_geo_resolve('8.8.8.8',$path)['city_name'];
        if (mb_strlen($clean)!==120 || str_contains($clean,"\n") || str_contains($clean,"\t")) throw new RuntimeException('City bounds or control sanitization failed');
        Reader::$record = ['country'=>'!'];
        if (visitor_geo_resolve('8.8.8.8',$path)!==$unknown) throw new RuntimeException('Malformed country accepted');
        Reader::$record = ['region'=>'<invalid>','city'=>"\xFF"];
        if (visitor_geo_resolve('8.8.8.8',$path)!==['country_code'=>'BE','region_code'=>'','city_name'=>'']) throw new RuntimeException('Malformed names not cleaned');
        foreach ([time()-31*86400, time()+86400, 0] as $invalidEpoch) {
            Reader::$built = $invalidEpoch;
            $before = Reader::$lookups;
            if (visitor_geo_resolve('8.8.8.8',$path)!==$unknown || Reader::$lookups!==$before) throw new RuntimeException('Stale/future MMDB used');
        }
        Reader::$built = time(); Reader::$fail = true;
        $before = Reader::$closed;
        if (visitor_geo_resolve('8.8.8.8',$path)!==$unknown || Reader::$closed!==$before+1) throw new RuntimeException('Reader failure leaked or failed to close');
        $before = Reader::$lookups;
        foreach (['127.0.0.1','10.0.0.1','::1','invalid',''] as $ip) visitor_geo_resolve($ip,$path);
        if (Reader::$lookups!==$before) throw new RuntimeException('Private/invalid IP reached reader');
    } finally { unlink($path); }
    echo "Geo reader contract, cleanup, freshness and privacy checks OK\n";
}
