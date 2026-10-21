# Releaseplan bezoekersstatistieken en handmatige archivering — 21 oktober 2026

**Status: voorbereiding op branch, NIET releaseklaar.** Basis: `main` merge SHA `aff6d5bc50350627aaeb0383e0520ee6dcfb038b` (PR #77). Deze notitie verleent geen merge-, SQL-, productie- of deploytoestemming. De volledige release-SHA en allowlist moeten na verdere wijzigingen opnieuw worden vastgesteld.

## Vastgelegde uitgangspunten

- Website 41lokeren.be, organisatie 41 Lokeren, contact 41clublokeren@gmail.com.
- Geen automatische opschoning en geen opschoning bij een websitebezoek.
- Archiveren is een bewuste adminhandeling met voorvertoning, CSRF, tweede bevestiging en database-transactie; detailcodes pas verwijderen na succesvolle opslag van aggregaten.
- Geaggregeerde statistieken uiterlijk tot en met 31 oktober 2027, daarna handmatig verwijderen; tracking mag vanaf 1 november 2027 niet meer plaatsvinden.
- Eerder gekozen eenvoudig profiel zonder *code*-rollback geldt niet automatisch voor databasehandelingen. Productiedatabase altijd afzonderlijk autoriseren, vooraf veilig back-uppen en herstel buiten productie aantoonbaar testen.

## Uitgevoerde bronreview (geen OVH-toegang of productiecontrole)

- `database/visitor_stats_aggregates.sql`: twee `CREATE TABLE IF NOT EXISTS` InnoDB-tabellen (`visitor_stats_day_totals`, `visitor_stats_lot_totals`), geen identificerende bezoekerskolommen. **IF NOT EXISTS valideert een eventueel al bestaande tabelstructuur niet**: vooraf live inspecteren en stoppen bij onverwachte tabellen of schema.
- `private/visitor_stats_archive.php`: transactie en `SELECT ... FOR UPDATE`; accumuleert bezoekers-ID-sets per dag en kavel in PHP-arrays. Geheugengrens en performance ontbreken; geen productiedata-afhankelijke archivering tot worst-case volume, timeouts en locks getest zijn.
- `admin/statistieken.php`: gescheiden preview/confirm-stappen; campagnetotalen combineren opgeslagen aggregaten met huidige dagrecords. Handmatige `delete all` wist drie statistiektabellen, geen kavels of accounts.
- `tests/visitor_stats_mysql_integration.php`: MySQL 8.4-test gebruikt wegwerpdatabase `visitor_stats_ci`, weigert andere DSN en test migratie, tellingen, idempotentie, conflicten en rollback. Eerder PR-head CI groen; **CI op de uiteindelijke release-SHA opnieuw controleren**.
- Huidige aggregaten bewaren geen kavel-paar-overlap; die analyse is later onmogelijk nadat de bezoekerscodes zijn verwijderd.
- Archivering blokkeert niet automatisch nieuw binnenkomende bezoeken voor dezelfde historische datum in uitzonderlijke omstandigheden. Concurrency en locking afzonderlijk toetsen, en geen aannames maken op basis van enkel een geïsoleerde basistest.
- De bestaande zesbestanden-workflow is code-only en **mag niet** worden gebruikt als autorisatie om schema of archiveringsmodule in productie te activeren.

## Uitvoeringsvolgorde en harde stopcriteria

1. **Read-only OVH-inventaris:** databaseversie, charset/collation, aanwezige tabellen/indexen, rechten, omvang en groeiruimte binnen de 250 MB-quota, server-PHP-versie, PDO, geheugen-/tijdslimieten, bestaande live bestanden en SHA-256/serverdrift. Geen wachtwoorden/secrets loggen. Bij afwijking: STOP.
2. **Privacybesluit door verantwoordelijke:** werkelijk juridische identiteit, gedocumenteerde gerechtvaardigd-belang-afweging inclusief noodzaak, bezwaar-/rechtenprocedure, transparante publieke tekst en benoemde archiverings-/verwijderbeheerder. Zonder positieve review: STOP; geen `PRIVACY REVIEW APPROVED` invullen.
3. **Functionele review vóór SQL:** begrens/batch verwerkingsgeheugen (of bewijs bovengrens), realistische load- en locktests, grote dagvolumes, dubbele adminacties en gelijktijdige bezoekers, dataconsistentie en malformed records. Herhaal alle drie verplichte CI-jobs plus MySQL-tests op exacte actuele commit.
4. **Apart DBA-goedgekeurd databasespoor:** volledige, versleuteld/privaat bewaarde data-only MySQL-back-up van de relevante productiegegevens en geïsoleerde herstelproef met expliciet aangetoonde tellingen/structuur; daarna read-only schema-driftcheck; alleen de twee genoemde aggregatietabellen mogen via expliciete aparte SQL-autorisatie worden gecreëerd. Geen destructieve migratie, geen `DROP`, geen import van testdata. Controleer SQL/DDL-resultaat en quota. Een applicatie-snapshot die statistiekgegevens uitsluit is hiervoor onvoldoende.
5. **Apart code-spoor:** stel exacte runtime-allowlist vast na beoordeling van afhankelijkheden, inclusief `private/visitor_stats_archive.php`, `private/visitor_stats.php`, `catalogus-public/admin/statistieken.php`, `catalogus-public/privacy.php` en gewijzigde entrypoints/bootstrap indien nodig. Controleer volledige diff, hostkey, root, symlinks, drift, PHP-lint en checksums. Maak een nieuwe handmatige, afzonderlijk beoordeelde deployworkflow indien de bestaande allowlist niet volstaat; geen `push`-deploy.
6. **Releasegoedkeuring en finale deployautorisatie afzonderlijk:** exacte SHA, allowlist, doelserver, SQL-impact en rollbackprofiel expliciet accorderen. Zonder deze afzonderlijke besluiten geen upload, SQL of activatie.
7. **Na eventuele geautoriseerde release:** read-only homepage/HTTPS/admin-auth/CSV-404 smoke-tests, gecontroleerde niet-destructieve statistiekcontrole, log review zonder identifiers, handmatige UI-acceptatie. Noteer werkelijke run-ID's, SHA, datum, afwijkingen en eindstatus in `docs/TESTPLAN.md`, `docs/REQUIREMENTS.md`, `CHANGELOG.md` en `docs/DEPLOYMENT.md`.

## Operatie tot einde veiling

- Wijs een verantwoordelijke beheerder aan om **minstens eenmaal per 30 dagen** in de admin de archivering te controleren en handmatig uit te voeren. De app heeft geen cron, geen automatische cleanup en geen archivering bij bezoeken. Bij niet uitvoeren blijven codes langer bestaan.
- Documenteer controledatum/resultaat zonder statistiekcodes te exporteren.
- Plan na 31 oktober 2027 de handmatige volledige statistiekverwijdering, controleer de nulstanden, en zorg afzonderlijk voor stopzetting van tracking vóór 1 november 2027. Zonder bewezen stopmechanisme: geen vrijgave.
- Documenteer hoe webserverlogs, hostingback-ups en eventuele andere bewaarsystemen afzonderlijk worden behandeld: admin-delete wist die niet.

## Uitdrukkelijk niet gedaan

Geen externe OVH-inventaris, geen SQL op productie, geen back-up of herstelproef, geen SFTP, geen GitHub Actions deployment, geen wijziging aan live code. Alleen voorbereiding/documentatie.
