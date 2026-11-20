# Technische architectuur — 41 Lokeren

**Status 17 oktober 2026:** gebaseerd op de op GitHub geïmporteerde applicatiebroncode. Statische code-inspectie is uitgevoerd; dit is **geen runtimecontrole** van OVHcloud, database of configuratie. Zie [code-audit](CODE_AUDIT_2026-10-17.md).

## Hosting en runtime

- Hosting: OVHcloud webhosting met domein <https://41lokeren.be>.
- Server-side: PHP; data: MySQL.
- Deploy: momenteel handmatig via RemoteDrive/FTP-achtige bestandskoppeling.
- In de geïmporteerde repository zijn geen Composer/buildpipeline-bestanden of automatische deploymentworkflows aangetroffen.
- MySQL-back-up en webbestanden/foto's moeten apart worden veiliggesteld. Hostingopslag en databasequota kunnen verschillend gemeten worden.

## Bekende projectindeling

```text
catalogus-public/
  index.php             # Publieke catalogus (bevestigen)
  kavel.php             # Publieke kavelpagina (bevestigen)
  admin/
    index.php           # Kaveloverzicht
    kavel.php           # Kaveleditor
    import.php          # CSV-import
    fotos-bulk.php      # Foto-import
    instellingen.php    # Veilinginformatie
    systeemcheck.php    # Systeemcheck
    backup.php          # Back-up & herstel (v1.4+)
    admin.css           # Adminstijl
  uploads/              # Productiefoto's: NIET committen
private/
  bootstrap.php         # Sessies, toegang, helpers, adminmenu
  images.php            # Fotoverwerking
  snapshots.php         # Snapshotlogica (v1.4+)
  config.php            # Productiesecrets: NIET committen
  backups/              # Snapshotarchief: NIET committen
```

**Opmerking:** de GitHub-baseline is geïmporteerd vanuit een lokale OVHcloud-kopie; eventuele door `.gitignore` uitgesloten productie-/uploadbestanden en later op de server gewijzigde bestanden ontbreken.

## Bekende databasetabellen

`admins`, `login_attempts`, `lots`, `lot_images`, `settings`.

**De geverifieerde schema's van alle vijf tabellen staan in [DATABASE.md](DATABASE.md)** (bron: phpMyAdmin `SHOW CREATE TABLE`, 18 oktober 2026). `lots.lot_number` heeft een unieke index; `lot_images.lot_id` verwijst met `fk_lot_image` naar `lots.id` en gebruikt `ON DELETE CASCADE`. De samengestelde `uq_source(lot_id,source_name)` geldt voor niet-NULL bronbestandsnamen; foto-volgorde via `idx_img(lot_id,sort_order,id)`. Let op: databasecascade verwijdert geen fysieke fotobestanden; de PHP-opruimlogica vereist een regressietest. Voor `settings` is bevestigd: `id` als primaire sleutel, nullable `event_date`, standaardwaarde `is_published=0` en een automatisch wijzigingstijdstip. De applicatie gebruikt `settings.id=1` als conventie; de database dwingt geen enkelrijige tabel af. Voor `admins` is bevestigd: unieke e-mailindex, `password_hash` en een aanmaakdatum. Er is geen veld voor rollen of individuele machtigingen; autorisatie door de applicatie apart toetsen. Voor `login_attempts` is bevestigd: `id` als primaire sleutel, `ip_hash CHAR(64)` en samengestelde index `idx_attempts(ip_hash,attempted_at)` voor recente mislukte inlogpogingen. De PHP-code gebruikt een IP-hash en controleert een venster van 15 minuten met een grens van 8 mislukkingen; daadwerkelijk rate-limitinggedrag en opschoning moeten nog getest worden. Alle DDL's zijn nu gedocumenteerd. Publiceer geen productiegegevens, inlog- of passwordhashwaarden.

## Beveiligingsgrenzen

1. Adminroutes eisen authenticatie en beschermen schrijfacties met CSRF.
2. Serverbestanden met database-inloggegevens staan buiten de publieke webroot.
3. Fotouploads worden server-side gevalideerd (mime, formaat, limiet, bestandsnaam).
4. Snapshotarchief en logs bevatten mogelijk gevoelige informatie en blijven buiten GitHub en buiten de publieke site.
5. Bij publicatie worden alleen toegestane kavels getoond, afhankelijk van status per kavel en algemene zichtbaarheid.

## Actueel bekende ontbrekende bestanden en verificatie

- Exacte PHP- en MySQL-versies en benodigde PHP-extensies, waaronder `ZipArchive`.
- Actuele `private/bootstrap.php` en wijzigingen aan admin-menu na hotfix v1.5.1.
- Actuele `private/snapshots.php`: transactie-, herstel- en opslagstrategie.
- Werkelijke OVHcloud-hostingopslag en gemeten vrije ruimte.
- Ervoor zorgen dat referenties in `private/config.php` niet in foutlogs terechtkomen.
- Match tussen bestanden op OVHcloud en toekomstige GitHub-baseline.

Werk dit document bij zodra de echte productiebroncode is veilig ingelezen.

## Publieke functionaliteit in code

- `catalogus-public/index.php`: zoeken, categorie-/prijsfilter, sorteren, pagineren, globale publicatievlag, kavelstatus.
- `catalogus-public/kavel.php`: detail, galerij en vorig/volgend kavel.
- `catalogus-public/assets/catalogus.js`: favorieten via browser-`localStorage` en delen van kavel.
- `catalogus-public/admin/import.php`: historische CSV-import, momenteel uitsluitend HTTP 404 voor GET/POST; geen actieve upsert.
- `private/snapshots.php`: ZIP-back-ups, hashes en restore; **consistentie bij gelijktijdige schrijfacties is nog niet bewezen**.

## Bestanden die in GitHub ontbreken

**Actuele stand issue #4 (20 november 2026; bovenstaande audit van 17 oktober is historisch):**

- `catalogus-public/assets/placeholder.svg` is aanwezig in Git en werd op 19 oktober als statisch asset op OVH technisch geverifieerd; de eigenaar bevestigde daarna de fotoloze-kavelweergave (T-028).
- `catalogus-public/assets/voorbeeld-kavels.csv` ontbreekt **bewust**: CSV-import is uitgeschakeld; `admin/import.php` retourneert 404 voor GET én POST (T-042). Niet opnieuw toevoegen zonder nieuwe productbeslissing.
- `sql/install.sql` bestaat niet. De geauthenticeerde `admin/systeemcheck.php` verwijst bij een databasefout naar een interne operatorprocedure in plaats van een fictief installatiebestand. Zie [ADMIN_RECOVERY.md](ADMIN_RECOVERY.md).
- `catalogus-public/admin/setup.php` bestaat terecht niet. De login weigert een lege administratorlijst met 503/no-store; nooit een publieke setup- of noodlogin toevoegen.
- `catalogus-public/uploads/.htaccess` en gebruikersfoto's zijn uitgesloten van Git; uploads-beveiliging op productie blijft afzonderlijk te controleren.

Zie het [auditrapport](CODE_AUDIT_2026-10-17.md) voor impact en opvolging.
