# Altijd zichtbare sociale footer
De publieke pagina's (catalogus, kavel, vorige editie en privacy) tonen onderaan de footer altijd **Volg ons** met Facebook- en Instagramiconen, ongeacht de instelling **Kavels tonen op website**.

De officiële Facebook- en Instagramprofielen zijn door de gebruiker bevestigd. Beide pictogrammen zijn klikbaar zonder externe scripts of tracking. De optionele configuratiesleutels in `private/config.php` zijn `facebook_url` en `instagram_url`, met uitsluitend HTTPS-URL's op de echte platformdomeinen. Voeg de correcte adressen uitsluitend lokaal/privé toe; nooit het configuratiebestand committen.

Dit is een codevoorstel: geen OVH-upload, SQL-/config- of productieactivering zonder aparte toestemming. De footer wordt alleen getoond op een pagina die de normale website-online gate passeert; website-offline blijft ongewijzigd.

## Bevestigde Facebook-pagina
De gebruiker heeft het officiële profiel `https://www.facebook.com/41Lokeren` bevestigd. Het Facebook-icoon gebruikt deze HTTPS-link standaard op alle publieke footers, zonder een wijziging aan `private/config.php`. Een eventueel expliciet geconfigureerde geldige `facebook_url` heeft voorrang.

## Bevestigde Instagram-pagina
Het officiële Instagramprofiel is `https://www.instagram.com/41lokeren/`. Het Instagram-icoon gebruikt deze HTTPS-link standaard op alle publieke footers. Een expliciet geconfigureerde geldige `instagram_url` heeft voorrang.
