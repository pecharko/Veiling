# Code-audit — eerste GitHub-productiebaseline

**Datum:** 17 oktober 2026  
**Repository:** `pecharko/Veiling` (private), branch `main`  
**Methode:** statische inspectie van PHP/JavaScript en bestandspaden via GitHub. **Geen code uitgevoerd, geen live OVHcloud-toegang, geen database-dump of hersteltest.**

## Samenvatting

De hoofdfunctionaliteit is terug te vinden in de broncode. De huidige code bevat kavelbeheer, statusbeheer, publieke catalogus, CSV-import, bulkfoto-import, favorieten, veilinginformatie, inloggen, snapshots en opslagschatting.

Dit bewijst de **aanwezigheid van implementatielogica**, niet dat iedere functie op de actuele server foutloos werkt. De vereisten blijven voor acceptatietests op runtime **niet geverifieerd**.

## Traceerbaarheid — bestaande requirements

| Requirement(s) | Implementatiebestand(en) | Wat statisch is bevestigd | Wat nog getest moet worden |
|---|---|---|---|
| FR-001, FR-022 | `private/bootstrap.php`, `admin/login.php` | Sessie, auth-controle, CSRF, wachtwoordverificatie en IP-gebaseerde inlogbeperking in code | Login/logout, HTTPS, sessiegedrag en alle schrijfacties |
| FR-002, FR-004 | `admin/kavel.php` | Aanmaken, bewerken, verwijderen, titel-/nummer-/schattingsvalidatie | CRUD, foutgevallen en transacties |
| FR-003 | `admin/kavel.php`, `admin/import.php` | Duplicaatfout in kaveleditor; dubbele nummers **binnen één CSV** geweigerd; bestaande lotnummers via upsert bijgewerkt | **UNIQUE-index** op `lots.lot_number` en concurrency; schema ontbreekt |
| FR-005, FR-012 | `admin/kavel.php`, `admin/instellingen.php`, `index.php`, `kavel.php` | Status `draft/published` én globale `is_published` worden gecontroleerd in publiek overzicht en detail | Publicatie-/verbergingstest |
| FR-006, FR-007, FR-008 | `private/images.php`, `admin/kavel.php`, `index.php`, `kavel.php` | MIME/afmetingen/bestandsgrootte, max. 16; bewaren/verwijderen; volgorde `sort_order, id` | Uploadfouten, bestandsrechten, hoofdfoto |
| FR-009 | `admin/import.php` | CSV-headeraliases, delimiterdetectie, limiet 2.000, transacties en upsert; statuskolom optioneel | Importedgecases, UNIQUE-index en voorbeeld-CSV |
| FR-010 | `admin/fotos-bulk.php` | Verwerking max. 20 per actie; bestandsnaam bepaalt lotnummer/volgorde; statusmelding | SFTP-inbox en foutafhandeling |
| FR-011 | `admin/instellingen.php` | Formuliervelden, validatie naam/e-mail/datumformaat en SQL-update | Opslaan, correct herladen; kalenderdatumvalidering |
| FR-013, FR-014 | `index.php`, `kavel.php` | Catalogus-/detailpagina en expliciet offline biedproces | End-to-end publiek gedrag |
| FR-015 t/m FR-018 | `private/snapshots.php`, `admin/backup.php` | ZIP met vijf tabellen en foto's, manifest/checksums, downloaden, verwijderen, herstel met veiligheidskopie en transactie | **Consistente snapshot en veilige restore** (zie risico's) |
| FR-019, FR-020 | `private/snapshots.php`, `admin/backup.php` | Bestandsmeting, uitsplitsing, drempels 80/90%, schatting vóór snapshot | OVH-quotavergelijking, voldoende buffer bij compressie-/quota-afwijkingen |
| FR-021 | `private/bootstrap.php` | Link `Back-up & herstel` is opgenomen in `admin_top()` | UI op alle adminpagina's |
| FR-023 | Admin-PHP-bestanden, `private/bootstrap.php` | Flash-/foutmeldingen aanwezig | Duidelijkheid en geen gevoelige detaillekken |

## Aanvullende functies aangetroffen

- Publiek zoeken op nummer/titel/tekst, filter op categorie en schatting, sorteren, pagineren met 24 kavels per pagina: `catalogus-public/index.php`.
- Favorieten per browser via `localStorage` (geen account of synchronisatie): `catalogus-public/assets/catalogus.js`; queryparameters tonen selectie.
- Galerie met thumbnailwissel en delen van een kavel via native share/klembord: `catalogus-public/kavel.php`, `assets/catalogus.js`.
- Admin-kaveloverzicht met filters, paginering (50 per pagina), tellingen: `catalogus-public/admin/index.php`.
- Systeemcontrole voor PHP-extensies, toegang tot database, mappen en HTTPS: `catalogus-public/admin/systeemcheck.php`.

Voeg bij volgende wijziging nieuwe requirements en passende tests toe voor deze aanvullende functies.

## Prioriteiten en openstaande risico's

### P1 — Snapshot-/herstelconsistentie en testen vóór operationeel gebruik

**Code:** `private/snapshots.php`, `catalogus-public/admin/backup.php`.

- De vijf MySQL-tabellen worden in `snap_create()` met afzonderlijke `SELECT *`-opdrachten zonder expliciete consistente databaseleestransactie ingelezen. Tegelijkertijd bewerkte kavels en fotoverwijzingen kunnen daardoor onderling afwijken.
- De snapshotlock wordt alleen in snapshot-/herstelcode gebruikt; kavelbewerking, CSV-import en foto-import blokkeren niet op die lock. Er is dus geen applicatiebreed onderhouds-/schrijfslot tijdens snapshot of restore.
- Tijdens restore wordt de actieve uploadmap eerst omgewisseld en daarna de database bijgewerkt; de code probeert bij databasefouten terug te draaien. Gedrag bij onderbreking/crash en concurrente wijzigingen is **niet getest**.
- `disk_free_space` is op gedeelde hosting geen betrouwbare accountquotameting; de bestandsmeter gebruikt een nominale 1 GiB-limiet en kan ontoegankelijke bestanden missen.
- Downloadbare snapshots bevatten o.a. `admins.password_hash` en data uit `login_attempts`. Behandel ZIP's als vertrouwelijke databack-ups en bewaar ze beveiligd.

**Actie:** geïsoleerde, geautomatiseerde én handmatige hersteltesten met concurrerende bewerkingen/onderbroken uitvoer vóór vertrouwen op productieherstel. Geen live restore uitvoeren om dit te testen.

### P2 — Verwijzingen naar niet-geversioneerde bestanden

In de **repository** ontbreken:
- `catalogus-public/assets/placeholder.svg` — gebruikt door `index.php` en `kavel.php` wanneer een kavel geen foto heeft.
- `catalogus-public/assets/voorbeeld-kavels.csv` — downloadlink in `admin/import.php`.
- `sql/install.sql` — genoemd in `admin/systeemcheck.php` bij ontbrekende DB.
- `catalogus-public/admin/setup.php` — gebruikt door `admin/login.php` als nog geen admin bestaat (mogelijk bewust verwijderd na installatie).

Deze bestanden kunnen wel nog op OVHcloud staan; **dat is niet gecontroleerd**. Voeg noodzakelijke niet-gevoelige assets/schema-documentatie toe of pas de verwijzingen aan. Laat `setup.php` niet zomaar publiek actief staan.

### P2 — Databaseconstraint op unieke lotnummers (UPDATE 18-10-2026: bevestigd)

**Update 18 oktober 2026:** via aangeleverde phpMyAdmin-uitvoer van `SHOW INDEX FROM lots;` is bevestigd dat `lot_number` een afzonderlijke unieke BTREE-index heeft (`Key_name=lot_number`, `Column_name=lot_number`, `Seq_in_index=1`, `Non_unique=0`). MySQL verhindert daardoor dubbele lotnummers. Een schemawijziging is niet nodig. De repository heeft nog geen geversioneerd databaseschema en functionele tests voor de PHP-foutmelding, CSV-upsert en gelijktijdige bewerkingen staan open. Deze bevinding actualiseert de eerdere risico-inschatting van 17 oktober.

### P3 — Validatie details en overige regressiepunten

- `admin/instellingen.php` controleert het datumformaat `YYYY-MM-DD`, maar niet expliciet of de datum kalendergeldig is.
- `admin/login.php` verwijst bij een lege `admins`-tabel naar een ontbrekende setup-route. Bepaal en documenteer herstel/toegangsprocedures.
- `private/config.example.php` bevat alleen voorbeeldwaarden in de gecontroleerde versie; dit bewijst niet dat ieder ander bestand vrij is van secrets.
- De browserfunctionaliteit en responsive layout zijn statisch aanwezig, maar niet interactief getest.

## Besluit en vervolg

1. Productiebroncode is als bestanden in GitHub terug te vinden. **Runtimecorrectheid niet geverifieerd.**
2. Geen productiecode aangepast tijdens deze audit.
3. Werk requirements/statussen, testplan en changelog bij op basis van deze bevindingen.
4. Verifieer op OVHcloud het actuele schema, de ontbrekende bestanden, de uploads-beveiliging en opslagquota.
5. Test en verbeter herstelconsistentie vóór back-ups als enige herstelvoorziening te gebruiken.

Alle externe productie-/databasecontroles moeten eerst expliciet worden uitgevoerd; dit document is geen penetratietest of formele securitycertificering.
