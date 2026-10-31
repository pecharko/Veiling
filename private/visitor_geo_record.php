<?php
declare(strict_types=1);

/**
 * Stage-only geo counter: caller must supply the same UTC-day pseudonymous
 * identifier used by the existing visitor-stats tracker, and an already
 * resolved coarse location. Not wired to public traffic until migration,
 * archive, snapshot and privacy gates are approved.
 *
 * Both the existing counter and this counter must be called in one database
 * transaction by the eventual tracking integration.
 */
function visitor_geo_record_view(PDO $pdo, string $day, string $visitorId, array $geo): void
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day, new DateTimeZone('UTC'));
    if (!$date || $date->format('Y-m-d') !== $day) {
        throw new InvalidArgumentException('Invalid UTC day');
    }
    if (strlen($visitorId) !== 32) {
        throw new InvalidArgumentException('Invalid daily pseudonymous visitor identifier');
    }
    if (!$pdo->inTransaction()) {
        throw new LogicException('Geo counter must share the parent tracking transaction');
    }
    $country = $geo['country_code'] ?? null;
    $region = $geo['region_code'] ?? null;
    $city = $geo['city_name'] ?? null;
    if (!is_string($country) || !preg_match('/^[A-Z]{2}$/D', $country)
        || !is_string($region) || ($region !== '' && !preg_match('/^[A-Z0-9-]{1,16}$/D', $region))
        || !is_string($city) || !mb_check_encoding($city, 'UTF-8') || mb_strlen($city, 'UTF-8') > 120
        || preg_match('/[\x00-\x1F\x7F]/u', $city)) {
        throw new InvalidArgumentException('Invalid coarse geo bucket');
    }
    if ($country !== 'BE' && $city !== '') {
        throw new InvalidArgumentException('City-level geo is restricted to Belgium');
    }
    if ($country === 'ZZ' && ($region !== '' || $city !== '')) {
        throw new InvalidArgumentException('Unknown country cannot have a region or city');
    }
    $stmt = $pdo->prepare('INSERT INTO visitor_geo_daily
        (visit_date,visitor_id,country_code,region_code,city_name,views)
        VALUES (?,?,?,?,?,1)
        ON DUPLICATE KEY UPDATE views=views+1');
    $stmt->execute([$day,$visitorId,$country,$region,$city]);
}
