# Bezoekersstatistieken - veilige introductie

**Status 21 oktober 2026:** statistieken-PHP nog NIET uitgerold. MySQL-tabel is afzonderlijk aangelegd en `analytics_secret` werd via GitHub Actions-run 39612725640 geïnstalleerd. Privacyreview en de productie-release van de vijf PHP-bestanden zijn nog openstaande gates.

## Werking
- Alleen GET op publieke homepage en gepubliceerde kavelpagina telt. Geen adminverkeer.
- Geen JavaScript, externe trackingprovider of trackingcookies. Bots via user-agent en DNT worden best-effort uitgesloten.
- Dagelijkse pseudonieme HMAC van datum, IP en user-agent; de geheime sleutel staat uitsluitend in private config en nooit in Git. Ruwe IP/user-agent worden niet in de statistiektabel bewaard.
- Een bezoeker wordt slechts binnen dezelfde UTC-dag herkend. **30-dagen unieke bezoekersdagen**, geen werkelijke unieke personen over 30 dagen: terugkerende personen tellen op nieuwe dagen opnieuw mee.
- De top-10-statistiek telt detailpaginaweergaven van kavels, niet unieke geïnteresseerden.
- Deze PR verandert opschoning naar **elk geregistreerd bezoek**, vóór het opslaan van de nieuwe gebeurtenis. Hierdoor worden verlopen rijen niet langer steekproefsgewijs verwijderd. **Dit is nog steeds geen gegarandeerde 30-dagenlimiet wanneer het bezoekverkeer stopt.** Vóór activering is een afzonderlijke dagelijks geplande cleanup met geverifieerde uitvoer en afzonderlijke autorisatie nodig; de exacte OVH-voorziening (cron of DB-event) is nog niet bevestigd.
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
- **Bewaartermijn:** per GET opschonen is een vangnet; zelfstandig, dagelijks bewaartermijnbeheer blijft **blokkerend** vóór productie-activering. Zonder bezoeken mag de tabel niet onbeperkt blijven bestaan.
- **Back-up en herstel:** `visitor_stats_daily` zit niet in de data-only snapshots. Bewust bevestigen als vluchtige statistiek (bij herstel mogelijk verloren), en apart vastleggen hoe overige logs/back-ups worden behandeld.
- **Activeringsvoorwaarden:** privacyverklaring + rechtsgrond bevestigd, periodieke cleanup aantoonbaar ingepland en getest, MySQL quota/indices geverifieerd, nieuw offline releaseplan, expliciete release- én finale deployautorisatie. Alleen na deze gates deployen.
