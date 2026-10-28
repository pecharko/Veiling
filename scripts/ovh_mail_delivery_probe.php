<?php
declare(strict_types=1);
/**
 * CLI-only, single-use OVH mail transport probe. Never place in a web document root.
 * No deployment, scheduling or invocation is configured by this file.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
if ($argc !== 2 || $argv[1] !== 'SEND-ONE-41LOKEREN-TEST') {
    fwrite(STDERR, "Explicit local confirmation required.\n"); exit(2);
}
$host = strtolower((string)(gethostname() ?: ''));
if (!is_file(dirname(__DIR__) . '/private/config.php')) {
    fwrite(STDERR, "Private OVH installation not found.\n"); exit(3);
}
$marker = dirname(__DIR__) . '/private/.ovh-mail-probe-20261028.used';
$handle = fopen($marker, 'c+');
if ($handle === false || !flock($handle, LOCK_EX)) {
    fwrite(STDERR, "Cannot reserve single-use marker.\n"); exit(4);
}
if (fstat($handle)['size'] > 0) {
    fwrite(STDERR, "Probe already attempted.\n"); exit(5);
}
fwrite($handle, gmdate('c') . " attempted\n");
fflush($handle);
flock($handle, LOCK_UN);
fclose($handle);
$headers = [
    'From' => '41 Lokeren <noreply@41lokeren.be>',
    'Reply-To' => 'noreply@41lokeren.be',
    'Content-Type' => 'text/plain; charset=UTF-8',
];
$result = mail(
    'chris.pecharko@gmail.com',
    'Testmail 41 Lokeren',
    "Dit is een eenmalige test van de PHP-mailfunctie van 41 Lokeren.\n"
    . "Controleer de afzender en ontvangst. Geen actie nodig.\n",
    $headers
);
fwrite($result ? STDOUT : STDERR, $result
    ? "PHP mail() accepted message; delivery not yet proven.\n"
    : "PHP mail() rejected message; no automatic retry.\n");
exit($result ? 0 : 6);
