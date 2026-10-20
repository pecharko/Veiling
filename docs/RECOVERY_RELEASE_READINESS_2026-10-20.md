# Voorbereiding productierelease: snapshotconsistentie (20 oktober 2026)

**Status: ALLEEN OFFLINE VOORBEREID — NIET LIVE — GEEN UPLOADTOESTEMMING.**

Deze checklist gaat over de broncode uit PR #31–#37. Laatst geverifieerde GitHub-hoofdcommit vóór de plan-PR: ``7be58c835c7557b1c81185adef1302d4ea8e24de``. De drie controles op PR #37 waren groen: [PHP #39376853774](https://github.com/pecharko/Veiling/actions/runs/39376853774), [kaveleditor #39376853710](https://github.com/pecharko/Veiling/actions/runs/39376853710), [snapshots #39376853769](https://github.com/pecharko/Veiling/actions/runs/39376853769). **Na iedere nieuwe commit moeten alle drie checks op de actuele commit opnieuw worden beoordeeld.**

De laatste technisch bevestigde OVHcloud-code-uitrol was [de auditrelease #39203389883](https://github.com/pecharko/Veiling/actions/runs/39203389883), op 19 oktober 2026 en gebaseerd op ``8b2e6b8f444f67952620163c523e04c5807a032e``. **De huidige SFTP-productie-inhoud is niet opnieuw geverifieerd.** De GitHub-diff is geen bewijs dat de remote code nog identiek is.

## Exacte runtime-allowlist voor één gezamenlijke release

| Volgorde | PHP-pad | Functie |
| --- | --- | --- |
| 1 | ``private/catalog_mutex.php`` | **Nieuw:** gedeelde MySQL-mutatievergrendeling |
| 2 | ``private/restore_journal.php`` | **Nieuw:** persistente herstelmarker en onderhoudsblokkering |
| 3 | ``private/snapshots.php`` | Consistente MySQL-snapshot, herstel en beide helpers |
| 4 | ``catalogus-public/admin/kavel.php`` | Lock voor kavels en foto's |
| 5 | ``catalogus-public/admin/instellingen.php`` | Lock voor instellingen |
| 6 | ``catalogus-public/admin/login.php`` | Lock voor aanmeldpogingen |
| 7 | ``catalogus-public/admin/backup.php`` | Lock over veiligheidssnapshot en daaropvolgende restore |
| 8 | ``private/bootstrap.php`` | **Laatste bestand:** schakelt PHP-onderhoudsgate in |

**Waarom één release?** De helpers zijn verplicht voor nieuwe PHP-code. Upload beide helpers eerst, afhankelijke private/admincode daarna en ``bootstrap.php`` als laatste. Acht SFTP-wijzigingen zijn niet atomair: een afgesloten admin-schrijfvenster en vooraf goedgekeurd rollbackplan blijven noodzakelijk.

**Niet meenemen:** ``private/config.php``, secrets, database, private/backups, snapshots, hersteljournal en bewijsbestanden, ``catalogus-public/uploads/``, foto's, .htaccess, overige runtimecode of assets. De vier operatorhelpers uit fase 3B–3E (``private/restore_diagnosis.php``, ``private/restore_finalize.php``, ``private/restore_precommit.php``, ``private/restore_mixed_diagnosis.php``) en de vier CLI-programma's in ``scripts/restore_*.php`` zijn **bewust uitgesloten**. Die kunnen alleen na afzonderlijke incidentbeveiligingsreview eventueel op een gecontroleerde omgeving worden gebruikt.

De snapshotmodule maakt een **data-only** back-up (database en foto's), geen broncodeback-up. De hier beschreven private code-rollback blijft afzonderlijk.

## Na het geverifieerde offline plan: aparte read-only remote preflight

De broncode-only [run #39378597480](https://github.com/pecharko/Veiling/actions/runs/39378597480) is **20 oktober 2026 geslaagd** op `ab4534c417bcf33b09cc13bc2de1ff726c068660`; een zeven dagen bewaard JSON-artifact is beschikbaar. De volgende fase [RECOVERY_REMOTE_PREFLIGHT_2026-10-20.md](RECOVERY_REMOTE_PREFLIGHT_2026-10-20.md) beschrijft een **aparte, opt-in, uitsluitend lezende** SFTP-controle van de daadwerkelijke productiecode, verwachte ontbrekende helpers en herstelmarkers. Dit is nog geen recht om live te inspecteren of te uploaden; de operator moet de workflow na menselijke review/merge **zelf en uitdrukkelijk** starten. Na latere repositorycommits moet ook de actuele bron-SHA opnieuw geverifieerd worden.

## Nieuwe offline planfase (geen productieconnectie)

Na menselijke review en merge van de plan-PR kan men in GitHub Actions handmatig **Plan catalog recovery runtime release (read-only)** kiezen op ``main``, met de volledige 40-tekens-SHA van die ``main``-commit. De workflow heeft uitsluitend ``workflow_dispatch``, géén deploymodus, géén productie-environment en **geen SFTP-secrets**.

De workflow doet alleen:

- broncontrole voor precies de acht allowlisted PHP-bestanden, inclusief broninhoud identiek aan de opgegeven Git-commit;
- basale controle op verplichte functies/afhankelijkheden en op symlinks;
- PHP 8.2-syntaxcheck en SHA-256 van elk lokaal bronbestand;
- een JSON-manifest, zeven dagen bewaard als GitHub Actions-artifact.

Lokale uitvoering op een gecontroleerde checkout:

```
python3 scripts/plan_catalog_recovery_release.py --release-sha <exacte-commit-SHA>
```

**Uitkomst van een geslaagd plan:** uitsluitend broncode gecontroleerd. Remote drift, hostkey, backupcapaciteit, MySQL, PHP-versie op OVHcloud, ontbrekende helperbestanden en ongewenste herstelmarkers zijn dan **nog niet onderzocht**.

## Verplichte productie-gates vóór een afzonderlijke deployment

1. **Securityreview + P1-risico's.** Bekijk de volledige diff PR #31–#37. [Issue #2](https://github.com/pecharko/Veiling/issues/2) blijft open voor onbekende/gemengde SQL-data, externe mutaties, statische/in-flight HTTP-verzoeken en volledige host/storagecrashes. De eigenaar moet deze beperkingen expliciet accepteren of eerst laten mitigeren.
2. **SFTP read-only preflight.** Controleer exact hostkey, productie-SFTP-root, remote bestanden en hun SHA-256 tegen de laatst geverifieerde toestand. **STOP** bij drift, symlinks, onbekende bestanden of ontbrekende verwachte mappen. De twee nieuwe helpers mogen uitsluitend als verwacht ontbrekend in een expliciet remote manifest staan.
3. **Herstelmarkercontrole.** **STOP** bij ``private/backups/.restore-active.json``, ``.restore-active.tmp-*`` of ``catalogus-public/.uploads-before-restore-*``. Verwijder nooit een marker of fotobewijs om onderhoud te omzeilen.
4. **Hostingvoorwaarden.** Bevestig PHP/runtime en ZIP-extensie, MySQL ``GET_LOCK``/``RELEASE_LOCK``, schrijf- en leesrechten voor private/backups, actuele quota en hostingruimte. Oude schijfruimtecijfers zijn niet voldoende.
5. **Twee soorten veiligheidskopieën.** Neem vóór de eerste byte upload een private kopie van de huidige **codebestanden** als 7-daags rollback-artifact; controleer daarnaast een afzonderlijke en **extern bewaarde data-only ZIP**. Meng die backups niet.
6. **Onderhoudsvenster + rollback.** Laat geen administratieve kavels, foto's, loginwrites of herstelacties lopen tijdens de niet-atomische bestandswissel. Verifieer een offline herstelpad en stop bij wijziging van remote bestanden tijdens de voorbereiding.
7. **Eerste expliciete goedkeuring.** Leg exacte release-SHA, acht paden, OVHcloud-doelomgeving, nieuwe gecontroleerde workflow, impact en risico's voor. Een groene CI is niet voldoende.
8. **Tweede expliciete goedkeuring.** Pas na een geslaagde offline planning, read-only remote preflight en beveiligde bestaande-codeback-up mag een apart door mensen gereviewde **deploy-workflow** na een *nieuwe* expliciete toestemming worden gestart. Vereis SHA-256 readback en best-effort rollback bij uploadfouten. Voer uitsluitend read-only HTTPS-smoketests uit en vraag vervolgens functionele adminacceptatie.

**Bestaande workflows** voor zes auditbestanden of twee snapshotbestanden voldoen niet aan deze achtpadendependency. Hergebruik of forceer ze niet.

## Wat ontbreekt nog na deze PR?

**Nog NIET uitgevoerd:** SFTP-preflight, remote driftcontrole, hostkeycontrole, bestaande-codeback-up, externe datasnapshotbevestiging, maintenancevenster, autorisatie, daadwerkelijke deployworkflow, upload, rollbackproef op hosting, rooktests en gebruikersacceptatie.

Het plan schrijft **niets** op 41lokeren.be of in MySQL. Voor de volgende stap is een **aparte handmatig gereviewde deployment-PR** nodig, gevolgd door twee afzonderlijke expliciete productietoestemmingen.
