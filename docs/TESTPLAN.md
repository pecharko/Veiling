# Testplan — veilingcatalogus 41 Lokeren

**Status 17 oktober 2026:** testcases bij de GitHub-broncode gecontroleerd en uitgebreid; **geen tests uitgevoerd of geslaagd verklaard**. Vul bij uitvoering datum, omgeving, commit, resultaat en opmerkingen in.

| Test-ID | Requirement | Testscenario | Verwacht resultaat | Uitvoering |
|---|---|---|---|---|
| T-001 | FR-001 | Adminpagina openen zonder login | Login vereist, geen afgeschermde informatie zichtbaar | Niet uitgevoerd |
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

**Let op:** voer T-012 t/m T-017 alleen uit met geschikte testdata of in een geïsoleerde testomgeving. Test geen risicovolle herstelactie rechtstreeks op productie.
