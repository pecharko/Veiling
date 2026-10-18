# Ontwikkelen, uitrollen en herstellen

**Uitrolmodel:** voorlopig handmatige bestandenoverdracht naar OVHcloud via RemoteDrive. **Geen automatische deployment vanuit GitHub.**

## Uitrol: CSV-import tijdelijk verwijderen (18 oktober 2026)

**Pas na merge naar `main`**, synchroniseer GitHub Desktop met de repository. Maak vóór upload een lokale kopie van de huidige bestanden in de OVHcloud-productieomgeving.

Upload **alle vijf** volgende bestanden vanaf de geactualiseerde repository met RemoteDrive, op exact dezelfde relatieve paden:

1. `private/bootstrap.php` — verwijdert CSV-link uit het adminmenu;
2. `catalogus-public/admin/index.php` — verwijdert knop 'Kavels importeren';
3. `catalogus-public/admin/import.php` — vervangt de importfunctie door een HTTP-404-afhandeling die ook POST blokkeert;
4. `catalogus-public/admin/systeemcheck.php` — verwijdert CSV-import als actieve systeemcheck;
5. `catalogus-public/admin/backup.php` — verwijdert CSV-verwijzing uit de back-upaanbeveling.

**Let op:** `admin/import.php` moet juist worden **overschreven**, niet alleen uit het menu worden verborgen. Verwijder het bestand **niet** uitsluitend uit Git of via de FTP-client, want de overblijvende serverversie kan dan nog imports uitvoeren. Als `catalogus-public/assets/voorbeeld-kavels.csv` intussen op OVHcloud was geplaatst, mag je **alleen dat niet meer gebruikte voorbeeldbestand** verwijderen (niet de volledige assets-map). Wijzig de database, bestaande kavels, snapshots, fotobestanden, `private/config.php` en `.htaccess` niet.

**Na upload, uitsluitend veilig testen:**

```bash
curl -sSI https://41lokeren.be/admin/import.php | grep -iE '^(HTTP/|location:)'
```

Verwacht: `HTTP/2 404` (of HTTP/1.1 404, afhankelijk van protocol). Open daarnaast admin/index.php en controleer dat CSV-import uit menu en acties verdwenen is maar Foto-import en Back-up & herstel intact blijven. **POST-test alleen uitvoeren met een leeg verzoek (zonder CSV-bestand, zonder authenticatiegegevens):** `curl -sS -o /dev/null -w '%{http_code}\n' -X POST https://41lokeren.be/admin/import.php`. Verwacht `404`. De test zonder data kan geen kavels toevoegen. Leg het resultaat vast als T-042.

Rollback: herstel de vijf originele PHP-bestanden vanuit de vooraf gemaakte veilige kopie. Hiermee kan CSV-import weer actief worden; pas doen na expliciete beslissing. De oude importimplementatie blijft ook in de Git-geschiedenis beschikbaar.

## 1. Eerste import (17 oktober 2026 uitgevoerd, controles deels open)

1. **Uitgevoerd volgens gebruiker:** actuele productiebroncode van `catalogus-public/` en `private/` gekopieerd naar `/Users/chrisdemol/Code/41Lokeren-Productie`.
2. **Nog afzonderlijk te verifiëren:** beveiligde back-up van database, foto's en productieconfiguratie blijft buiten Git; GitHub vervangt die niet.
3. **Uitgevoerd volgens gebruiker:** repository gekloond onder `/Users/chrisdemol/Code/GitHub/Veiling`.
4. **Statisch beoordeeld:** belangrijke geïmporteerde PHP-/JS-bestanden in GitHub; zie `docs/CODE_AUDIT_2026-10-17.md`. Compleetheid vergeleken met OVHcloud moet nog worden geverifieerd.
5. **Gecontroleerd voor de voorbeeldconfiguratie:** `private/config.example.php` bevat placeholders. Verdere scanning en hercontrole bij elke commit blijft nodig.
6. **Uitgevoerd volgens gebruiker:** staginglijst met 28 bestanden bekeken. Geheimencontrole blijft nodig voor nieuwe bestanden.
7. **Uitgevoerd volgens gebruiker:** eerste broncode-commit en push.
8. **Door GitHub-verificatie bevestigd:** centrale codebestanden zijn bereikbaar in de private repository. Volledige secret-audit en ontbrekende assets blijven open.
9. **Uitgevoerd:** architectuur, requirements en testplan bijgewerkt vanuit statische code-inspectie. Testuitvoering is nog open.

**Privacy:** een private repository is niet hetzelfde als een kluis voor productiegeheimen. Indien ooit een secret werd gecommit, moet die credential worden vervangen en de blootstelling worden onderzocht; alleen het bestand verwijderen is niet genoeg.

## 2. Nieuwe wijziging

1. Start vanuit actuele `main` (liefst met een featurebranch in GitHub Desktop).
2. Noteer de relevante requirement-ID (FR-/NFR-ID). Voeg een nieuw ID toe wanneer nodig.
3. Pas code, acceptatiecriteria, `CHANGELOG.md` en tests tegelijk aan.
4. Controleer syntax en relevante functionaliteit; wijzig niet onnodig het gehele `bootstrap.php` of andere gedeelde componenten.
5. Open waar mogelijk een Pull Request en gebruik de checklist in `.github/PULL_REQUEST_TEMPLATE.md`.
6. Merge pas na review van tests en controle op persoonsgegevens/geheimen.

## 3. Handmatige uitrol naar OVHcloud

1. Noteer welke bestanden zijn gewijzigd.
2. Maak vlak voor uitrol een kopie van de doelbestanden en zo nodig een database-/fotoback-up.
3. Verifieer welke productieversie draait en voorkom het overschrijven van nieuwere bestanden.
4. Upload enkel gewijzigde bestanden naar het juiste pad; kopieer **nooit** lokale secrets over productie heen.
5. Voer de gerichte smoke-tests uit (zie `docs/TESTPLAN.md`).
6. Controleer fouten/logs en het algemene functioneren van de website en admin.
7. Noteer uitrolmoment en gebruikte commit in `CHANGELOG.md` of de releasenotities.

## 4. Terugdraaien

- **Code-only bug:** herstel de vooraf bewaarde versie van de gewijzigde PHP/CSS/JS-bestanden.
- **Databasewijziging of foutieve datamutatie:** gebruik een vooraf getest herstelplan; code terugdraaien alleen is mogelijk onvoldoende.
- **Foto's/back-ups:** herstel vanuit afzonderlijke, verifieerbare opslagkopie.
- Een snapshot herstellen **overschrijft data**. Test de terugzetprocedure eerst in een testomgeving.

## 5. Losse back-ups

Bewaar een lokale, beveiligde kopie van productie PHP-configuratie, database en foto's op de Mac of versleutelde externe opslag. Download belangrijke snapshots buiten OVHcloud, zodat een storing op die server niet ook de back-up wegneemt.

## 6. Lokaal synchroniseren via GitHub Desktop

**Clone:** File > Clone repository > `pecharko/Veiling` > kies map (bv. `~/Documents/Projecten/Veiling`).

**Nieuwe veranderingen binnenhalen:** Fetch origin / Pull origin.

**Eigen wijzigingen opslaan:** kijk de Changes-lijst na, schrijf een commitbericht, klik Commit to main of featurebranch en daarna Push origin.

**Belangrijk:** Fetch/Pull halen alleen GitHub-commits op, niet de productiecode die intussen rechtstreeks op OVHcloud is aangepast.
