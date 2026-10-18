<?php
declare(strict_types=1);

/*
 * CSV-import tijdelijk uitgeschakeld op verzoek van de beheerder.
 * De oorspronkelijke implementatie blijft beschikbaar in de Git-geschiedenis.
 * Ook POST-verzoeken naar deze oude route mogen geen data wijzigen.
 */
http_response_code(404);
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
exit;
