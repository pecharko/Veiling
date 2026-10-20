# Ontwikkelen, uitrollen en herstellen

**Uitrolmodel:** handmatig goedgekeurde GitHub Actions/SFTP-releases met een expliciete bestandslijst. Een commit/merge wijzigt OVHcloud **niet automatisch**. RemoteDrive is enkel een fallback.

**Noodprocedure bij onverwacht HTTP 503 na herstel:** [RESTORE_INCIDENT_RUNBOOK.md](RESTORE_INCIDENT_RUNBOOK.md). Maak alleen kopieën van journal, data en fotomappen; verwijder niet blind een veiligheidsmarker.

**Fase 3B (in voorbereiding; niet live):** de read-only diagnosemodules `private/restore_diagnosis.php` en `scripts/restore_diagnose.php` worden niet automatisch uitgevoerd en zijn geen vervanging voor een geautoriseerd incidentherstel. `php scripts/restore_diagnose.php --plan` is uitsluitend bedoeld voor een beveiligde **offline kopie** van de applicatie, een bijbehorende read-only onderzochte database en herstelbestanden, of voor een bevoegde technische operator. De CLI retourneert alleen een diagnose en kan niets terugzetten of vrijgeven. Het resultaat `target_verified_pending_review` is **geen toestemming** om de journalmarker of oude fotomap te verwijderen; blijf het incidentrunbook volgen. **Geen SFTP-deploy of test op de live productieomgeving in deze PR.**

**Belangrijke gezamenlijke release-afhankelijkheden PR #31–#33:** de verbeteringen zijn in GitHub ontwikkeld maar **geen van de runtimewijzigingen uit PR #31, #32 of #33 staan al op productie**. Een toekomstige gerichte code-uitrol moet daarom deze **acht runtimepaden samen** behandelen:

1. `private/catalog_mutex.php` — nieuwe MySQL-write-mutex uit PR #32
2. `private/restore_journal.php` — nieuw onderhoudsjournal uit PR #33
3. `private/snapshots.php` — gecombineerde DB-consistentie, mutex en journal uit PR #31–#33
4. `catalogus-public/admin/kavel.php` — schrijfvergrendeling
5. `catalogus-public/admin/instellingen.php` — schrijfvergrendeling
6. `catalogus-public/admin/login.php` — vergrendeling voor loginpogingen
7. `catalogus-public/admin/backup.php` — buitenste veiligheidssnapshot-/restorelock
8. `private/bootstrap.php` — nieuwe PHP-maintenance-gate

Upload beide nieuwe helpers **eerst**, daarna de afhankelijke private/admin-PHP-code; activeer `private/bootstrap.php` pas **als laatste**. Verifieer vóór een daadwerkelijke uitrol dat deze allowlist alle runtime-afhankelijkheden en de actuele productieverschillen dekt. Tijdens niet-atomair uitrollen van afhankelijke bestanden mogen beheerders geen mutaties doen of restores starten; het releaseplan moet hiervoor een gecontroleerd venster en rollback voorzien. Bestanden uit `private/backups/`, `uploads/`, databases, journal of echte snapshots zijn **nooit** onderdeel van de code-upload zonder afzonderlijke expliciete incidentautorisatie.

**Fase 3A (in PR, niet live):** de fail-closed hersteljournalwijziging omvat `private/restore_journal.php` (nieuw), `private/bootstrap.php` en `private/snapshots.php`. De helper MOET vóór de twee PHP-afhankelijkheden worden geüpload; oude deploymentworkflows mogen niet hergebruikt worden zonder exacte allowlist. Nieuwe journaldata wordt uitsluitend tijdens een toekomstige herstelactie op `private/backups/` geschreven, niet tijdens een code-deployment. Een onverwachte `.restore-active.json`, `.restore-active.tmp-*` of `.uploads-before-restore-*` (ook zonder journal) houdt de PHP-site met HTTP 503 offline totdat een expert de situatie heeft beoordeeld. **Verwijder de marker niet blind; verwijder ook geen `.uploads-before-restore-*` of `.restore-*` map zonder incidentplan en veilige externe kopie.**

Relevante productiegate bij een latere release: hercontroleer `main`-SHA, drie groene CI-runs, SHA-256 per exact geallowlist pad, afstand tot bestaande productiecode (drift), 7-daagse privécodeback-up, rollback, handmatig offline plan en twee afzonderlijke release/deploytoestemmingen. Bij eerste uitrol moet de opslageis (hostingvolume en accountquota) gecontroleerd worden. **Op productie geen SIGKILL-proeven of herstelacties.** Deze fase biedt onderhoudsbeperking, **geen bewezen automatische recovery**. PHP-only blokkering beschermt niet rechtstreeks statische foto-URLs of externe caches; volledige onderhoudsmodus vergt extra maatregelen.


**Fase 2 in review — geen productie-uitrol:** de voorgestelde MySQL-mutex raakt `private/catalog_mutex.php` (nieuw), `private/snapshots.php`, `catalogus-public/admin/kavel.php`, `catalogus-public/admin/instellingen.php`, `catalogus-public/admin/login.php` en `catalogus-public/admin/backup.php`. De nieuwe helper moet vóór de afhankelijke PHP-scripts geüpload worden. Gebruik GEEN bestaande workflow met een verkeerde allowlist, en deploy nooit afzonderlijk alleen `snapshots.php` of alleen de adminroutes. Deze wijziging kan tijdens een snapshot tijdelijk HTTP 503 laten zien voor schrijvende beheerders. Vereis een aparte handmatige releaseworkflow met offline plan, codeback-up, SHA-256, driftcontrole, rollback, drie groene CI-suites en twee afzonderlijke expliciete toestemmingen. T-051 is uitsluitend geïsoleerd; geen restoretest op productie. De huidige live-versie is **niet** geverifieerd als voorzien van deze mutex.

**Fase-1-fix in review:** voor een consistente MySQL-read-view wordt uitsluitend `private/snapshots.php` als runtimebestand aangepast. Deze PR is **niet** geschikt voor bestaande SFTP-deployworkflows zonder exacte allowlist en dubbele toestemming. Geen nieuwe productie-back-up of restore uitvoeren als proef. Filesystem-lock, foto-consistentie en crashbestendige restore ontbreken nog.

**Let op — snapshotcrashonderzoek, issue #2 (19-10-2026):** T-050A/T-050B zijn *risicoreproducerende* CI-tests, niet een restorefix. Hun groene uitkomst autoriseert **geen** productiewijziging. [Onderzoek en voorgesteld herstelprotocol](SNAPSHOT_CONSISTENCY_CRASH_STUDY_2026-10-19.md). Bewaar bij een onderbroken herstel beide database- en foto-toestanden; herstel niet op goed geluk en draai geen destructieve test op de live hosting.


## Auditrelease PR #25, #26, #27 — technisch live geverifieerd (19-10-2026)

De drie audit-PR's zijn gemerged in `main` (respectievelijk `a8d095ef4a26a967d5eaaeddc51fd3794f18061d`, `23507f8680a615ef57250b070846c0c9e333a8b9`, `e1022abc80fdff88febab078ef678942bd3209ae`). Ook deployment-PR #28 is handmatig gemerged. **Productierelease is geslaagd** via exact commit `8b2e6b8f444f67952620163c523e04c5807a032e` op 19-10-2026; [workflow-run #39203389883](https://github.com/pecharko/Veiling/actions/runs/39203389883) (29 seconden). Alle zes bestanden zijn op productie geüpload en met SHA-256 read-back geverifieerd. Vijf bestaande bestanden zijn vóór de upload veilig bewaard; `assets/placeholder.svg` was niet op productie aanwezig en is als nieuw bestand vastgelegd. Privé recovery-artifact `ovh-audit-pr25-26-27-before-39203389883`, vervalt 26-10-2026.

De voorgestelde workflow `.github/workflows/audit-release-deploy.yml` met `scripts/deploy_audit_release.py` staat alleen de volgende zes runtimepaden toe, in uploadvolgorde:

1. `catalogus-public/assets/placeholder.svg` — statische fallbackafbeelding, mogelijk nieuw op productie.
2. `catalogus-public/admin/backup.php` — verwijderde bulkfoto-importtekst.
3. `catalogus-public/admin/kavel.php` — verwijderde bulkfoto-importtekst.
4. `catalogus-public/admin/admin.css` — navigatieknop voor beveiligd uitloggen.
5. `catalogus-public/admin/logout.php` — alleen POST met geldig CSRF-token.
6. `private/bootstrap.php` — CSRF-beveiligd POST-formulier in het adminmenu; **als laatste**, zodat oude en nieuwe uitlogroute zo kort mogelijk uiteenlopen.

Releasegates: drie groene CI-checks op actuele PR-head en handmatige merge; vervolgens eerst `workflow_dispatch` op `main` met `mode=plan`, lege `release_sha` en lege `confirmation`. Dit toont de zes SHA-256-checksums zonder verbinding of schrijfacties. Verifieer het releasecommit, de hashes en de bestanden; vraag dan **aparte expliciete release- en finale deploygoedkeuring**. Alleen voor `mode=deploy` vereist de workflow `release_sha` exact gelijk aan `GITHUB_SHA` en `confirmation=DEPLOY AUDIT RELEASE`. SFTP gebruikt de bestaande vastgepinde SSH-hostkey en gecontroleerde remote root.

De productieversies van de vijf bestaande bestanden en eventueel een al aanwezige placeholder worden vóór de upload geback-upt in een privé GitHub Actions-artifact (7 dagen). Een op productie ontbrekende placeholder wordt in het manifest expliciet als **afwezig** vastgelegd, zodat deze alleen bij foutieve upload terug verwijderd kan worden. Productiedrift blokkeert de upload. Na ieder bestand wordt de SHA-256 teruggelezen; een fout activeert best-effort rollback. Uploads zijn niet atomisch als één eenheid; houd de admin gedurende de korte vrijgave bij voorkeur vrij van gelijktijdig gebruik.

De read-only HTTPS-tests controleren homepage, SVG-URL (200), login, afgeschermde adminroutes (303), anonieme logout (303), CSV- en bulkfoto-import GET + lege POST (404). **Uitgevoerde run #39203389883:** homepage/login/placeholder HTTP 200, afgeschermde admin- en anonieme uitlogroute HTTP 303, beide oude importroutes GET/lege POST HTTP 404; alle tests geslaagd. **Beheerdersacceptatie bevestigd 19-10-2026:** na checklist van ingelogd uitloggen/heropenen, de fotoloze kavelplaceholder en het gewone fotouploadveld antwoordt de beheerder 'alles werkt'. Dit is een gebruikersverklaring, zonder afzonderlijke screenshots of live destructieve uploadtest. De rollback is niet op productie getest. De beheerdersacceptatie nadien omvat nav-uitloggen op een **test-/beheerderssessie** en het heropenen van de beveiligde admin, zichtbaarheid van fotofallback op een kavel zonder foto, foto-uploadformulier in kaveleditor en correcte back-uppagina. **Geen** destructieve testen op productie. Deze SFTP-code-release verandert geen database, bestaande foto's, `uploads/`, snapshots of configuratie.



## Uitfaseren bulkfoto-import — voorbereiding, nog niet live (19-10-2026)

**Afzonderlijke voorgestelde workflow:** `.github/workflows/photo-import-retirement-deploy.yml` met `scripts/deploy_photo_import_retirement.py` (PR voor handmatige review). De workflow is alleen handmatig startbaar op `main` en controleert het door de beheerder ingegeven exacte `release_sha` plus bevestiging `DEPLOY PHOTO RETIRE` vóór elke SFTP-verbinding. Test eerst `mode=plan` met lege bevestiging en controleer de drie SHA-256-hashes; dit doet geen netwerkverbinding. Een geslaagd plan geeft geen toestemming voor upload.

**Strikte bestandsvolgorde:** eerst `catalogus-public/admin/fotos-bulk.php` (404 voor GET/POST), dan `catalogus-public/admin/systeemcheck.php` (verouderde bulk-mapcontrole verwijderd), tot slot `private/bootstrap.php` (Foto-import-link weg). Tijdens `deploy` worden de drie bestaande productieversies vóór enige wijziging geback-upt in een privé GitHub Actions-artifact (7 dagen); alle externe bestanden en de toepassingmap worden geverifieerd met lstat om symlinks te weigeren. Bij drift of ongeldige serverroot: stoppen. Bij uploadfout: best-effort rollback met SHA-256-verificatie. De HTTP-smoketests controleren homepage/login/afgeschermde admin en beide oude import-URL's (GET + lege POST). Test daarna aangemeld of de menulink ontbreekt en de kaveleditor nog foto's accepteert, zonder een destructieve productietest uit te voeren.



De ontwikkelwijziging raakt uitsluitend `private/bootstrap.php` (menulink), `catalogus-public/admin/fotos-bulk.php` (HTTP 404 voor alle methodes) en `catalogus-public/admin/systeemcheck.php` (verwijderde controle en tekst). Andere PHP-/data-/fotobestanden hoeven niet te worden geüpload. **Gebruik geen bestaande dashboard-, CSS-, kavel- of snapshotworkflow:** die hebben een andere, vaste allowlist. Bereid eerst een aparte gerichte handmatige deployment met offline `plan`, SHA-256, geverifieerde SFTP-root, een kort bewaard privé-back-upartifact, driftcontrole, best-effort rollback en read-only GET + lege POST naar `admin/fotos-bulk.php` (beide 404) voor. Controleer ook de bestaande CSV-404 en anonieme admin-auth, vervolgens aangemeld de afwezige menulink en foto-upload bij een kavel. Verander **geen** database, `private/photo-inbox/`, `uploads/`, bestaande foto's, snapshots of configuratie. Vraag apart expliciete toestemming voor productie na groene CI en een geslaagd plan.


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

## Modern kaveloverzicht — geplande UI-release (niet live)
Runtime-allowlist: uitsluitend `catalogus-public/admin/index.php` en `catalogus-public/admin/admin.css`. De CSS en de PHP moeten samen worden vrijgegeven. Bestaande snapshot-, kavel- en CSV-deployworkflows mogen hiervoor niet worden hergebruikt zonder expliciete allowlist-review. Eerst CI, read-only plan, productiecodeback-up en goedkeuring van exacte commit/paden; daarna pas SFTP, SHA-256 en read-only smoke-tests. Geen database-, foto- of snapshotwijzigingen. Na uitrol T-046 visueel uitvoeren.

### Dashboardrelease — specifieke workflow
Gebruik `.github/workflows/dashboard-deploy.yml` met `scripts/deploy_modern_dashboard.py` (alleen `catalogus-public/admin/admin.css` gevolgd door `catalogus-public/admin/index.php`). Eerst `mode=plan`, daarna na afzonderlijke goedkeuring `mode=deploy` met bevestiging `DEPLOY DASHBOARD`. De workflow maakt een privé rollbackartifact (7 dagen), vergelijkt de remote bestanden met de back-up vóór schrijven en controleert SHA-256 na iedere upload. Na de uitrol: read-only controle van anonieme admin-loginredirect en CSV 404; aangemelde visuele acceptatie T-046 blijft verplicht. Geen data-, foto- of snapshotwijzigingen. Workflow pas na groene CI en merge beschikbaar op `main`.
