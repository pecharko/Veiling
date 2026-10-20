# Read-only OVHcloud SFTP-preflight — catalogusherstelrelease

**Status: CODE VOORBEREID, NIET LIVE UITGEVOERD, GEEN DEPLOYTOESTEMMING.**

Op **20 oktober 2026** is de bestaande [offline achtbestandenreleasecontrole #39378597480](https://github.com/pecharko/Veiling/actions/runs/39378597480) succesvol uitgevoerd op commit `ab4534c417bcf33b09cc13bc2de1ff726c068660`. Alle acht bronbestanden waren geldig en het 7-daagse [artifact](https://github.com/pecharko/Veiling/actions/runs/39378597480/artifacts/12244248238) is opgeslagen. **Het controleerde geen enkele OVHcloud-file of productie-back-up.**

## Doel van deze tweede, afzonderlijke controle

De nieuwe `scripts/preflight_catalog_recovery_remote.py` en de handmatige workflow `catalog-recovery-remote-preflight.yml` beperken zich tot **alleen-lezen SFTP-metingen**. Ze mogen niets uploaden, vervangen, hernoemen, verwijderen, chmodden of schrijven; ze importeren geen SQL en lezen geen snapshot-ZIP, loginsecret, klantbestand, database of foto-inhoud. De bestaande SFTP-verbinding gebruikt exact de in GitHub Secrets opgeslagen credentials en een gepinde SSH-hostkey. Dit is een scriptmatige leesgarantie; het bestaande SFTP-account kan meer rechten hebben. Beveiligingsreview blijft verplicht.

De zes **bestaande** runtimebestanden moeten byte-voor-byte identiek zijn aan de laatst geverifieerde [productieauditrelease #39203389883](https://github.com/pecharko/Veiling/actions/runs/39203389883), Git-commit `8b2e6b8f444f67952620163c523e04c5807a032e`. De twee **nieuwe** helpers moeten op productie nog afwezig zijn:

| Remote verwacht | Bestanden |
| --- | --- |
| Nog **niet aanwezig** | `private/catalog_mutex.php`, `private/restore_journal.php` |
| Exact audithash | `private/snapshots.php`, `catalogus-public/admin/kavel.php`, `catalogus-public/admin/instellingen.php`, `catalogus-public/admin/login.php`, `catalogus-public/admin/backup.php`, `private/bootstrap.php` |

De tool gebruikt **`lstat`**, weigert symlinks en ongeldige/missende appdirectories, identificeert de juiste site via vaste codekenmerken en leest de zes PHP-bestanden tweemaal. Elke afwijking (ook een file die tijdens controle wijzigt) geeft **STOP**. Een andere eerdere rechtmatige handmatige deployment zou dus eerst apart onderzocht moeten worden — nooit automatisch een nieuwe baseline accepteren.

De tool controleert uitsluitend op **bestandsnamen** (niet de inhoud) van achtergebleven herstelindicatoren in `private/backups/` (`.restore-active.json`, `.restore-active.tmp-*`, `.restore-finalizing-tmp-*`) en `catalogus-public/` (`.uploads-before-restore-*`, `.restore-*`). Een indicator leidt tot STOP; de tool verwijdert geen herstelbewijs.

## Hoe starten na review/merge van de PR?

1. Controleer eerst dat de PR met menselijke beveiligingsreview is gemerged en dat de drie CI-suites groen zijn op de exacte huidige code. De eerste offline run #39378597480 hoort bij een **oudere** main-SHA; voer zonodig opnieuw de handmatige offline planworkflow uit voor de actuele main-SHA.
2. Open GitHub Actions → **Controleer OVH herstelrelease via SFTP (alleen lezen)** op `main`.
3. Vul bij `release_sha` de **volledige actuele main-commit** in en bij `confirmation` exact `READ ONLY SFTP PREFLIGHT`.
4. Start de workflow **alleen na expliciete toestemming om met de live OVHcloud-SFTP-server te verbinden**. De bestaande `production`-environment en GitHub-secrets worden alleen voor deze handmatige, lezende job gebruikt. Er is geen `deploy`-mode.
5. Na succes: controleer het uitvoerlog en het `recovery-remote-preflight-<run-id>` JSON-artifact (zeven dagen). Het verslag bevat slechts relatieve PHP-paden, bron- en remote-hashes, een hash van de geconfigureerde SFTP-root, veiligheidsstatus en **geen broncode, databasewaarden, foto's of servercredentials**.

### Stopcondities

STOP zonder nieuwe baseline of upload bij: onbekende hostkey, onjuiste SFTP-root, onverwacht PHP-bestand, gewijzigde remote SHA, ontbrekende directories, symlink, één van de herstelmarkers, afwijkende main-SHA of onvolledige CI. Meld het incident en onderzoek de exacte situatie; **pas nooit een verwachte SHA aan om een rode preflight te omzeilen**.

## T-060: geïsoleerd en verplicht

`tests/recovery_remote_preflight_test.py` simuleert een SFTP-server met alleen `lstat`, `listdir` en `open("rb")`. Er is geen echte verbinding. De tests verifiëren een volledig geldige oude productieboom, gewijzigde PHP-code, onverwachte nieuwe helper, symlinks, missende mappen, onbekende applicatie, meerdere typen herstelmarkers, bestanden die tijdens het lezen veranderen en een onvolledige allowlist. **T-060 is geïsoleerd geslaagd (15 controles)** in [PHP-CI-run #39379852405](https://github.com/pecharko/Veiling/actions/runs/39379852405) op 20 oktober 2026. De PHP/repository-CI voert T-060 uit en vereist historische Git-inhoud zodat de gecontroleerde oude productiecommit beschikbaar is. De definitieve PR-head moet opnieuw drie groene CI-controles hebben.

## Wat dit nadrukkelijk niet bewijst

Zelfs een groene remote-preflight betekent **nog niet** dat de nieuwe herstelcode kan worden uitgerold. Niet bewezen zijn de daadwerkelijke PHP/MySQL-hostingcompatibiliteit, het exclusief rustige adminvenster, een actuele **extern gecontroleerde data-only snapshot**, de aparte private **7-daagse code-rollback-back-up**, voldoende hostingquota, een getest rollbackscenario, menselijke securityacceptatie en beide afzonderlijke toestemmingmomenten voor de latere echte deployment. Na deze fase volgt alleen een aparte **deploy-workflow-PR**, niet een upload.

Zie ook [de achtbestandenreleasechecklist](RECOVERY_RELEASE_READINESS_2026-10-20.md) en het open P1-[issue #2](https://github.com/pecharko/Veiling/issues/2).
