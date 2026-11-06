# Uitsluiting Verenigde Staten uit bezoekerstatistieken

Elke GET-catalogus- of kavelweergave controleert na de bestaande IP-/bot-/DNT-gates via de **lokale GeoLite2 City-database** het land van het vertrouwde client-IP (`REMOTE_ADDR`). Wanneer het land exact **US** is, stopt de tracker **vóór** berekening van de dagelijkse bezoekersidentifier en vóór alle SQL-schrijfoperaties. Daardoor worden Amerikaanse bezoeken niet meegenomen in de algemene teller, kavelstatistieken of geografische tellingen.

De controle blijft actief wanneer de afzonderlijke geografische registratie uitgeschakeld is. Er wordt geen netwerkverzoek gedaan en er worden geen IP's of GeoIP-details in logs opgeslagen. `X-Forwarded-For` wordt niet vertrouwd.

**Onbekende locaties blijven onbekend.** Bij een ontbrekende, onleesbare, beschadigde of verouderde (>30 dagen) GeoLite2-database is de uitkomst `ZZ`; deze wordt **niet** als US beschouwd en wordt dus niet uitgesloten. Het betreft bijgevolg een uitsluiting van **als US herkende** bezoekers, geen absolute blokkade van alle Amerikaanse IP's. VPN's en proxydiensten kunnen de geschatte herkomst beïnvloeden. De website zelf blijft bereikbaar vanuit de VS.

De maatregel is **prospectief** en verwijdert geen historische gegevens, geaggregeerde statistieken of archieven. De bestaande drie-dagenarchivering blijft ongewijzigd. De nieuwe bronwijziging is alleen op `preview/ui` voorbereid, niet naar `main` gemerged en niet op productie gedeployed.

Offline regressie: `php tests/visitor_stats_us_exclusion_test.php`.
