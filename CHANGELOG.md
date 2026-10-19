# Wijzigingslogboek

Dit logboek volgt de wijzigingen in de GitHub-repository. Historische applicatieversies hieronder zijn **overgenomen uit eerdere projectbesprekingen**; zonder import van productiebroncode zijn ze **niet onafhankelijk geverifieerd**.

## [Unreleased]

### Fase 2 snapshotveiligheid — gedeelde MySQL-mutex voor adminmutaties (PR in voorbereiding)
- Nieuwe privé-helper `private/catalog_mutex.php` coördineert alle appadmin-writes via connection-scoped `GET_LOCK`: kavels, foto's, veilinginstellingen, loginpogingen; niet via netwerkfilesysteem-`flock`.
- `snap_create()` houdt de mutex vast van DB-selects tot na het sluiten van de foto-ZIP. `snap_restore()` beschermt de herstelbewerking; `admin/backup.php` houdt dezelfde, herhaald verworven mutex ook vast **tussen** de voorafgaande veiligheidssnapshot en het herstel.
- Bij bezette mutex krijgt een admin-POST na vijf seconden een fail-closed HTTP 503; geen gedeeltelijke writes. Extra T-051-verificatie van lockisolatie, genest gebruik, vrijgave na SIGKILL, en een geblokkeerde HTTP-kavel-POST zonder DB-mutatie. **Geïsoleerd getest en geslaagd:** [snapshot-CI-run #39321531424](https://github.com/pecharko/Veiling/actions/runs/39321531424) en [kavel-CI-run #39321530958](https://github.com/pecharko/Veiling/actions/runs/39321530958).
- **Niet opgelost:** publieke lezingen tijdens restore, externe DB/SFTP-schrijfacties zonder mutex, een persisterende hersteljournal en SIGKILL na fotoswap. Er is **geen** productiedeploy; handmatige review vereist.

### Deelverbetering snapshots: één consistente MySQL-read-view (PR #31 gemerged, niet op productie)
- `snap_create()` leest de vijf tabellen binnen één eigen `REPEATABLE READ`-transactie en sluit deze vóór het archiveren van foto's. Een al actieve PDO-transactie wordt uit voorzorg geweigerd.
- T-050A is omgevormd tot een veiligheidstest: een gelijktijdige atomaire update levert een consistent oud paar van `settings` en `lots` op; ook transactieafsluiting en afwijzing van geneste transacties getest. **Geïsoleerde snapshot-CI geslaagd:** [run #39319327080](https://github.com/pecharko/Veiling/actions/runs/39319327080).
- **Fase-1-beperkingen:** nog geen app-brede foto-/schrijflock of crashbestendige restore bij het mergen van #31; in de daaropvolgende fase-2-PR wordt de applicatiegecoördineerde mutex voorgesteld, maar het crashherstel blijft open. T-050B blijft een bewust geslaagde risicoreproductie. Niet op productie uitgerold; wijzigingen aan snapshotcode vragen handmatige review en nieuwe specifieke deployautorisatie.

### Snapshotconsistentie en SIGKILL-onderzoek — 19-10-2026 (alleen CI/test)
- Eerste reproduceerbare onderzoekstests T-050A (gemengde MySQL-read-momenten) en T-050B (SIGKILL na fotoswap vóór DB-herstel) toegevoegd voor issue #2, uitsluitend op fictieve GitHub Actions/MySQL-data.
- De tests instrumenteren alleen tijdelijke kopieën van `private/snapshots.php` in een afzonderlijk PHP-testproces; bestaande productiecode, foto's en snapshots zijn ongewijzigd.
- Ontwerpkeuzes en veilige herstelstrategie beschreven in `docs/SNAPSHOT_CONSISTENCY_CRASH_STUDY_2026-10-19.md`. **Deze tests detecteren een risico, zij verhelpen het niet.** Een uitvoerbare productieoplossing vraagt een afzonderlijke beveiligingsreview.

## Productierelease — 19 oktober 2026 (gecontroleerd en technisch geverifieerd)

- Audit-PR's #25 (regressies + oude Fotobulk-tekst), #26 (kavelfoto-placeholder), #27 (POST+CSRF-uitloggen) en bijbehorende handmatige release-PR #28 zijn gemerged.
- **Exacte productiecommit:** `8b2e6b8f444f67952620163c523e04c5807a032e` via `audit-release-deploy.yml`; [GitHub Actions-run #39203389883](https://github.com/pecharko/Veiling/actions/runs/39203389883) geslaagd op 19-10-2026, branch `main`. Slechts zes goedgekeurde runtimepaden overgezet en SHA-256 geverifieerd.
- **Back-up:** vijf bestaande PHP/CSS-bestanden vooraf gekopieerd; de nieuwe `assets/placeholder.svg` was nog afwezig en is als nieuw vastgelegd. Privé rollbackartifact `ovh-audit-pr25-26-27-before-39203389883`, bewaartermijn tot 26-10-2026.
- **Technische productiecontrole:** homepage, login en placeholder HTTP 200; beschermde adminroutes en anonieme logout HTTP 303; oude foto- en CSV-import GET en lege POST HTTP 404. Alle checks groen. Geen database-, kavelfoto-, snapshots-, `uploads/`- of configuratiewijzigingen.
- **Functionele acceptatie 19-10-2026:** beheerder bevestigt na checklist voor fotoloze kavelplaceholder, ingelogd uitloggen/heropenen en behoud van de fotouploadfunctie: 'alles werkt'. Geen afzonderlijke screenshots of destructieve productieproeven; de automatisering testte uitsluitend geïsoleerde geauthenticeerde HTTP-scenario's en read-only productie-HTTP. Zie T-028/T-049. Mobiel/toetsenbord en crashbestendige snapshot-restore blijven buiten deze release.


### Kwaliteitsronde en regressiebewaking — 19-10-2026 (PR #25 gemerged, daarna uitgerold)
- Broncode-audit van authenticatie, kaveleditor, fotoupload, snapshotherstel en deployment met prioriteiten P1/P2/P3 vastgelegd in `docs/QUALITY_AUDIT_2026-10-19.md`. Bestaand snapshot-consistentierisico blijft open (#2); gedeeltelijke foto-opslag, GET-logout en beheerderssessieduur vergen opvolging.
- Oude verwijzingen naar de niet-ondersteunde foto-import verwijderd uit de kaveleditor en de backupintroductie.
- Bestaande PHP-CI uitgebreid van zes broncodecontroles voor CSV-import naar dertien controles voor CSV, bulkfoto-import, adminnavigatie en behoud van de individuele fotoupload (T-048). Deze wijziging is in de hierboven geregistreerde productie-auditrelease opgenomen.

### Bulkfoto-import definitief uitgeschakeld (19-10-2026; eerder op productie uitgerold)
- Op verzoek is de niet te ondersteunen bulkfoto-import via SFTP uit de adminnavigatie verwijderd. De oude route `admin/fotos-bulk.php` geeft voor GET en POST uitsluitend HTTP 404, zonder databaseverbinding, fotoverwerking of verwijdering van bestanden.
- Verouderde systeemcontrole op `private/photo-inbox/` en de SFTP-bulkimporttip verwijderd. Reeds aanwezige bestanden in de inbox blijven onaangeroerd.
- Upload en beheer van foto's binnen afzonderlijke kavels blijft beschikbaar. Geïsoleerde HTTP-regressietests uitgebreid; T-047 toegevoegd. **De eerdere gerichte Foto-import-retirementrelease is inmiddels geslaagd en door de beheerder visueel bevestigd; de auditrelease controleert opnieuw de oude import-URL's.**

### Modern kaveloverzicht (PR in voorbereiding, niet live)
- Dubbele actieknoppen onder de statistieken verwijderd; hoofdnavigatie bleef destijds de ingang voor nieuw kavel, foto-import en veilinginformatie (foto-import later uitgefaseerd).
- Vier moderne statistiekkaarten, responsieve tabel, statusbadges, duidelijkere bewerkactie en gecombineerde zoek-/categorie-/statusfilters.
- Alleen admin-dashboard PHP en scoped CSS gewijzigd; geen database- of fotoaanpassingen. Productie-uitrol vereist aparte gecontroleerde SFTP-release.


### Gerichte SFTP-deployment van opslagcorrecties T-016/T-017 (voorbereid, niet live)
- Dedicated handmatige GitHub Actions-release voor twee PHP-bestanden, in veilige volgorde `private/snapshots.php` daarna `catalogus-public/admin/backup.php`.
- Vooraf controle van projectroot, codeback-up van beide bestanden als zeven dagen privé artifact, serverdrift-controle en SHA-256-verificatie; bij uploadfout best-effort code-rollback.
- Na handmatig goedgekeurde upload alleen read-only HTTP-controles (homepage, login, back-up achter login, CSV nog steeds 404). **Geen automatische productie-uitrol, geen MySQL-/datasnapshot-wijzigingen.**

### Opslagwaarschuwingen en snapshot-capaciteit — T-016/T-017
- **T-016/T-017: 29/29 controles geslaagd** in geïsoleerde CI ([run 39126901647](https://github.com/pecharko/Veiling/actions/runs/39126901647)), met gesimuleerde opslaglimieten: niveaus rond 80% (waarschuwing) en 90% (kritiek), schijf- en quota-preflight inclusief 20 MiB veiligheidsmarge, echte snapshotcreatie met te lage/volledige testquota.
- Onvolledige bestandsmeting mag de capaciteitspreflight niet langer onbedoeld overslaan: een nieuw snapshot wordt dan uit voorzorg geweigerd.
- Adminpagina gebruikt dezelfde opslagniveau-classificatie als de CI-test. Geen wachtwoorden of productiegegevens in de tests; alleen disposable MySQL/PHP. **Uitrol op OVHcloud niet uitgevoerd.**

### Back-up & herstel — 40 geïsoleerde functionele controles geslaagd (18-10-2026)
- Nieuwe GitHub Actions-suites met fictieve kavels/foto's op tijdelijke MySQL 8.4 en PHP 8.2, **zonder productiegegevens** en zonder gewijzigde applicatiecode. Snapshot-ZIP's bevatten databasegegevens, manifest en kavelfoto's, **geen sourcecode**.
- Snapshotcreatie, volledige herstelcyclus, SHA-256-integriteit, ZIP-grootte en opslagoptelling, lock en padcontrole getest (20 checks).
- Daarnaast de echte geauthenticeerde beheerpagina getest: toegang, download, create, delete, restore, veiligheidskopie, CSRF en sessie-invalidering (20 checks). [CI-run 39112431040](https://github.com/pecharko/Veiling/actions/runs/39112431040).
- **Openstaande risico's (issue #2):** niet gegarandeerd consistente snapshot bij concurrente kavel- en fotowijzigingen; crash tussen foto- en databasetransacties; werkelijke OVHcloud-opslagquota nog niet geverifieerd; de 80/90%-drempels zijn in de nieuwe PR #11 uitsluitend gesimuleerd. **Geen herstelactie op productie uitgevoerd.**

- **Kaveleditor PR #7:** werkelijke formulier- en fotouploadflows gevalideerd tegen geïsoleerde MySQL 8.4 en localhost PHP, GitHub Actions-run 39109809899 geslaagd. De afzonderlijke handmatige SFTP-workflow heeft beide editorbestanden naar OVHcloud uitgerold. GitHub Actions-job `ovh-kavel` is geslaagd, inclusief back-upartifact en rooktest voor loginbeveiliging en uitgeschakelde CSV-import (screenshot 18-10-2026). De beheerder heeft na de visuele nieuw-kavelcontrole op 18-10-2026 gemeld dat alles goed lijkt te werken; gebruikersacceptatie vastgelegd, zonder destructieve productietests.

### PR #7 — isolatietests en handmatige productie-uitrol geslaagd (18-10-2026)
- Formulierwaarden blijven staan bij validatie- of databankfout via `lot_form_values()`; uploadstatus wordt vóór INSERT/UPDATE gecontroleerd. Alleen deze kaveleditorcorrectie en tests zijn overgenomen, zonder de bestaande CSV-uitschakeling of SFTP-deployment terug te draaien.
- PHP-unitchecks toegevoegd aan de bestaande GitHub Actions-controle. Echte integratietests op een geïsoleerde MySQL-database en een afzonderlijk goedgekeurde editor-deployment moeten nog volgen.

### Eerste automatische OVHcloud SFTP-deploy geslaagd — 2026-10-18
- FR-030/T-043: handmatig startbare GitHub Actions-workflow toegevoegd met een offline `plan`-modus, aparte bevestigde `deploy`-modus, SFTP met vooraf geverifieerde SSH-hostsleutel en repositorysecrets.
- De workflow is beperkt tot de vijf PHP-bestanden van de CSV-disable-release. Ze haalt de huidige versies van OVHcloud eerst op in een kort bewaard privé-back-upartifact, verifieert hashes en probeert bij uploadfouten de vorige bestanden te herstellen.
- HTTP-smoketests eisen 404 voor GET en lege POST op `admin/import.php` na de upload. Geen automatische uitrol bij push. Op 18-10-2026 heeft de gebruiker een expliciete `deploy` gestart; SFTP-hostsleutel, back-upartifact, uploads, checksums en GET-/POST-404-smoketests slaagden ([run 39109055677](https://github.com/pecharko/Veiling/actions/runs/39109055677)).

### CSV-import tijdelijk verwijderd uit admin (productie uitgerold)
- FR-009 en FR-029 op verzoek tijdelijk uitgeschakeld. Menulink en dashboardknop verdwijnen, oude route `catalogus-public/admin/import.php` is vervangen door een minimale handler die altijd HTTP 404 retourneert, ook voor POST (zonder databaseverbinding of schrijfopdracht).
- Actief CSV-voorbeeldbestand verwijderd; oorspronkelijke parser en voorbeeld blijven in de Git-geschiedenis herstelbaar. Foto-import blijft behouden; tekst op systeemcheck en backup aangepast.
- Regressiecontrole T-042 toegevoegd: ontbrekende links, 404 bij GET en POST, foto-import nog aanwezig. **Uitrol via GitHub Actions-run 39109055677 geslaagd** op 18-10-2026: vijf PHP-bestanden via SFTP geplaatst, oude versies in herstelartifact bewaard, GET en lege POST naar import.php geven HTTP 404. Ook visueel bevestigd via productie-adminscreenshot: CSV-import ontbreekt in hoofdmenu en dashboard, links naar Foto-import, kavelbeheer en Back-up & herstel zijn aanwezig en 10 kavels staan nog in het overzicht. **T-042 geslaagd**; werking van de andere functies wordt afzonderlijk getest.

### CSV-voorbeeldbestand hersteld — 2026-10-18
- In de productie-admin mislukt de knop `Download voorbeeld-CSV`: Chrome probeert `voorbeeld-kavels.html` op te slaan en meldt dat het bestand niet beschikbaar is. De link in `admin/import.php` wees naar een statisch CSV-bestand dat niet in Git stond.
- `catalogus-public/assets/voorbeeld-kavels.csv` toegevoegd met exact de negen importkolommen, UTF-8-BOM en twee duidelijk fictieve, **niet-gepubliceerde** voorbeeldkavels. CSV-parser van `admin/import.php` gecontroleerd op kolomnamen en puntkommascheiding. Geen wijzigingen aan de PHP-importfunctie.
- **Uitrol nog nodig:** bestand uploaden naar `/catalogus-public/assets/` op OVHcloud. Productiedownload en import zijn nog **niet** opnieuw getest. T-028 nog open; issue #4 gedeeltelijk opgevolgd.

### Testvoorbereiding — 2026-10-18
- Fase-1-regressiecontrole van aanmelden, kavelbeheer en CSV-import op basis van huidige GitHub-PHP-broncode vastgelegd in `docs/REGRESSION_2026-10-18.md`. Geen geauthenticeerde of muterende productietests uitgevoerd.
- Niet-destructieve GitHub Actions-workflow toegevoegd voor PHP 8.2-syntaxcontrole bij push/pull request (`.github/workflows/php-lint.yml`); workflowresultaat nog niet als geslaagd bevestigd.
- [Issue #5](https://github.com/pecharko/Veiling/issues/5) geopend voor verlies van kavelinvoer na fout en risico op gedeeltelijk opslaan bij foutieve fotoupload; T-038/T-039 opgenomen. Geen productiecode gewijzigd.

### Beveiligingscontrole — 2026-10-18
- Gebruiker kan na geldige aanmelding het ingelogde kaveloverzicht openen (T-001B). Screenshot toont 10 kavels en adminnavigatie, inclusief Back-up & herstel.
- **P1 issue #6 (tijdelijke redirect actief):** aanvankelijk antwoordde HTTP-login met `200 OK` zonder redirect. De gebruiker testte na OVHcloud-aanpassing opnieuw: `302 Found` en `Location: https://41lokeren.be/admin/login.php`. Vervolgcontrole geslaagd: directe HTTPS-login `HTTP/2 200`, loginredirectketen `302`→`200` zonder lus en homepage `302` naar HTTPS. Permanente 301 voor HTTP-login en homepage vervolgens via `curl -sSI` bevestigd (`301 Moved Permanently` met HTTPS-Location). Visuele logout-/heropencontrole T-041 geslaagd; technische cookiecontrole en aanvullende adminroutes blijven open. De door de gebruiker gedeelde, werkende permanente redirectregels zijn met verduidelijkte commentaarregel geversioneerd in `catalogus-public/.htaccess`. Zie `docs/HTTPS_REMEDIATION.md`. Productieconfiguratie door gebruiker aangepast; geen PHP- of databasewijzigingen door de assistent.
- Afzonderlijk synchronisatieprobleem Mac: GitHub weigert accountwachtwoorden voor Git over HTTPS. Gebruik GitHub Desktop-browseraanmelding, Git Credential Manager of GitHub CLI; geen productiecode gewijzigd.

### Uitlogcontrole — 2026-10-18
- T-041 geslaagd (visuele functionele test): na logout toont Chrome `/admin/login.php`; na opnieuw openen van de beveiligde `/admin/index.php` in hetzelfde gewone browservenster zonder aanmelding verschijnt opnieuw het loginformulier. Technische cookie-/sessiestorevalidatie nog niet uitgevoerd. Geen productiecode gewijzigd.

### Browsertest — 2026-10-18
- T-001 (FR-001): drie incognitocontroles voor kaveloverzicht (`/admin/index.php`), CSV-import (`/admin/import.php`) en kaveleditor (`/admin/kavel.php`) tonen loginformulier zonder afgeschermde inhoud. Op derde screenshot is de uiteindelijke URL `/admin/login.php` zichtbaar. Geldige login/logout en andere beveiligde routes blijven te testen. Geen gegevens gewijzigd.

### Gecontroleerd — 2026-10-18
- FR-003: de gebruiker bevestigde via phpMyAdmin (`SHOW INDEX FROM lots;`) een afzonderlijke UNIQUE-index op `lots.lot_number` (`Non_unique=0`). Geen databasewijziging vereist. T-026 geslaagd; kaveleditor-/CSV-/concurrencytests blijven open. De DDL van alle vijf tabellen is inmiddels gedocumenteerd.
- FR-003: phpMyAdmin-duplicatenquery met `GROUP BY lot_number HAVING COUNT(*) > 1` gaf nul rijen terug. T-030 geslaagd; geen bestaande duplicaten.
- FR-003: volledige `SHOW CREATE TABLE lots;`-uitvoer bevestigd en (zonder huidige `AUTO_INCREMENT`-tellerstand) gedocumenteerd in `docs/DATABASE.md`. T-031 geslaagd. Geen wijziging aan productiedatabase.
- FR-007/008/010: `SHOW CREATE TABLE lot_images;` bevestigd: foreign key `fk_lot_image` met `ON DELETE CASCADE`, index voor fotovolgorde en unieke niet-NULL bronnaam per kavel. DDL toegevoegd aan `docs/DATABASE.md`, T-032 geslaagd. Fysieke fotobestanden worden niet door MySQL verwijderd; T-033 staat open.
- FR-011/012: `SHOW CREATE TABLE settings;` bevestigd, inclusief optionele veilingdatum en verborgen standaardpublicatiestatus (`is_published=0`). De singletonconventie `id=1` is een applicatieafspraak, geen databaseconstraint. Schema vastgelegd in `docs/DATABASE.md`; T-034 geslaagd, T-006/T-010 nog open. Geen wijzigingen aan productie.
- FR-001: `SHOW CREATE TABLE admins;` bevestigd met unieke e-mailindex en verplichte `password_hash`. Schema vastgelegd in `docs/DATABASE.md`; T-035 geslaagd. Geen rol-/permissievelden aangetroffen; login-/autorisatietests blijven open. Productiedatabase ongewijzigd.
- FR-001: `SHOW CREATE TABLE login_attempts;` bevestigd: ASCII IP-hash van 64 tekens, timestamp en `idx_attempts(ip_hash,attempted_at)`. DDL van alle vijf tabellen nu in `docs/DATABASE.md`; T-036 geslaagd. Inloglimiet/opschoning (T-037) nog niet functioneel getest; geen productiecode of MySQL gewijzigd.

### Toegevoegd — 2026-10-17
- Private GitHub-repository ingericht als toekomstige broncode- en documentatiebasis.
- Beveiligende `.gitignore` voor productiedata, geheime configuratie, snapshots en uploads.
- Functionele requirementsbaseline in `docs/REQUIREMENTS.md`.
- Documentatie voor architectuur, lokale kopie, deploy- en herstelproces, en testplan.

- Productiebroncode (PHP, CSS, JS en statische ontwerpassets) door de gebruiker vanuit OVHcloud naar `main` gepusht; belangrijke bestanden via GitHub gecontroleerd.
- Eerste statische broncode-audit: `docs/CODE_AUDIT_2026-10-17.md`, inclusief traceerbaarheid en risico's.
- Requirementsregister bijgewerkt naar **28 functionele requirements**, met nieuwe beschrijvingen voor favorieten, filters, galerie, adminoverzicht en systeemcheck; implementatiestatus onderscheiden van echte tests.

### Nog te doen
- Controleren of de geïmporteerde GitHub-code nog exact overeenkomt met alle bestanden op OVHcloud (uitgesloten uploads/configuratie buiten Git blijven).
- Vereisten en releasehistoriek toetsen aan de daadwerkelijke code.
- Back-up-/herstelmodule in een testomgeving valideren.

## Historisch (gerapporteerd, nog te verifiëren)

- **v1.5.1** — Menukoppeling 'Back-up & herstel' in adminomgeving.
- **v1.5** — Opslagdashboard, snapshotgroottes en meldingen bij beperkte ruimte.
- **v1.4** — Back-up & herstel van database en foto's.
- **v1.3.1** — Verbeteringen aan het kavelbeheerformulier.
- Eerdere versies: initiële veilingcatalogus, admin, CSV- en fotobeheer.

Bewaar daadwerkelijke deploymentdata en commit-SHA's vanaf de eerste geïmporteerde productiebaseline.
