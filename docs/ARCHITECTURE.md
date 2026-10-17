# Technische architectuur — 41 Lokeren

**Status 17 oktober 2026:** gebaseerd op eerder gedeelde delen van PHP-broncode en screenshots, **niet** op een volledige actuele productie-export. Controleer alle paden bij de import.

## Hosting en runtime

- Hosting: OVHcloud webhosting met domein <https://41lokeren.be>.
- Server-side: PHP; data: MySQL.
- Deploy: momenteel handmatig via RemoteDrive/FTP-achtige bestandskoppeling.
- Geen aanwijzing voor buildpipeline, Composer, GitHub Actions of automatische deployments; pas aannemen na broncode-inspectie.
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

**Opmerking:** de actuele website kan aanvullende bestanden of andere paden bevatten; deze opsomming is geen gegarandeerd volledige directorylisting.

## Bekende databasetabellen

`admins`, `login_attempts`, `lots`, `lot_images`, `settings`.

Verifieer de volledige schema's, indexen (met name de unieke lotnummerconstraint), foreign keys en kolomtypen op basis van een **geschoonde** schema-export. Publiceer geen productiegegevens of inlog-/passwordhashwaarden.

## Beveiligingsgrenzen

1. Adminroutes eisen authenticatie en beschermen schrijfacties met CSRF.
2. Serverbestanden met database-inloggegevens staan buiten de publieke webroot.
3. Fotouploads worden server-side gevalideerd (mime, formaat, limiet, bestandsnaam).
4. Snapshotarchief en logs bevatten mogelijk gevoelige informatie en blijven buiten GitHub en buiten de publieke site.
5. Bij publicatie worden alleen toegestane kavels getoond, afhankelijk van status per kavel en algemene zichtbaarheid.

## Ontbrekende verificatie

- Exacte PHP- en MySQL-versies en benodigde PHP-extensies, waaronder `ZipArchive`.
- Actuele `private/bootstrap.php` en wijzigingen aan admin-menu na hotfix v1.5.1.
- Actuele `private/snapshots.php`: transactie-, herstel- en opslagstrategie.
- Werkelijke OVHcloud-hostingopslag en gemeten vrije ruimte.
- Ervoor zorgen dat referenties in `private/config.php` niet in foutlogs terechtkomen.
- Match tussen bestanden op OVHcloud en toekomstige GitHub-baseline.

Werk dit document bij zodra de echte productiebroncode is veilig ingelezen.
