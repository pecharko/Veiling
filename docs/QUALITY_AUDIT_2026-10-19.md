# Technische kwaliteits- en veiligheidsaudit — 19 oktober 2026

**Repository:** `pecharko/Veiling` · **Methode:** statische inspectie van de actuele GitHub-broncode en CI-/deploymentconfiguratie. **Geen productie- of datamutaties; geen live inlog-, restore-, browser- of pentest uitgevoerd.** Bestaande productieacceptatie is waar vermeld gebaseerd op eerdere GitHub Actions-runs en feedback van de beheerder.

## Samenvatting en prioriteiten

| Prioriteit | Bevinding en bewijs in broncode | Aanpak en verificatie |
| --- | --- | --- |
| **P1** | `private/snapshots.php`: `snap_restore()` verwisselt de fotomap vóór de databasecommit (ca. regels 100–117). Een procescrash of gelijktijdige kavelwijziging kan een inconsistente toestand achterlaten. De bestaande integratietests bewijzen geen crashherstel. | **Bestaand issue [#2](https://github.com/pecharko/Veiling/issues/2)**. Ontwerp applicatiebreed onderhouds-/schrijflock, consistent DB+files-snapshotprotocol, hersteljournal en fault-injectiontests in disposable MySQL. Geen testrestore op productie. |
| **P2** | `catalogus-public/admin/kavel.php`: SQL INSERT/UPDATE wordt uitgevoerd vóór `add_lot_image()`; een fout bij een volgende foto resulteert expliciet in een *gedeeltelijk bewaard* kavel. Dit gedrag wordt momenteel zelfs door `tests/kavel_http_integration.php` als bestaand gedrag getest. | Beslis of gedeeltelijke foto-opslag een expliciet aanvaarde productregel is; anders atomair gedrag ontwerpen voor database en bestanden met compensatie bij fouten. Voeg tests toe voor tweede/derde foto en I/O-falen. |
| **P2** | `catalogus-public/admin/logout.php` accepteert GET zonder CSRF. Elke navigatie naar de route beëindigt de sessie; dit is geen gegevenslek, wel een onbedoelde state change. In `private/bootstrap.php` ontbreekt een beheerderssessie-inactiviteitslimiet. | Maak logout POST met CSRF en test GET zonder sessiewijziging, POST met/zonder token en heropenen van admin. Beoordeel een passende time-out afzonderlijk. |
| **P2** | `docs/AUTO_MERGE.md`: de eigen auto-merge is opt-in met een label, maar GitHub Free zonder afgedwongen required checks laat beheerders de conventie omzeilen. | Controleer schrijfrechten en auditlog, en activeer branch protection/ruleset zodra het abonnement dit toestaat. **Geen** verruiming van de CSS-/documentatie-allowlist. |
| **P3** | Na verwijdering van bulkfoto-import bleef in `admin/kavel.php` het advies staan 'gebruik je de foto-import' en in `admin/backup.php` 'of foto-import'. | Verwijder verouderde tekst en voeg broncode-regressiebewaking toe via bestaande PHP-CI; zie de begeleidende PR. |

## Positieve controles in de broncode

- Login gebruikt `password_verify()`, `session_regenerate_id(true)`, CSRF en een IP-hash-gebaseerde pogingenteller; adminfuncties vereisen `require_auth()`.
- Publieke kaveldetails controleren zowel `is_published` als de status `published`; SQL-invoer verloopt via voorbereide statements.
- Foto-upload controleert MIME, afmetingen en bestandsgrootte, bewaart met onvoorspelbare bestandsnamen, en beperkt het aantal per kavel. Er bestaan geïsoleerde positieve en negatieve uploadtests.
- CSV-import en definitief geschrapte bulkfoto-import geven 404 zonder muterende applicatielogica. De fotoupload per kavel blijft bestaan.
- De deploymentworkflows werken handmatig, met expliciete bestandslijsten, hostkeyverificatie, back-up en SHA-256-controle. Dit is geen bewijs dat elke rollback slaagt na procesafbreking.
- De drie verplichte controles zijn geautomatiseerd in GitHub Actions, inclusief geïsoleerde kavel- en snapshot-integratietests. Dit dekt niet alle publieke en administratieve browserflows.

## Openstaande verificatie en aanbevolen volgorde

1. **Eerst P1:** ontwerp- en testplan voor consistente snapshot/restore (#2); geen destructieve productieproeven.
2. **Daarna P2:** bepaal gewenste semantiek voor kavel+meerdere foto's; test I/O-falen. Hardening van logout en sessieduur in afzonderlijke handmatig te reviewen PR.
3. **Daarna P2:** publicatie-instellingen, zoekfilters, mobiele weergave en toegankelijkheid end-to-end testen, inclusief geldige/onmogelijke datum, anonieme routebeveiliging en systeemcheck.
4. **P3:** verouderde teksten opruimen en regressietests in de bestaande lintworkflow onderbrengen.

## Scope en vrijgave

Deze audit wijzigt zelf geen productiecode of data. Een eventuele code-PR wordt niet automatisch gemerged als PHP, beveiliging of deployment betrokken is. Na drie groene CI-checks volgt menselijke review; een merge is **geen** productie-uitrol. Voor een gerichte release zijn telkens een passende handmatige workflow, offline plan, hashes, back-up, afzonderlijke expliciete toestemming en read-only smoketests nodig.
