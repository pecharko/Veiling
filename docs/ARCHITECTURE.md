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

Er staat geen geversioneerd schema in de repository. Verifieer volledige schema's, indexen (**UNIQUE op `lots.lot_number`**), foreign keys en kolomtypen met een **geschoonde** schema-export of metadataquery. Publiceer geen productiegegevens of inlog-/passwordhashwaarden.

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
- `catalogus-public/admin/import.php`: CSV-upsert op lotnummer, voorwaardelijk publiceren.
- `private/snapshots.php`: ZIP-back-ups, hashes en restore; **consistentie bij gelijktijdige schrijfacties is nog niet bewezen**.

## Bestanden die in GitHub ontbreken

- `catalogus-public/assets/placeholder.svg` en `catalogus-public/assets/voorbeeld-kavels.csv` (wel door PHP gebruikt).
- `sql/install.sql` en `catalogus-public/admin/setup.php` (wel vanuit systeemcheck/login genoemd; setup kan bewust na installatie verwijderd zijn).
- `catalogus-public/uploads/.htaccess` en gebruikersfoto's (uitgesloten van Git; uploads-beveiliging op productie apart controleren).

Zie het [auditrapport](CODE_AUDIT_2026-10-17.md) voor impact en opvolging.
