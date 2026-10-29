# Geografische herkomst bezoekers - implementatieplan (2026-10-29)

Status: **voorbereiding; geen runtimecode, geen migratie, geen productieautorisatie**.

## Doel
Voeg aan de bestaande `admin/statistieken.php` geaggregeerde rapporten per land, Belgische provincie/regio en (indicatief) stad toe. Toon de onbekende/niet-bepaalbare categorie expliciet. Land, regio en stad zijn onnauwkeurige IP-schattingen, geen GPS-locaties. Bestaande tellers en hun definities behouden.

## Bestaande architectuur / afhankelijkheden
- `private/visitor_stats.php` hasht `REMOTE_ADDR`, dag en user-agent voor `visitor_stats_daily`, maar slaat het IP niet op. Historische geografische herleiding is onmogelijk.
- `private/visitor_stats_archive_one_day_experimental.php` archiveert per UTC-dag naar `visitor_stats_day_totals` en `visitor_stats_lot_totals`, waarna detailrecords worden verwijderd.
- Automatische cron-archivering volgt een afzonderlijk beveiligd issue224-releasepad.
- `catalogus-public/privacy.php` vermeldt momenteel geen geolocatie.
- OVH Starter heeft beperkte quota en staat geen onzorgvuldige uitbreiding van de bezoekersdatabase toe.

## Architectuurkeuze
1. Gebruik lokaal GeoLite2 City (.mmdb) via de officiële PHP Reader (Composer vendor of gecontroleerde PHAR, versie vastpinnen). Databank buiten publieke webroot en Git plaatsen. Nooit onbetrouwbare X-Forwarded-For gebruiken; uitsluitend REMOTE_ADDR tenzij aantoonbaar vertrouwde reverse proxy-configuratie aanwezig is.
2. Vraag expliciete externe MaxMind-account-/licentiesleutelconfiguratie, nooit in repo of logs. Download/update uitsluitend via gecontroleerde private route. GeoLite2-updates vereist; verwijder oude databanken uiterlijk binnen 30 dagen volgens licentie.
3. Resolve bij een telbaar publiek GET-verzoek op de server; geen externe API-call per bezoek. Verwerk IP slechts in werkgeheugen; bewaar voor het rapport uitsluitend gevalideerde landcode, regio/provincie-code en optionele stadnaam (geaggregeerd). Bestaande DNT, botfilter en analytics aan/uit respecteren.
4. Voeg **afzonderlijke geografische dagaggregatietabel(len)** toe met een duidelijke definitie voor `page_views` en `visitor_days`. Vermijd een simpele teller per request die gebruikersdagen overdrijft. Ontwerp exact-once of idempotente telling, concurrentiecontrole, correct gescheiden `home`/`lot` en onbekende locatie. Verwijder nooit detaildata vóór succesvolle, transactionele geografische samenvatting.
5. Integreer geopartitie in zowel handmatige als cron-archivering; voorkom dat een oude cron na schemawijziging detailrecords verwijdert zonder hun geo-aggregaten. Compatibiliteitscheck tegen huidige snapshot/restore; fail closed waar nodig. Geodata niet bewaren in een tabel die vergeten wordt door reset/backup/herstel/retentie.
6. Rapporteer per land, provincie/regio en stad in bestaande admin-statistieken: aflopende bezoekersdagen en paginaweergaven, periode laatste 30 dagen en campagne, onbekend, lege toestand, voortgang/registratie niet actief. Toon alleen voldoende geaggregeerde waarden; overweeg kleine aantallen te onderdrukken i.v.m. herleidbaarheid.
7. Pas privacytekst, verwijder-/retentiepad en tests aan. Houd verwijderdatum 31 oktober 2027 aan tenzij expliciet anders goedgekeurd.

## Eerst uit te voeren toetsen
- Inventariseer echte DB-schema-, snapshot-, restore- en croncontracten; bepaal of een schema-aanpassing veilig kan worden uitgerold zonder historische gegevens te verliezen.
- Meet GeoLite2 City bestandsgrootte, reader-vereisten en OVH vrije schijfruimte / PHP-extensies read-only.
- Test: één bezoek, herhaald bezoek, gelijktijdige bezoeken, DNT/bot, ongeldige/private IP, ontbrekende/verouderde MMDB, geolocation onbekend, overgang daggrens UTC, SQL failure, archivering en herstel, privacyweergave.
- Release splitsen: code + optionele infrastructuur, daarna afzonderlijk geautoriseerde DB-migratie, MaxMind-database/secret-configuratie, activering. Geen productieactie zonder exacte autorisatie.
- Database-backupcompatibiliteit moet vooraf expliciet worden beslist (oude snapshots na upgrade herstelbaar ja/nee/onbekend).

## Niet doen
- Geen exacte bezoeklocaties of ongehashte IP's opslaan.
- Geen historische bezoeklocaties afleiden uit dagelijkse hashes.
- Geen directe, niet-geverifieerde afhankelijkheidsdownload op OVH.
- Geen nieuwe externe trackingcookies, geen wijziging aan releasegates.
