# Ontwikkelen, uitrollen en herstellen

**Uitrolmodel:** handmatig goedgekeurde GitHub Actions/SFTP-releases met een expliciete bestandslijst. Een commit/merge wijzigt OVHcloud **niet automatisch**. RemoteDrive is enkel een fallback.

## Gerichte code-release T-016/T-017 — opslagwaarschuwingen en snapshot-preflight (NOG NIET LIVE)

GitHub PR #11, gemerged in code-commit `d98dbc8fb90e4019649eefc41c86998cdcbcbaeb`. **69 geïsoleerde GitHub Actions-tests geslaagd**: [69 tests](https://github.com/pecharko/Veiling/actions/runs/39127059404), [PHP-lint](https://github.com/pecharko/Veiling/actions/runs/39127059332). Code in GitHub is niet gelijk aan de live server.

Gebruik **uitsluitend** de nieuwe workflow [`snapshot-storage-deploy.yml`](../.github/workflows/snapshot-storage-deploy.yml) en het eigen script [`deploy_snapshot_storage.py`](../scripts/deploy_snapshot_storage.py), nadat de voorbereidende PR is goedgekeurd en naar `main` is samengevoegd.

**Strikte release-allowlist (altijd precies twee bestanden):**
1. `private/snapshots.php` — de helperfunctie(s), eerst uploaden.
2. `catalogus-public/admin/backup.php` — de beheerderspagina, pas daarna uploaden.

Deze release bevat **geen** MySQL-mutaties, herstelactie, foto- of snapshotwijzigingen. Niet uploaden: `private/config.php`, `private/backups/`, `catalogus-public/uploads/`, `photo-inbox/`, `.htaccess`, FTP-credentials of andere bestanden.

**Voorwaarden vóór productie:** controleer `main` HEAD, inhoud/hashes van beide bestanden, eerdere deployment, `plan`-modus en CI-tests. Leg de exact goedgekeurde workflow-commit en beide paden vast. Vraag afzonderlijke expliciete toestemming voor `deploy`.

**Uitrolstappen:**
1. In GitHub Actions → **Deploy T-016 T-017 opslagveiligheid (handmatig)** → **Run workflow**, branch **main**, mode **plan**, bevestiging leeg. Deze stap heeft geen SFTP-verbinding en wijzigt niets.
2. Controleer dat **uitsluitend** de twee bovenstaande PHP-bestanden met hun SHA-256-hashes in het plan staan. Vraag expliciete gebruikersgoedkeuring voor de productie-release.
3. Start pas daarna opnieuw de workflow met mode **deploy** en bevestiging **`DEPLOY SNAPSHOTS`**. Bevestig dat de bestaande geverifieerde SFTP-Secrets en `OVH_SFTP_BASE` aanwezig zijn zonder ze ooit zichtbaar te maken.
4. De job valideert de juiste productiemappen en bestaande modules, haalt eerst de twee oude PHP-bestanden op en archiveert ze **vóór upload** in een privé artifact (`ovh-snapshot-storage-before-<run-id>`, 7 dagen bewaard).
5. De job verifieert tijdens de upload per bestand opnieuw dat de remote bestanden ongewijzigd zijn; uploads vinden plaats in de afhankelijkheidsvolgorde (helper, adminpagina), gevolgd door een SHA-256-leescontrole.
6. Read-only smoke tests: homepage GET **200**, loginpagina GET **200**, anonieme back-uppagina GET **303**, uitgeschakelde CSV-route **GET 404 en lege POST 404**. Geen snapshot aanmaken, downloaden, verwijderen of herstellen als automatische smoketest.
7. Na een geslaagde job in de aangemelde admin handmatig controleren dat **Back-up & herstel** laadt en het normale opslagoverzicht toont; productiequota blijven indicatief. Geen simulaties of destructieve tests op productie.

**Rollback:** bij uploadfout probeert de job onmiddellijk de oorspronkelijke PHP-bestanden te herstellen en te verifiëren. Als de job onverwacht stopt of de HTTP-check na de upload mislukt, beschouw de status dan als **onzeker**; controleer het privé back-upartifact en herstel uitsluitend de goedgekeurde oude versies via gecontroleerde SFTP, vervolgens smoke-testen. De automatische rollback vangt **niet** iedere server- of procesonderbreking op.

De bestaande workflows `ovhcloud-deploy.yml` (historische CSV-blokkering) en `kavel-deploy.yml` (kaveleditor) hebben oude vaste bestandslijsten en zijn **niet geschikt** voor deze release.

## Geautomatiseerde, gecontroleerde SFTP-upload (nieuwe mogelijkheid)

Workflow: [GitHub Actions — Deploy naar OVHcloud (handmatig)](../.github/workflows/ovhcloud-deploy.yml). Uploadscript: [`scripts/deploy_ovh_sftp.py`](../scripts/deploy_ovh_sftp.py).

**Doel:** minder handwerk via RemoteDrive. De eerste release uploadt bewust **alleen de vijf hieronder genoemde PHP-bestanden voor het uitschakelen van CSV-import**. De workflow draait **niet automatisch bij commits**; een beheerder start ze expliciet, vanaf de `main`-branch. De standaardkeuze `plan` toont enkel de bestanden en wijzigt niets. De optie `deploy` vereist het exacte bevestigingswoord **`DEPLOY`**.

### Eenmalig in te stellen in OVHcloud

1. Open **OVHcloud Manager → Web Cloud → Hosting → FTP - SSH**. Verifieer de SFTP-servernaam en de FTP-gebruiker. Activeer **SFTP** voor deze gebruiker indien nodig; OVH Starter ondersteunt dit.
2. Controleer in je SFTP-client wat de login-basismap is. Zoek vanuit die map de twee **naast elkaar liggende mappen** `catalogus-public/` en `private/`. De repository gebruikt die structuur. Een SFTP-pad `.` kan correct zijn wanneer je bij aanmelden meteen in `/home/kobejoe` terechtkomt; gebruik anders het daadwerkelijk bevestigde pad. **Niet gokken.**
3. Controleer de SSH-hostsleutelvingerafdruk van de server via een reeds vertrouwde SFTP-verbinding of OVHcloud. Gebruik die verificatie om de uitvoer van `ssh-keyscan -p 22 <SFTP-host>` te valideren; `ssh-keyscan` alleen geeft **geen** identiteitgarantie.

### Eenmalig in GitHub instellen

In [GitHub-repository-instellingen](https://github.com/pecharko/Veiling/settings/secrets/actions) → **Settings → Secrets and variables → Actions**:

| Type | Naam | Waarde |
|---|---|---|
| Secret | `OVH_SFTP_HOST` | Gecontroleerde SFTP-hostnaam uit OVHcloud |
| Secret | `OVH_SFTP_USER` | Gebruikersnaam van FTP/SFTP |
| Secret | `OVH_SFTP_PASSWORD` | SFTP-wachtwoord, alleen in GitHub Secrets invoeren |
| Secret | `OVH_SFTP_KNOWN_HOSTS` | Hele geverifieerde SSH-`known_hosts`-regel voor host: `hostnaam ssh-ed25519 AAAA...` (kan ook ander SSH-sleuteltype zijn) |
| Variable | `OVH_SFTP_BASE` | SFTP-basismap die `catalogus-public/` en `private/` bevat, bijvoorbeeld `.` indien bevestigd |

Gebruik voor dit doel bij voorkeur een afzonderlijke SFTP-gebruiker met alleen noodzakelijke maptoegang. Deel **geen** wachtwoord of host-key secrets in ChatGPT, issues of commits. Maak desgewenst een GitHub **Environment `production`** met expliciete reviewergoedkeuring voordat de job de secrets kan gebruiken (waar dit in je plan mogelijk is). De workflow gebruikt uitsluitend een geverifieerde SSH-hostsleutel en weigert onbekende hosts; er is geen fallback naar gewone FTP.

### Uitvoeren

1. Open [GitHub → Actions](https://github.com/pecharko/Veiling/actions) → **Deploy naar OVHcloud (handmatig)** → **Run workflow**, branch **main**.
2. Kies eerst **`plan`**, laat `confirmation` leeg en start. Controleer de afgedrukte lijst van precies vijf PHP-bestanden. Dit vereist nog geen SFTP-credentials.
3. Kies daarna **`deploy`**, typ **`DEPLOY`**, en start.
4. De workflow controleert de verbinding en de bestaande serverbestanden, **downloadt eerst de vijf oude productieversies**, bewaart die als **privé GitHub Actions-artifact voor 7 dagen**, en uploadt vervolgens de nieuwe bestanden. Na elke upload wordt de SHA-256 inhoud gecontroleerd. Bij een fout probeert het script wijzigingen terug te draaien. Dit is een **best-effort rollback**, geen volwaardige database-/serverback-up.
5. Na upload test de job uitsluitend `GET` en een **lege POST** naar `https://41lokeren.be/admin/import.php`: beide moeten **404** teruggeven. De database blijft onaangeraakt. Controleer daarna in Chrome dat CSV-import uit de admin is verdwenen en Foto-import en Back-up & herstel nog werken.

**Veiligheidsbeperkingen:** deze eerste workflow deployt niet automatisch andere toekomstige codewijzigingen. Voordat we de workflow uitbreiden, moet de vrijgavelijst en smoke-test per release gecontroleerd worden. De private `config.php`, snapshots, uploads, databasebestanden en `.htaccess` worden niet aangeraakt. **GitHub-commit ≠ live OVH-uitrol.**

**Fallback:** als SFTP niet beschikbaar is of de workflow faalt, gebruik de handmatige RemoteDrive-stappen hieronder; controleer een eventueel foutgerelateerd back-upartifact vóór opnieuw uploaden.

## Kaveleditor release — PR #7 (NIET live, afzonderlijke toestemming)

De code in PR #7 verbetert de kaveleditor: behoud formulierwaarden na opslaanfouten en controleer uploadstatus vóór DB-save. Een MySQL 8.4/PHP localhost-integratietest is geslaagd; de productieomgeving is hierdoor **niet** gewijzigd.

**Pas nadat PR #7 expliciet is goedgekeurd en gemerged** staat de workflow [Deploy kaveleditor naar OVHcloud](../.github/workflows/kavel-deploy.yml) op `main` beschikbaar. Start eerst `plan`: toont precies de twee geplande bestanden zonder netwerkverkeer. `deploy` vereist het bevestigingswoord `DEPLOY KAVEL`. Gebruik het bestaande GitHub `production` environment en dezelfde eerder ingeregelde SFTP-Secrets.

De workflow:

1. Controleert de bestaande editor en het systeem op de SFTP-doelmap.
2. Haalt een **pre-uploadback-up** op van `catalogus-public/admin/kavel.php`; neemt `private/lot_form.php` ook op wanneer die al bestaat. Als de helper nog ontbreekt, noteert de manifest expliciet dat het een nieuw bestand is.
3. Bewaart een rollback-artifact voor zeven dagen, en uploadt daarna **eerst** `private/lot_form.php`, **daarna** `catalogus-public/admin/kavel.php`.
4. Verifieert van beide bestanden de checksums. Bij een fout probeert het script de vorige versies te herstellen, en verwijdert het eventueel zojuist aangemaakte helperbestand.
5. Controleert anoniem `/admin/kavel.php` (verwacht login-redirect `303`) en de uitgeschakelde CSV-route `/admin/import.php` (verwacht `404`).

**Beperkingen:** automatische smoke-tests vervangen geen handmatige controle van een geldige kavelbewaring in de productie-UI. Gebruik geen bestaande veilingkavels voor destructieve testen; een testkavel alleen met expliciete toestemming en opruimplan. Bij onverwacht resultaat geen tweede deploy starten maar bekijk de herstelkopie. De bestaande CSV-deploymentworkflow blijft afzonderlijk en is niet uitgebreid met editorbestanden.

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
