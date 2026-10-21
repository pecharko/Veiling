# Bezoekersstatistieken - veilige introductie

**Status 21 oktober 2026:** statistieken-PHP nog NIET uitgerold. MySQL-tabel is afzonderlijk aangelegd en `analytics_secret` werd via GitHub Actions-run 39612725640 geïnstalleerd. Privacyreview en de productie-release van de vijf PHP-bestanden zijn nog openstaande gates.

## Werking
- Alleen GET op publieke homepage en gepubliceerde kavelpagina telt. Geen adminverkeer.
- Geen JavaScript, externe trackingprovider of trackingcookies. Bots via user-agent en DNT worden best-effort uitgesloten.
- Dagelijkse pseudonieme HMAC van datum, IP en user-agent; de geheime sleutel staat uitsluitend in private config en nooit in Git. Ruwe IP/user-agent worden niet in de statistiektabel bewaard.
- Een bezoeker wordt slechts binnen dezelfde UTC-dag herkend. **30-dagen unieke bezoekersdagen**, geen werkelijke unieke personen over 30 dagen: terugkerende personen tellen op nieuwe dagen opnieuw mee.
- De top-10-statistiek telt detailpaginaweergaven van kavels, niet unieke geïnteresseerden.
- **Geen automatische opschoning:** op expliciet verzoek wordt noch bij websitebezoeken noch via een geplande taak verwijderd. Er is momenteel geen gegarandeerde bewaartermijn; het overzicht in de admin toont enkel de laatste 30 UTC-dagen, maar oudere rijen blijven in de database staan totdat een afzonderlijk goedgekeurde handmatige verwijdering plaatsvindt. Dit is een privacyrisico en blijft een **blokkerend open besluit vóór activering**.
- Gehashte bezoekersinformatie kan nog steeds persoonsgegevens zijn. Juridische/privacyreview en update van privacyverklaring zijn verplicht voor activatie; cookievrij betekent niet automatisch AVG-vrij.

## Afzonderlijke installatieprocedure
1. Laat CI, privacyreview en de bestaande restore/snapshot-compatibiliteit expliciet controleren. De bestaande snapshotmodule gebruikt een vaste lijst met tabellen en neemt visitor_stats_daily momenteel NIET mee. Dit is voor vluchtige analytics verdedigbaar, maar moet expliciet als productbeslissing worden goedgekeurd; restore zet analytics dus niet terug.
2. Maak en verifieer vooraf een DATA-ONLY snapshot. Controleer de 250 MB MySQL-quota.
3. Voer `database/visitor_stats.sql` na expliciete autorisatie handmatig uit op OVH. Controleer naam en indexen.
4. Configureer in `private/config.php` een `analytics_secret` met minstens 32 cryptografisch willekeurige tekens. Deel deze nooit in chat of commits. Zonder sleutel registreert de app niets.
5. Leg de exacte runtime-allowlist vast: `private/visitor_stats.php`, `private/bootstrap.php`, `catalogus-public/index.php`, `catalogus-public/kavel.php`, `catalogus-public/admin/statistieken.php`. Alleen handmatig gestarte deployment na separate goedgekeurde plan en deploy.
6. Test eerst anonieme HTTPS-GET, adminauth, DNT/bot filtering, MySQL opslag, datumgroepering en top 10. Bestaande restore-integriteit en CSV/Foto-import 404 mogen niet regressief wijzigen.
7. Meet beperkte verkeersvolumes; voeg index/retentiebeleid toe als gebruik groeit. Maak geen live schattingen zonder echte data.

## Minimale acceptatietesten (nog te verifiëren)
- PHP lint alle gewijzigde PHP bestanden.
- Zonder analytics_secret geen SQL trackingquery.
- Ontbrekende tabel verbreekt de publieke pagina niet; admin meldt dat statistieken ontbreken.
- Herhaalde GET van dezelfde browser/dag verhoogt views zonder nieuwe dagbezoeker.
- Andere dag telt dezelfde browser opnieuw.
- Administratie vereist login.
- Migratie en restore onafhankelijk goedgekeurd en getest.

## Privacy- en activatiebesluit (OPEN — geen toestemming tot productie-uitrol)

- **Verwerkingsverantwoordelijke:** 41 Lokeren / organiserende rechtspersoon en contactpunt nog formeel vast te stellen vóór livegang.
- **Verwerkte informatie:** IP-adres en user-agent worden tijdens een GET verwerkt voor de HMAC; in de statistiekentabel wordt enkel een per UTC-dag gepseudonimiseerd 32-byte ID, dag, soort pagina, kavel-ID en weergaveteller opgeslagen. Pseudonimisering is **geen anonimisering**. Hosting- en webserverlogs vallen buiten deze module en vragen een afzonderlijk beleid.
- **Doel:** interne, geaggregeerde bezoekers- en kavelstatistieken; geen advertenties, externe trackers of marketingprofielen.
- **Rechtsgrond:** nog door verantwoordelijke/privacyadviseur vast te leggen. Zonder afgeronde proportionaliteits- en transparantiebeoordeling wordt de module niet geactiveerd; cookievrij betekent niet automatisch toestemmings- of AVG-vrij.
- **DNT:** browserheader DNT=1 wordt gerespecteerd; botdetectie is best effort. Dit is geen volledig opt-out- of privacyrechtenproces.
- **Transparantie:** vóór livegang een duidelijk bereikbare publiek zichtbare privacyverklaring met doel, gegevenscategorieën, bewaartermijn, rechtsgrond, contact/rechten en eventuele ontvangers/hosting. Publicatietekst pas na eigenaarreview.
- **Bewaartermijn:** geen automatische verwijdering, ook niet tijdens paginaweergaven. Voor productie-activering moet een verantwoordelijke een uitvoerbaar en aantoonbaar handmatig wis- en controleproces met concrete bewaartermijn goedkeuren, of opnieuw kiezen voor geautomatiseerde tijdige verwijdering. Een 30-dagenfilter in het dashboard is geen wissen van gegevens.
- **Back-up en herstel:** `visitor_stats_daily` zit niet in de data-only snapshots. Bewust bevestigen als vluchtige statistiek (bij herstel mogelijk verloren), en apart vastleggen hoe overige logs/back-ups worden behandeld.
- **Activeringsvoorwaarden:** privacyverklaring + rechtsgrond bevestigd, toelaatbare bewaartermijn en naleefbaar handmatig verwijderproces gedocumenteerd en getest (zonder automatische opschoning), MySQL quota/indices geverifieerd, nieuw offline releaseplan, expliciete release- én finale deployautorisatie. Zonder deze gates niet deployen.

## Beslissing 21 oktober 2026 — geen automatische opschoning

- De eigenaar heeft expliciet gevraagd **geen geplande automatische opschoning** en **geen opschoning bij elk websitebezoek** te configureren.
- De trackingcode mag uitsluitend tellers opslaan of bijwerken; de trackingcode mag geen analytics-rijen verwijderen.
- De adminmodule bevat in deze PR een handmatige verwijderprocedure: alle statistiekrecords of alle records strikt vóór een UTC-datum. Bevestiging is tweestaps met CSRF en sessie-token (5 minuten). Geen automatische verwijdering. De verwijderprocedure is pas bruikbaar **na een afzonderlijk toegestane productie-uitrol van de admincode**.
- De organisator moet nog de concrete handmatige controlefrequentie, maximale bewaartermijn, verantwoordelijke en registratie van uitgevoerde verwijderingen vastleggen. Zonder naleefbaar beleid blijft privacy-activatie geblokkeerd. Geen productiegegevens wissen door de PR, tests of merge.

## Beheerprocedure: handmatig verwijderen (voorbereid, niet live)

1. Meld aan als beheerder, open **Statistieken** en ga naar **Statistiekgegevens handmatig verwijderen**.
2. Kies **Verwijder alle statistieken** of **Verwijder alles vóór een datum**. De datum is een strikte exclusieve grens in UTC: bijvoorbeeld vóór 2026-10-01 wist alleen records t/m 2026-09-30.
3. De eerste POST met CSRF toont een read-only aantal betrokken database-rijen. Er gebeurt nog geen verwijdering.
4. Een tweede POST vereist opnieuw CSRF, een eenmalig sessietoken dat 5 minuten geldig is, de letterlijke bevestiging **VERWIJDER**, en de expliciete browserwaarschuwing. Het aantal rijen kan tussen preview en uitvoering wijzigen.
5. Bij succes toont de admin een melding met het aantal daadwerkelijk verwijderde rijen. De SQL is beperkt tot `DELETE FROM visitor_stats_daily` (alles) of een gebonden datumparameter `WHERE visit_date < ?`. Geen TRUNCATE, geen broncode of andere tabellen wijzigen.
6. Deze actie is onomkeerbaar binnen de statistiekenmodule: de data-only snapshots bevatten `visitor_stats_daily` niet. Een afzonderlijk bewaarbeleid en een handmatig controlelog buiten de analytics-tabel blijven organisatorische verplichtingen.
7. De gebruikerskeuze is uitdrukkelijk **geen dagelijkse taak en geen opschoning bij bezoeken**. Zonder daadwerkelijk handmatig wissen groeien historische rijen verder ondanks het 30-dagenfilter op het dashboard.
