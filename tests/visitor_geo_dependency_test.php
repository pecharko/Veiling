<?php
declare(strict_types=1);
$autoload=dirname(__DIR__).'/private/geoip/vendor/autoload.php';
if(!is_file($autoload)) {
    echo "SKIP: pinned GeoIP2 dependency smoke test; composer install required\n";
    exit(0);
}
require $autoload;
require dirname(__DIR__).'/private/visitor_geo_lookup.php';
if(!class_exists(GeoIp2\Database\Reader::class)
    || Composer\InstalledVersions::getPrettyVersion('geoip2/geoip2')!=='v3.2.0')throw new RuntimeException('Pinned official reader missing');
$file=tempnam(sys_get_temp_dir(),'geo-corrupt-');
if($file===false)throw new RuntimeException('Cannot create fixture');
try {
    file_put_contents($file,'This is not an MMDB database');
    if(visitor_geo_resolve('8.8.8.8',$file)!==['country_code'=>'ZZ','region_code'=>'','city_name'=>''])throw new RuntimeException('Real reader corruption fallback failed');
}finally{unlink($file);}
echo "Pinned official GeoIP2 reader and corrupt-database fallback OK (licensed production MMDB not provisioned)\n";
