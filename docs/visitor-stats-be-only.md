# Bezoekersstatistieken: uitsluitend bevestigde Belgische herkomst

**Status:** ontwikkel- en testwijziging in de previewbranch; geen PR, merge of productie-uitrol.

## Functionele regel

Alleen bezoeken waarbij de bestaande lokale GeoLite2 City-resolver de tweeletterige
landcode **BE** teruggeeft, mogen worden opgenomen in bezoekersstatistieken.
De filter geldt voordat een dagelijkse pseudonieme bezoeker-ID wordt berekend,
en voordat een van de statistiektabellen wordt bijgewerkt.

- **BE**: normale registratie van algemene bezoeken, kavelweergaven, en
  geografische weergaven indien de afzonderlijke geoschakelaar actief is.
- **Elk ander land** (o.a. NL, FR, DE, US): geen bezoekers- of kavelregistratie;
  geen geografische registratie en daardoor geen nieuwe archiefaggregaten.
- **ZZ, ongeldige of ontbrekende geolocatie**: eveneens geen registratie.
  Bij ontbrekende, verouderde of onleesbare lokale GeoLite2-database stopt
  registreren dus veilig in plaats van een land te raden.
- **Geen vertrouwen in X-Forwarded-For**: de huidige GeoIP-resolver ontvangt
  uitsluitend de vertrouwde `REMOTE_ADDR`. Geen remote lookup of IP-logging.
- Bestaande DNT/bot-, admin-IP-, tracking- en geoschakelaars blijven werken.

De maatregel betreft **nieuwe** bezoeken. Bestaande historische ruwe
statistieken, geografische gegevens, archiefdagen en geaggregeerde totalen
worden bewust niet verwijderd of achteraf herberekend. Dat zou een aparte
gegevens-/archiefbeslissing en afzonderlijke autorisatie vereisen.

Er is **geen databaseschemamigratie** nodig. Wel dient de aanwezige GeoLite2
City-database op de productieomgeving actueel en bruikbaar te zijn;
zonder bevestigde BE-herkomst worden ook Belgische bezoekers niet geteld.

## Gerichte tests

- `php tests/visitor_stats_be_only_filter_test.php`:
  bevestigde BE wel toegelaten; alle buitenlandse, ongeldige en onbekende
  landcodes afgewezen; filter vóór de algemene en geografische writes;
  proxyheaders niet vertrouwd.
- `php tests/visitor_stats_us_exclusion_test.php`:
  historische CI-entrypoint gebruikt dezelfde BE-only-regressietest.
- `php tests/visitor_stats_tracking_mysql_integration.php`:
  uitsluitend in de bestaande wegwerp-MySQL `visitor_stats_ci`;
  onbekende herkomst schrijft geen bezoek- of kavelrecords, ook niet wanneer
  admin-IP-uitsluiting uit staat of forwardingheaders worden vervalst.

Een echte positieve MySQL-/HTTP-test met gecontroleerde Belgische GeoLite2
fixture of vooraf geteste lokale reader ontbreekt nog. Deze moet worden
toegevoegd/uitgevoerd vóór het pakket als volledig geïntegreerd wordt aangemerkt.

De bestaande preview op localhost heeft doorgaans een private of reserved IP
en kan daarom niet worden gebruikt als bewijs van een echte Belgische
GeoIP-resolutie. Bewaar de bestaande fictieve testgegevens.
