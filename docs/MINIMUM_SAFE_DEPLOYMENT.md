# Minimum Safe Deployment — acht PHP-bestanden

**Status:** draft, geen productie-uploadautorisatie. `main` en productie blijven ongewijzigd.

## Verplicht voor eerste release (niet schrappen)

1. Controleer exacte `main`-SHA, drie vereiste groene CI-checks en PHP-lint van **uitsluitend** de acht runtimebestanden uit `scripts/plan_catalog_recovery_release.py`.
2. Gebruik de reeds geslaagde read-only SFTP-preflight als uitgangspunt, maar hercontroleer vlak vóór upload remote hostkey/root, zes oude SHA-256-hashes, twee afwezige helpers en herstelmarkers. STOP bij drift.
3. Controleer handmatig dat OVH de vereiste PHP-extensies/ZIP en MySQL `GET_LOCK`/`RELEASE_LOCK` ondersteunt, plus voldoende hostingruimte.
4. Bevestig een **werkelijk bestaande, extern opgeslagen, leesbare data-only back-up** (database en foto's), inclusief hash. Een JSON-attest alleen is onvoldoende bewijs. **Geen restoretest op productie.**
5. Maak vóór upload een **afzonderlijke privécodeback-up** van de zes originele bestanden; leg de twee afwezige helpers vast. Controleer SHA-256 en bewaar het rollbackartifact zeven dagen. Stop bij mislukte archivering.
6. Spreek een rustig administratief onderhoudsvenster af en verhinder gelijktijdige wijzigingen/restores.
7. Voer alleen een strikt achtpaden-SFTP-release uit: twee helpers eerst, vijf afhankelijke bestanden, `private/bootstrap.php` laatst. Controleer na elke upload de SHA-256 door opnieuw te lezen.
8. Bij mislukking: rollback in omgekeerde volgorde, verwijder uitsluitend nieuwe helpers indien die vóór de release afwezig waren; rapporteer onvolledige rollback dringend. Voer alleen read-only HTTPS-smoketests uit en vraag menselijke functionele goedkeuring.
9. Vraag afzonderlijk akkoord voor concrete release-SHA/bestandslijst/doelomgeving, en na het geslaagde plan opnieuw **finale deploytoestemming**.

## Bewust uitgesteld

- Geavanceerde dashboards, geautomatiseerde externe opslagverificatie, uitgebreide hostinginventaris, extra incident-automatisering en autonome herstelacties.
- Nieuwe generieke release-engine of synchronisatie van andere bestanden.
- Live restore-/crashtests, automatische deploy na merge en automatische verwijdering van herstelmarkers.

## Technische afbakening van PR #41

- De bestaande `plan`-workflow mag offline tests en manifesten uitvoeren.
- `deploy` blijft **hard geblokkeerd** totdat een apart gereviewde, getest veilige runner bovenstaande minimumgates afdwingt.
- `scripts/recovery_release_transaction.py` en `scripts/recovery_backup_evidence.py` zijn **offline hulpmiddelen**, geen productie-runner.
- Voor de minimale echte uploader zijn gerichte fake-SFTP-tests nodig voor gedeeltelijke uploads, drift, SHA-readback, rollback en rollbackfouten. Deze vervangen geen echte data- of codeback-up.

## Stand van bewijs

Read-only preflight [run #39381878595](https://github.com/pecharko/Veiling/actions/runs/39381878595) bevestigde zes oude bestanden en twee afwezige helpers. Dit bewijst **geen** actuele externe back-up, hostcompatibiliteit, onderhoudsvenster of productiedeploy.

**Beslisregel:** zonder elk verplicht bewijs: STOP. Geen upload.
