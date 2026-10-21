# Handmatige archivering bezoekersstatistieken — implementatie-PR

**Status: voorbereiding, NIET production-ready, GEEN DB-autorisatie, GEEN toestemming tot upload.**

## Onderdeel van deze PR

- `database/visitor_stats_aggregates.sql`: twee aparte tabellen voor anonieme dagtotalen en kavel-dagtotalen, uitsluitend handmatig te installeren **na afzonderlijke goedkeuring**.
- `private/visitor_stats_archive.php`: expliciet aangeroepen archiveringsfunctie; alleen afgesloten UTC-dagen ouder dan 30 dagen, bronrecords vergrendelen, dag- en kaveltotalen berekenen, nieuwe records invoegen, aantallen verifiëren, vervolgens in dezelfde transactie de oude bezoekerscodes verwijderen. Bij fout rollback van gehele transactie; geen gedeeltelijke archivering.
- `catalogus-public/admin/statistieken.php`: handmatige archiveringsactie met CSRF, preview, kortlevende token en getypte bevestiging; campagnetotalen uit gearchiveerde en actieve gegevens. Bestaande totale en datumgebonden handmatige verwijdering werkt transactioneel ook op nieuwe aggregaten.
- `tests/visitor_stats_manual_archive_test.py`: statische regressiecontroles voor veiligheidsvoorwaarden. **Deze statische tests zijn geen vervanging voor uitvoerige MySQL-integratietests.**

## Bewuste beperkingen / openstaande poorten

1. **Geen automatische opschoning** (geen cron, geen cleanup bij publieke bezoeken). De beheerder moet de archivering daadwerkelijk maandelijks uitvoeren; anders blijven bezoekerscodes langer staan. Die operationele termijn moet vastliggen vóór privacygoedkeuring.
2. **Eindevaluatie:** huidige implementatie archiveert dagtotalen en kavelstatistieken, **geen kavelpaar-overlapmetingen**. Per-dag overlap van bezoekers kan na verwijdering niet meer opnieuw worden berekend. Beslis of dat nodig is vóór migratie. De campagnetotalen tellen bezoekersdagen en geen unieke personen over alle dagen.
3. **Privacyverklaring** in `catalogus-public/privacy.php` is in deze PR nog NIET aangepast. De bestaande vermelding 'alle gegevens tot veiling-einde' blijft daardoor niet passend zodra de nieuwe archiveringsfunctie actief is. Een gecorrigeerde privacytekst, definitieve rechtsgrond, rechteninformatie en formele AVG-review zijn verplichte voorwaarden voor release.
4. **Database-integratie nog te bewijzen:** test op een echte geïsoleerde MySQL 8.x testdatabase dat bronnen/aggregaten exact matchen, geen dubbeltelling ontstaat bij herhaald archiveren, een onderbroken transactie niets wist, lot-verwijzingen kloppen, ontbrekende schema's fail-closed behandelen, en handmatige verwijdering uitsluitend statistieken raakt.
5. **Databaseback-up en herstel** vereisen aparte controle en autorisatie vóór enige schemawijziging. Niet via de bestaande code-only zesbestanden-workflow deployen. Nieuwe allowlist, remote drift, exacte SHA-checks en volledig geautoriseerd schema/releaseplan verplicht.
6. **Rapportagebeperking:** de recentste 30 dagen blijven in de oorspronkelijke detailtabel, eerdere dagen staan in aggregaten. Alleen het samenvattende campagneblok is volledig historische rapportage; grafieken en Top 10 blijven op de laatste 30 dagen gericht.
7. **Veiligheidsreview:** controleer in het bijzonder geheugenbelasting bij grote bronsets, transactieduur, locking en performance op OVH MySQL met beperkte DB-quota. Er is nog geen benchmark gedaan. Bij lange campagnes met veel kavels kan verwerking per kleinere datumgrens nodig zijn.
8. De publieke trackingfunctie en haar HMAC zijn **ongewijzigd**. De bestaande privacy- en releasegates blijven dicht.

## Voorlopige acceptatiechecks

- Geen openbare of automatische delete.
- Admin tweestapsarchivering en CSRF.
- Brondata uitsluitend gewist na succesvolle transactionele archivering.
- Aggregaten bevatten geen `visitor_id`.
- Veilig opnieuw uitvoeren van archivering op reeds afgesloten dagen.
- Campagnetotalen combineren zonder dubbeltelling.
- Volledige handmatige verwijdering wist zowel details als aggregaten.
- Bij ontbreken van aggregatieschema moet admin de operatie blokkeren of een duidelijke fout geven; nooit ongemerkt gegevens verwijderen.
- Geen productie-uitrol zonder database- en privacygoedkeuring.

**Productiestatus: niets uitgerold, niets in OVH-database gewijzigd.**
