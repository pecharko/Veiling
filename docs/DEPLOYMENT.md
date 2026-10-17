# Ontwikkelen, uitrollen en herstellen

**Uitrolmodel:** voorlopig handmatige bestandenoverdracht naar OVHcloud via RemoteDrive. **Geen automatische deployment vanuit GitHub.**

## 1. Veilige eerste import (nog uit te voeren)

1. Download **de actuele productiebroncode** van `catalogus-public/` en `private/` naar een afzonderlijke, veilige map op je Mac.
2. Neem óók een beveiligde back-up van de huidige database en kavelfoto's, maar bewaar deze **buiten de Git-repository**.
3. Clone de private GitHub-repository in GitHub Desktop.
4. Inventariseer alle broncodebestanden en vergelijk ze met de bekende functionaliteit. Een oude ZIP uit de chat is **niet** automatisch dezelfde versie als productie.
5. Verwijder of vervang productiegeheimen door een onschadelijk voorbeeldbestand (bv. `private/config.example.php`, zonder echte waarden).
6. Controleer `git status` en de GitHub Desktop Changes-lijst **bestand voor bestand**. Let op credentials in PHP, .htaccess, logs, JSON, foto's, SQL-dumps en commentaar.
7. Commit pas daarna de geschoonde broncode, bijvoorbeeld `chore: import verified OVHcloud production baseline`.
8. Push naar GitHub en controleer via de webinterface dat geen geheimen of gegevensbestanden zichtbaar zijn.
9. Actualiseer `docs/ARCHITECTURE.md`, `docs/REQUIREMENTS.md` en `docs/TESTPLAN.md` aan de hand van de echte code.

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
