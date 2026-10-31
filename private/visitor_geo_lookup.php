<?php
declare(strict_types=1);

/**
 * Optional local GeoLite2 City lookup. No remote network call is made.
 * Returns only coarse, validated location fields, never the IP address.
 * Collection remains OFF until its separately approved complete UTC start day.
 */
function visitor_geo_resolve(string $ip, ?string $databasePath = null): array
{
    $unknown = ['country_code' => 'ZZ', 'region_code' => '', 'city_name' => ''];
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return $unknown;
    }
    $path = $databasePath ?? __DIR__ . '/geoip/GeoLite2-City.mmdb';
    try {
        $autoload = __DIR__ . '/geoip/vendor/autoload.php';
        if (!class_exists('GeoIp2\\Database\\Reader') && is_file($autoload)) {
            require_once $autoload;
        }
        if (!is_file($path) || !is_readable($path) || !class_exists('GeoIp2\\Database\\Reader')) {
            return $unknown;
        }
        $reader = new GeoIp2\Database\Reader($path);
        try {
            // File mtime can change on upload; use the database build epoch.
            $built = $reader->metadata()->buildEpoch;
            if (!is_int($built) || $built <= 0 || $built > time() || $built < time() - 30 * 86400) {
                return $unknown;
            }
            $record = $reader->city($ip);
            $country = strtoupper((string)($record->country->isoCode ?? ''));
            $region = strtoupper((string)($record->mostSpecificSubdivision->isoCode ?? ''));
            $city = (string)($record->city->name ?? '');
            if (!preg_match('/^[A-Z]{2}$/D', $country) || $country === 'ZZ') return $unknown;
            if (!preg_match('/^[A-Z0-9-]{1,16}$/D', $region)) $region = '';
            // Store city only for Belgium, avoid publishing misleading precision abroad.
            if ($country !== 'BE') $city = '';
            $city = trim(preg_replace('/[\\x00-\\x1F\\x7F]/u', '', $city) ?? '');
            if (mb_strlen($city, 'UTF-8') > 120) $city = mb_substr($city, 0, 120, 'UTF-8');
            return ['country_code' => $country, 'region_code' => $region, 'city_name' => $city];
        } finally {
            $reader->close();
        }
    } catch (Throwable $exception) {
        // Unknown is not an exception to public visitor functionality.
        return $unknown;
    }
}
