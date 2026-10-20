# Minimum Safe Deployment — definitief releaseplan ter review

Status: **VOORBEREID, NIET VRIJGEGEVEN, GEEN PRODUCTIE-UPLOAD**.
Doel: https://41lokeren.be, OVHcloud. Bestaande main voor dit plan:
`d33e4c10376302dceb0e24aee670c594557bcd15`.
Wanneer main wijzigt, moeten plan, hashes en CI opnieuw worden gecontroleerd.

## Exacte acht-bestandenallowlist

1. `private/catalog_mutex.php` (nieuw)
2. `private/restore_journal.php` (nieuw)
3. `private/snapshots.php`
4. `catalogus-public/admin/kavel.php`
5. `catalogus-public/admin/instellingen.php`
6. `catalogus-public/admin/login.php`
7. `catalogus-public/admin/backup.php`
8. `private/bootstrap.php` (laatste)

Bron: `scripts/plan_catalog_recovery_release.py`. Alle andere bestanden uitgesloten, inclusief `private/config.php`, databases, snapshots, `private/backups/`, `uploads/`, foto's, `.htaccess` en herstel-operatorhelpers.

## Aangetoond

- Read-only SFTP-baseline, 20 oktober: [run 39381878595](https://github.com/pecharko/Veiling/actions/runs/39381878595); zes oude PHP-bestanden komen overeen met auditcommit, twee helpers ontbreken, geen herstelmarkers gevonden.
- Read-only codebackup, 20 oktober: [run 39385190939](https://github.com/pecharko/Veiling/actions/runs/39385190939); artifact `recovery-code-rollback-39385190939`, ID `12247286096`, 7 dagen retentie. **Verloopt; vernieuw vlak voor daadwerkelijke upload.**
- Beheerder bevestigt een lokaal gedownloade data-only ZIP met `database.json`, `manifest.json` en fotobestanden. ZIP-test en alle interne SHA-256 hashes lokaal geslaagd. Werkelijke externe bewaarlocatie, ZIP-hash, datum en herstelbaarheid zijn niet onafhankelijk door GitHub vastgesteld.
- Bestaande OVH-systeemcheck: PHP 8.5, PDO MySQL, benodigde tabellen en schrijfrechten groen; geschatte resterende ruimte 982,5 MB.
- phpMyAdmin-test `GET_LOCK`/`RELEASE_LOCK` met geïsoleerde testnaam gaf `lock_test=1`, zonder catalogustabelwijzigingen.
- Snapshots via bestaande UI maken/aanleveren maakt `ZipArchive` aannemelijk; de productie-readiness-check blijft dit bij release bevestigen.

## Verplicht nog te leveren vóór toestemming voor upload

1. **Uploader is NIET gekoppeld.** De workflow `catalog-recovery-controlled-deploy.yml` weigert expliciet elke `mode=deploy`. Dit mag alleen via afzonderlijk gereviewde securitygevoelige PR gewijzigd worden. Bestaande fake-SFTP-tests bewijzen geen veilige live upload.
2. **Releaseplan op concrete main-commit:** voer `catalog-recovery-release-plan.yml` met handmatige `release_sha` uit en beoordeel acht bronhashes en PHP-lint.
3. **Afgesloten beheerdersvenster:** geen gelijktijdige kavel/fotowrites, logins of snapshot-herstel tijdens acht niet-atomaire uploads.
4. **Nieuwe just-in-time remote controle:** SFTP-root/hostkey, zes oude codehashes, twee afwezige helpers, herstelsignalen. STOP bij drift.
5. **Nieuwe code-rollbackbeveiliging:** sla exact zes oude remote codebestanden + afwezigheidsmanifest in 7-daags privéartifact op en verifieer dat upload pas na succesvolle artifact-persistentie kan starten; stop bij ontbreken artifact.
6. **Data-only ZIP:** beheerder bevestigt leesbare afzonderlijke externe kopie met volledige ZIP SHA-256 en datum (niet in repo of Actions uploaden).
7. **Geverifieerde uploader:** helperbestand 1 en 2 eerst, daarna vijf afhankelijke bestanden, `bootstrap.php` laatste; beperkte allowlist, SHA-256 readback en rollback bij fout. Herstel een gedeeltelijke upload of meld onmiddellijk STOP bij onvolledige rollback. Geen blind synchroniseren of bestandssysteemopruiming.
8. **Twee autorisaties:** eerst de specifieke SHA/acht paden/prod-release; na succesvolle laatste plan/backup/drift-controles apart definitieve `deploy`-toestemming.
9. Na deployment: read-only HTTPS-homepage, afgeschermde admin/login en CSV-import-404 controleren; beheerder voert visuele acceptatie uit. Geen live restoretest.

## Niet automatiseren

Geen permanente agent, auto-merge van security/deploy-PR's, live restore-proeven, auto-herstel van MySQL, verwijdering van herstelmarkers, of algemene uploads van bestanden.

**STOP tenzij alle openstaande gates aantoonbaar geslaagd zijn.**
