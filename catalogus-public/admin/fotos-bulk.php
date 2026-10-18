<?php
declare(strict_types=1);

/*
 * Bulkfoto-import via SFTP is definitief niet ondersteund.
 * Behoud dit bestaande URL-pad als expliciete 404 voor GET en POST.
 * Geen bootstrap, authenticatie, maptoegang, database of schrijfbewerking.
 * Individuele fotoupload via admin/kavel.php blijft beschikbaar.
 */
http_response_code(404);
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
exit;
