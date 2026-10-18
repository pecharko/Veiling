# Wijzigingslogboek

Dit logboek volgt de wijzigingen in de GitHub-repository. Historische applicatieversies hieronder zijn **overgenomen uit eerdere projectbesprekingen**; zonder import van productiebroncode zijn ze **niet onafhankelijk geverifieerd**.

## [Unreleased]

### Testvoorbereiding — 2026-10-18
- Fase-1-regressiecontrole van aanmelden, kavelbeheer en CSV-import op basis van huidige GitHub-PHP-broncode vastgelegd in `docs/REGRESSION_2026-10-18.md`. Geen geauthenticeerde of muterende productietests uitgevoerd.
- Niet-destructieve GitHub Actions-workflow toegevoegd voor PHP 8.2-syntaxcontrole bij push/pull request (`.github/workflows/php-lint.yml`); workflowresultaat nog niet als geslaagd bevestigd.
- [Issue #5](https://github.com/pecharko/Veiling/issues/5) geopend voor verlies van kavelinvoer na fout en risico op gedeeltelijk opslaan bij foutieve fotoupload; T-038/T-039 opgenomen. Geen productiecode gewijzigd.

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
