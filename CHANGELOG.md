# Wijzigingslogboek

Dit logboek volgt de wijzigingen in de GitHub-repository. Historische applicatieversies hieronder zijn **overgenomen uit eerdere projectbesprekingen**; zonder import van productiebroncode zijn ze **niet onafhankelijk geverifieerd**.

## [Unreleased]

### GitHub Actions → OVHcloud SFTP-deploy (nog niet op productie uitgevoerd)
- FR-030/T-043: handmatig startbare GitHub Actions-workflow toegevoegd met een offline `plan`-modus, aparte bevestigde `deploy`-modus, SFTP met vooraf geverifieerde SSH-hostsleutel en repositorysecrets.
- De workflow is beperkt tot de vijf PHP-bestanden van de CSV-disable-release. Ze haalt de huidige versies van OVHcloud eerst op in een kort bewaard privé-back-upartifact, verifieert hashes en probeert bij uploadfouten de vorige bestanden te herstellen.
- HTTP-smoketests eisen 404 voor GET en lege POST op `admin/import.php` na de upload. Geen automatische uitrol bij push; er zijn nog geen SFTP-credentials ingesteld of remote acties uitgevoerd.

### CSV-import tijdelijk verwijderd uit admin (nog niet uitgerold)
- FR-009 en FR-029 op verzoek tijdelijk uitgeschakeld. Menulink en dashboardknop verdwijnen, oude route `catalogus-public/admin/import.php` is vervangen door een minimale handler die altijd HTTP 404 retourneert, ook voor POST (zonder databaseverbinding of schrijfopdracht).
- Actief CSV-voorbeeldbestand verwijderd; oorspronkelijke parser en voorbeeld blijven in de Git-geschiedenis herstelbaar. Foto-import blijft behouden; tekst op systeemcheck en backup aangepast.
- Regressiecontrole T-042 toegevoegd: ontbrekende links, 404 bij GET en POST, foto-import nog aanwezig. **Uitrol naar OVHcloud vereist**; louter GitHub aanpassen wijzigt productie niet.

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
