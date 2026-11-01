<?php
declare(strict_types=1);

/** EU-27 ISO 3166-1 alpha-2 members; UK and other non-EU countries remain country/region only. */
function visitor_geo_is_eu_country(string $code): bool
{
    return in_array($code, [
        'AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','DE','GR','HU','IE',
        'IT','LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE',
    ], true);
}
