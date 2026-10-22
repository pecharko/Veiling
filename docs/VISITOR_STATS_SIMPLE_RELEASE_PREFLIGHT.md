# PR #81: eendagsarchivering — eenvoudige release, uitsluitend voorbereiding

## Status en scope

- Broncode: goedgekeurde merge `a3237de3fde31b1cdf2141ccd596964f362263dd`.
- Profiel: voorlopig **eenvoudig zonder rollback**; nog geen expliciete definitieve risicoacceptatie of deploymentautorisatie.
- Exacte runtime-allowlist, in veilige volgorde: `private/visitor_stats_archive_one_day_experimental.php` **eerst**, dan `catalogus-public/admin/statistieken.php`.
- Geen upload van `private/visitor_stats_archive.php`: deze bestaande functie wordt door de nieuwe adminroute niet aangeroepen. Niet uploaden: documentatie, tests, database, `private/config.php`, geheimen, snapshots, foto’s, `.htaccess`.
- De bestaande geaggregeerde statistiektabellen moeten vooraf aantoonbaar bestaan. Geen database-migratie of destructieve databaseactie in deze release.

## Verplichte afzonderlijke read-only productie-preflight — NOG NIET UITGEVOERD

Deze voorbereiding maakt **geen** externe verbinding. Een bevoegd beheerproces moet, zonder te schrijven:

1. De verwachte OVH-SFTP-host en poort bepalen op basis van de bestaande, vertrouwde productieconfiguratie. Vergelijk de ontvangen hostkey exact met de **eerder onafhankelijk vertrouwde** hostkey uit de beheerde known_hosts/CI-gate. Geen automatische trust-on-first-use en geen acceptatie bij mismatch. Noteer *alleen* de hostkey-fingerprint, geen geheimen.
2. De SFTP-root read-only controleren en aantonen dat het bestaande doelpad voor `catalogus-public/admin/statistieken.php` en `private/visitor_stats_archive_one_day_experimental.php` correct is. Niet gokken uit accountnaam of toevallig vindbare mappen.
3. Beide remote paden afzonderlijk read-only inspecteren: bestaan of ontbreken, type normaal bestand (geen symlink), normale toegangsrechten. Een onverwachte symlink of ongekende mapstructuur betekent **STOP**.
4. Het bestaande remote adminbestand read-only vergelijken met de expliciet geaccepteerde **productiebaseline** (SHA-256 of aantoonbaar gelijkwaardige inhoud); bij gewijzigde of onbekende remote inhoud **STOP wegens drift**, niet stilzwijgend overschrijven.
5. Voor het nieuwe private bestand controleren dat het **niet onverwacht bestaat**; als het wel bestaat, eerst de exacte herkomst en inhoud expliciet beoordelen. Geen automatische overwrite.
6. Lint en checksums van de twee **bronbestanden** verifiëren op dezelfde vastgepinde releasecommit; vergelijking met het manifest moet byte-exact zijn.
7. Controleren of de drie tabellen `visitor_stats_daily`, `visitor_stats_day_totals` en `visitor_stats_lot_totals` aanwezig zijn, uitsluitend via vooraf geautoriseerde read-only schema-inspectie. Als zo'n inspectie niet geautoriseerd of beschikbaar is, blokkeren en niet aannemen dat de tabellen bestaan.
8. Preflightbevindingen met datum, bron-SHA, hostkeyresultaat, root, driftuitkomst en CI-run vastleggen. Geen gegevens van bezoekers of credentials loggen.

De huidige offline GitHub-workflow verifieert uitsluitend de **bron**, niet deze externe resultaten. Een groen offline CI-resultaat is dus **geen productie-plan-pass**.

## Releasegates voor een afzonderlijke toekomstige deploymentworkflow

- Alleen `workflow_dispatch`, nooit `push`. Exacte commit, vastgezette twee-paden-allowlist, expliciete modus `plan` of `deploy` en menselijke goedkeuring.
- `plan` is read-only en moet alle bovenstaande OVH-gates daadwerkelijk passeren voor enige releasegoedkeuring.
- `deploy` weigert bij drift of gewijzigde bron-SHA. Upload in bovenstaande volgorde, controleer de SHA-256 per bestand, stop bij eerste fout. Geen `--delete`, blind directory-sync, DB-aanpassingen of omzeilen van SSH/SFTP-gates.
- Profiel eenvoudig: geen codeback-up, rollbackartifact of automatische rollback; andere gates blijven volledig verplicht.
- Lees na afloop uitsluitend veilige HTTPS-smoke-tests: publieke homepage, admin-loginredirect, uitgeschakelde CSV-import GET en lege POST (404), plus visuele adminacceptatie door beheerder; nooit archiveren of verwijderen als productietest.
- Twee **gescheiden** handelingen na succesvolle read-only `plan`: (1) expliciete releasegoedkeuring met risicobevestiging en precieze bestanden, (2) definitieve autorisatie voor de werkelijke productie-upload.

> Bij een fout is er geen automatische of voorbereide rollback; herstel vergt een nieuwe handmatige correctie of herdeploy en kan downtime veroorzaken.

**Status:** workflow en offline manifest ter review in PR #82. Hostkey, root, productie-drift, schemaverificatie en productiedeployment: niet uitgevoerd; dus vrijgave geblokkeerd.
