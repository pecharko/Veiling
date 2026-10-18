# Testplan — veilingcatalogus 41 Lokeren

**Status 18 oktober 2026:** de schema-inspecties T-026, T-030 t/m T-032 en T-034 t/m T-036 zijn met phpMyAdmin-screenshots bevestigd. De nieuwe fase-1-code-inspectie is gedocumenteerd in [REGRESSION_2026-10-18.md](REGRESSION_2026-10-18.md). **De basiscontrole T-001 is op 18-10-2026 voor drie anonieme adminroutes geslaagd en de admin is na aanmelding toegankelijk (T-001B). Chrome bevestigde op een later screenshot 'Connection is secure' en 'Certificate is valid' voor 41lokeren.be (T-040: TLS-deel geslaagd); de gebruiker heeft de tijdelijke HTTPS-redirect vervolgens uitgerold en met `curl -I http://41lokeren.be/admin/login.php` een **`302 Found` + `Location: https://41lokeren.be/admin/login.php`** bevestigd. T-040: **definitieve 301** nu voor HTTP-login en homepage bevestigd; eerder zijn direct HTTPS (HTTP/2 200), de redirectketen zonder lus en Chrome-certificaat gecontroleerd; andere adminroutes en sessie-/logouttesten blijven open; herstelplan staat in [HTTPS_REMEDIATION.md](HTTPS_REMEDIATION.md).** Vul bij uitvoering datum, omgeving, commit, resultaat en opmerkingen in.

| Test-ID | Requirement | Testscenario | Verwacht resultaat | Uitvoering |
|---|---|---|---|---|
| T-001 | FR-001 | Adminpagina's openen zonder login (`/admin/index.php`, `/admin/import.php`, `/admin/kavel.php`) | Login vereist, geen afgeschermde kavel-/importinhoud zichtbaar | **Geslaagd (drie visuele basiscontroles), 18-10-2026** — drie gebruikersscreenshots in het gevraagde incognitovenster tonen loginformulier zonder admininhoud; bij de derde is in de adresbalk `41lokeren.be/admin/login.php` zichtbaar. De oorspronkelijk aangevraagde URL `/admin/kavel.php` is niet tegelijk in beeld, maar de navigatie gebeurde volgens de testinstructie. Geldige login/logout en overige routes niet getest. |
| T-002 | FR-002/004 | Nieuwe kavel maken, geldige titel en schatting | Kavel wordt bewaard en heropenbaar | Niet uitgevoerd |
| T-003 | FR-003 | Tweede kavel met bestaand lotnummer bewaren | Duidelijke fout; geen tweede identiek lotnummer | Niet uitgevoerd |
| T-004 | FR-003 | Bestaande kavel bewerken zonder lotnummer te wijzigen | Bewerking slaagt | Niet uitgevoerd |
| T-005 | FR-003/009 | CSV importeren met dubbele lotnummers | Geen stille duplicaten; begrijpelijke melding | Niet uitgevoerd |
| T-006 | FR-005/012 | Concept en gepubliceerd; catalogus aan/uit | Alleen bedoelde kavels worden publiek zichtbaar | Niet uitgevoerd |
| T-007 | FR-006/007 | Geldige en ongeldige foto's uploaden/verwijderen | Alleen geldige foto's geaccepteerd; veilige verwijdering | Niet uitgevoerd |
| T-008 | FR-008 | Meerdere foto's met verschillende sorteerwaarden | Laagste sorteerwaarde is hoofdfoto | Niet uitgevoerd |
| T-009 | FR-009/010 | CSV- en fotobulkimport met fouten | Heldere melding, geen onbedoelde dataverlies | Niet uitgevoerd |
| T-010 | FR-011 | Veilinginfo bewerken en herladen | Alle velden correct opgeslagen en getoond | Niet uitgevoerd |
| T-011 | FR-015/016 | Snapshot maken, tonen, downloaden | ZIP beschikbaar en compleet | Niet uitgevoerd |
| T-012 | FR-017 | Snapshot terugzetten in geïsoleerde testomgeving | Database en foto's correct hersteld; geen half herstel | Niet uitgevoerd |
| T-013 | FR-017 | Ongeldige/beschadigde ZIP aanbieden | Herstel wordt veilig geweigerd | Niet uitgevoerd |
| T-014 | FR-017 | Veiligheidskopie vóór herstel controleren | Originele toestand veilig herstelbaar | Niet uitgevoerd |
| T-015 | FR-018/019 | Opslagdashboard vergelijken met OVHcloud Manager | Verschillen verklaard; labels kloppen | Niet uitgevoerd |
| T-016 | FR-019 | Drempels 79%, 80%, 89%, 90% simuleren | Correcte waarschuwingsniveaus | Niet uitgevoerd |
| T-017 | FR-020 | Snapshot met onvoldoende vrije ruimte testen | Snapshot veilig geweigerd | Niet uitgevoerd |
| T-018 | FR-021 | Elk beheeronderdeel openen | Back-up & herstel blijft in menu zichtbaar | Niet uitgevoerd |
| T-019 | FR-022 | Beheer-POST zonder geldig CSRF-token | Actie geweigerd, geen datamutatie | Niet uitgevoerd |
| T-020 | NFR-003 | Mobiel scherm en toetsenbordbediening | Velden en acties bruikbaar, geen overlap | Niet uitgevoerd |
| T-021 | NFR-001 | Geplande commit op secrets/gegevens scannen | Geen credentials, SQL-dumps, foto's of snapshots | Niet uitgevoerd |
| T-022 | FR-024 | Publiek zoeken/filteren/sorteren/pagineren | Alleen gepubliceerde kavels; correcte resultaten en navigatie | Niet uitgevoerd |
| T-023 | FR-025 | Favoriet toevoegen/verwijderen, pagina herladen | Favorieten blijven lokaal per browser behouden; geen serveraccount nodig | Niet uitgevoerd |
| T-024 | FR-026 | Galerie en delen/kopiëren kavel-link | Goede foto en bruikbare deelbare URL | Niet uitgevoerd |
| T-025 | FR-027/028 | Adminoverzicht en systeemcontrole | Correcte tellingen, filters, controles en foutmeldingen | Niet uitgevoerd |
| T-026 | FR-003 | `SHOW INDEX FROM lots;` via phpMyAdmin | Unieke DB-index uitsluitend op `lot_number` | **Geslaagd 18-10-2026** — screenshot: `Key_name=lot_number`, `Column_name=lot_number`, `Non_unique=0`, `Seq_in_index=1` |
| T-027 | FR-015/017 | Gelijktijdige kavelwijziging tijdens snapshot/restore in testomgeving | Back-up en herstelde data zijn onderling consistent; geen verlies | Niet uitgevoerd |
| T-028 | FR-013 | Kavel zonder foto en voorbeeld-CSV downloaden | Placeholder en CSV-download werken zonder 404 | Niet uitgevoerd |
| T-029 | NFR-001 | GitHub-baseline scannen op hardcoded secrets, persoonsgegevens, snapshots | Geen gevoelige gegevens in getrackte bestanden | Niet uitgevoerd |
| T-030 | FR-003 | Bestaande dubbele lotnummers opsporen met `SELECT lot_number, COUNT(*) AS aantal FROM lots GROUP BY lot_number HAVING COUNT(*) > 1 ORDER BY lot_number;` | Geen rijen (geen dubbele lotnummers) | **Geslaagd 18-10-2026** — phpMyAdmin-screenshot: empty result set, zero rows |
| T-031 | FR-003 | `SHOW CREATE TABLE lots;` in phpMyAdmin | DDL bevat `UNIQUE KEY lot_number (lot_number)`, verwachte kolommen, defaults en indexen | **Geslaagd 18-10-2026** — volledige DDL getoond, opgenomen in `docs/DATABASE.md` (zonder omgevingsgebonden auto-incrementteller) |
| T-032 | FR-007/008/010 | `SHOW CREATE TABLE lot_images;` in phpMyAdmin | FK naar `lots.id` met `ON DELETE CASCADE`, unieke bronnaam per kavel en sorteerindex aanwezig | **Geslaagd 18-10-2026** — schema/constraints gezien op screenshot; vastgelegd in `docs/DATABASE.md` |
| T-033 | FR-007/017 | Testkavel met foto's verwijderen, inclusief databasecascade en bestandsopruiming | Geen gekoppelde fotoregistraties en geen achterblijvende fysieke foto's; herstelmechanisme blijft consistent | Niet uitgevoerd — **uitsluitend geïsoleerde testomgeving**, niet op productie |
| T-034 | FR-011/012 | `SHOW CREATE TABLE settings;` via phpMyAdmin | Veilingvelden, optionele datum, publicatievlag standaard 0, primaire sleutel en wijzigingstijdstip aanwezig | **Geslaagd 18-10-2026** — screenshot toont volledig schema; opgenomen in `docs/DATABASE.md`. Geen interactieve formulier- of publicatietest |
| T-035 | FR-001 | `SHOW CREATE TABLE admins;` via phpMyAdmin | Primaire sleutel, unieke e-mailindex, verplichte `password_hash` en aanmaakdatum aanwezig | **Geslaagd 18-10-2026** — DDL bevestigd en opgenomen in `docs/DATABASE.md`. Login-/rollenwerking niet getest |
| T-036 | FR-001 | `SHOW CREATE TABLE login_attempts;` in phpMyAdmin | Primaire sleutel, `ip_hash CHAR(64)` (ASCII), timestamp en samengestelde index op IP-hash/tijdstip aanwezig | **Geslaagd 18-10-2026** — geverifieerd via aangeleverde screenshot; DDL in `docs/DATABASE.md` |
| T-037 | FR-001 | Test inloglimiet met gecontroleerde testaccount/IP en herstel na tijdvenster, inclusief opschoning oude pogingen | Na 8 mislukte pogingen binnen 15 minuten wordt de volgende poging tijdelijk geblokkeerd; records ouder dan 24 uur worden bij login-POST opgeschoond, succesvolle login wist IP-pogingen | Niet uitgevoerd — **uitsluitend testomgeving**, geen productieblokkering veroorzaken |
| T-038 | FR-002/004 | Invalide kavelinvoer veroorzaakt fout; ingevoerde gegevens blijven zichtbaar in het formulier | POST-gegevens blijven behouden zonder onverwachte aanmaak/wijziging | **Code-inspectie: afwijking gevonden 18-10-2026** — invoervelden worden na een fout uit DB/defaults opgebouwd; niet interactief getest, zie issue #5 |
| T-039 | FR-002/006 | Fotoupload mislukt bij het opslaan van een nieuw kavel | Eenduidig resultaat: geen onbedoelde gedeeltelijke save of onjuiste melding, geen verweesde foto's | **Code-inspectie: risico gevonden 18-10-2026** — lot-insert gebeurt vóór uploadvalidatie; niet getest, zie issue #5 |
| T-001B | FR-001 | Aanmelden met geldig beheerdersaccount geeft toegang tot kaveloverzicht | Adminoverzicht toegankelijk na geldige aanmelding; sessie blijft beperkt tot beveiligd HTTPS | **Functionele toegang geslaagd 18-10-2026**, gebruikersscreenshot op `/admin/index.php` toont ingelogde admin, 10 kavels en navigatie. **HTTPS-waarschuwing open (T-040), veilige sessie nog niet bevestigd.** |
| T-040 | NFR-007 / FR-001 | Controleer admin via correct HTTPS/certificaat én HTTP→HTTPS-doorverwijzing | Browser accepteert veilige verbinding met geldig certificaat voor `41lokeren.be`; HTTP-verzoeken redirecten naar HTTPS | **Tijdelijke redirect geslaagd, 18-10-2026** — oorspronkelijk HTTP `200 OK` zonder redirect. Na configuratiewijziging toont aangeleverde `curl -I`-uitvoer **`HTTP/1.1 302 Found`** plus **`Location: https://41lokeren.be/admin/login.php`**. Chrome valideerde het HTTPS-certificaat. **Aanvullende controles geslaagd 18-10-2026:** directe `https://41lokeren.be/admin/login.php` antwoordt `HTTP/2 200`; `curl -sSIL --max-redirs 3 http://41lokeren.be/admin/login.php` toont `302` met juiste `Location` gevolgd door `HTTP/2 200` (geen lus); `http://41lokeren.be/` geeft `302` naar `https://41lokeren.be/`. **Definitieve 301 geslaagd 18-10-2026:** herhaalde `curl -sSI`-opdrachten tonen `HTTP/1.1 301 Moved Permanently` met respectievelijk `Location: https://41lokeren.be/admin/login.php` en `Location: https://41lokeren.be/`. Het HTTPS-eindpunt en de tijdelijke keten waren eerder geslaagd. **Nog open:** controle extra adminroutes, uitloggen/sessiecookie en behoud van configuratie in Git; issue #6, [herstelplan](HTTPS_REMEDIATION.md). |
| T-041 | FR-001 / NFR-007 | Uitloggen uit actieve HTTPS-adminsessie en oude adminroute opnieuw openen | Na `Uitloggen` verschijnt loginpagina; een rechtstreeks bezoek aan `/admin/index.php` zonder nieuwe login toont geen kavels | **Gedeeltelijk geslaagd 18-10-2026** — na de instructie om uit te loggen toont gebruikersscreenshot het loginformulier op `/admin/login.php` (adresbalk zichtbaar). **Nog niet bewezen:** rechtstreeks opnieuw openen van `/admin/index.php` in dezelfde browsersessie zonder aanmelden; wacht op deze stap. |

## Registratie bij uitvoering

Gebruik per testronde dit sjabloon:

```text
Datum:
Omgeving: lokaal / test / productie (productie: alleen niet-destructieve tests)
Git-commit:
Test-ID:
Resultaat: geslaagd / mislukt / geblokkeerd
Bewijs / notities:
Actiehouder en eventuele follow-up:
```

**Let op:** voer T-012 t/m T-017, T-037 t/m T-039 en destructieve CSV-/CRUD-tests alleen uit met geschikte testdata of in een geïsoleerde testomgeving. Test geen risicovolle herstelactie rechtstreeks op productie.
