# Altijd zichtbare sociale footer
De publieke pagina's (catalogus, kavel, vorige editie en privacy) tonen onderaan de footer altijd **Volg ons** met Facebook- en Instagramiconen, ongeacht de instelling **Kavels tonen op website**.

De officiële sociale profieladressen zijn nog niet geverifieerd. Daarom toont de component tot configuratie niet-klikbare pictogrammen, zonder verzonnen URL of externe tracking. De optionele configuratiesleutels in `private/config.php` zijn `facebook_url` en `instagram_url`, met uitsluitend HTTPS-URL's op de echte platformdomeinen. Voeg de correcte adressen uitsluitend lokaal/privé toe; nooit het configuratiebestand committen.

Dit is een codevoorstel: geen OVH-upload, SQL-/config- of productieactivering zonder aparte toestemming. De footer wordt alleen getoond op een pagina die de normale website-online gate passeert; website-offline blijft ongewijzigd.

## Bevestigde Facebook-pagina
De gebruiker heeft het officiële profiel `https://www.facebook.com/41Lokeren` bevestigd. Het Facebook-icoon gebruikt deze HTTPS-link standaard op alle publieke footers, zonder een wijziging aan `private/config.php`. Een eventueel expliciet geconfigureerde geldige `facebook_url` heeft voorrang; de Instagram-link blijft optioneel.
