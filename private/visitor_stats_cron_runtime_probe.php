<?php
declare(strict_types=1);
/**
 * Temporary OVH cron runtime probe for issue #224.
 * This is NOT the archiver; no bootstrap, SQL, DB or filesystem writes.
 * Do not run through a web server.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(3);
}
$target = __DIR__ . '/visitor_stats_daily_cron.php';
$ok = is_file($target) && is_readable($target);
$tz = date_default_timezone_get();
$utc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$brussels = $utc->setTimezone(new DateTimeZone('Europe/Brussels'));
echo 'probe=issue224-ovh-cron-runtime' . PHP_EOL;
echo 'sapi=' . PHP_SAPI . PHP_EOL;
echo 'php=' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . PHP_EOL;
echo 'target_readable=' . ($ok ? 'yes' : 'no') . PHP_EOL;
echo 'timezone=' . $tz . PHP_EOL;
echo 'utc_time=' . $utc->format(DateTimeInterface::ATOM) . PHP_EOL;
echo 'brussels_time=' . $brussels->format(DateTimeInterface::ATOM) . PHP_EOL;
exit($ok ? 0 : 4);
