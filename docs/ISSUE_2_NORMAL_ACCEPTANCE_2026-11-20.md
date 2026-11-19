# Issue #2 — reguliere snapshot- en herstelacceptatie (20 november 2026)

## Besluit en afbakening

De eigenaar heeft op 19 november 2026 besloten **geen nieuwe ondersteuning voor uitzonderlijke crashscenario's** te verlangen. Deze acceptatiematrix beoordeelt de reguliere backup- en restorewerking en verandert **geen runtimecode of bestaande veiligheidsmechanismen**. Een volledige hosting-/storagecrash, stroomuitval op een kritiek commitmoment, reeds lopende HTTP-verzoeken of niet-coöperatieve phpMyAdmin/SFTP-writes zijn geen nieuw te ontwikkelen gegarandeerde automatische herstelpaden. Bij incidenten blijft fail-closed gedrag verplicht en is deskundige, apart geautoriseerde interventie mogelijk noodzakelijk. Productie is geen destructieve hersteltestomgeving.

**Vastgestelde bronstaat:** `main` commit `ec0ffeef6c8e190535536702ed61d176259279c3`. `private/snapshots.php` gebruikt v8 `41lokeren-v8-catalog-only`, zes catalogustabellen (`settings`, `lot_categories`, `lot_sellers`, `lots`, `lot_images`, `seller_images`) en foto's. Adminaccounts/inloggegevens, uitnodigingen, permanente lotnummerreserveringen en bezoekersstatistieken worden bij een catalogusrestore niet teruggezet; bestaande live administratieve en statistische staat blijft behouden. Oude snapshotformaten worden niet ondersteund. Dit actualiseert de historische v7/vijf-tabellen/account-herstelteksten elders in de documentatie; die historische bevindingen zijn geen actuele v8-acceptatie.

## Bewijs en oordeel

| Regulier criterium | Bewijs | Oordeel |
| --- | --- | --- |
| Snapshot van catalogus en foto's met consistente databaseleesweergave | `snap_create()` neemt de catalogusmutex, gebruikt `REPEATABLE READ` over alle zes tabellen en houdt de mutex tijdens fotoarchivering; [snapshot-CI 44553799205](https://github.com/pecharko/Veiling/actions/runs/44553799205) T-050A en T-051 | **Aangetoond voor door applicatiemutex gecoördineerde wijzigingen** |
| Nieuwe ZIP bruikbaar en volledig | `snap_verified_file()` controleert DB-manifest, v8-structuur, fotoreferenties en iedere foto-SHA-256 ná `ZipArchive::close()` en vóór publicatie; T-061 in dezelfde CI | **Aangetoond in geïsoleerde CI** |
| Snapshot maken, downloaden, verwijderen en herstel via admin | T-011/T-012/T-045; actuele v8-fotorestore en geauthenticeerde HTTP-flow in snapshot-CI 44553799205; productiegebruiker bevestigde eerder creatie en externe ZIP-download (oktober 2026) | **Aangetoond in geïsoleerde CI, geen actuele productie-restore** |
| Ongeldige ZIP en corrupte fotobytes weigeren (T-013) | T-061: CRC-geldig, maar manifest-SHA-256 afwijkend geeft weigering vóór DB-/fotowijziging; pad/format/versie-controles | **Aangetoond voor geteste corruptiegevallen; geen claim dat ieder mogelijk ZIP-defect is getest** |
| Veiligheidskopie vóór restore en rollback bij afgehandelde fout (T-014) | Geauthenticeerde HTTP-hersteltest controleert voorafgaande veiligheidssnapshot; T-053 injecteert SQL-fout en controleert herstel van oorspronkelijke DB- en fotostaat | **Aangetoond voor normaal pad en geïnjecteerde afgehandelde SQL-fout; geen offsite-recoveryattest** |
| Ruimte en waarschuwingen (T-015/016/017) | T-016 controleert 80%/90%; T-017 weigert ontoereikend quotum inclusief 20 MiB marge; eerdere productie-OVH Managervergelijking: app 41,5 MB versus OVH 38 MB | **Drempel- en grensgedrag aangetoond in CI; echte OVH-quotawaarde slechts indicatief, verschil 3,5 MB niet verklaard** |
| Gelijklopende kavel-/fotobewerkingen (T-027) | T-050A bewijst één consistente DB-read-view, T-051 dat een concurrerende geconfigureerde writer niet binnentreedt bij de mutex; HTTP-kavelblokkering in [kavel-CI 39321530958](https://github.com/pecharko/Veiling/actions/runs/39321530958) | **Deels afgedekt; oorspronkelijke volledige end-to-end gelijktijdige kavel/foto-creatie tijdens snapshot én restore niet afzonderlijk uitgevoerd** |
| Veilig handmatig herstel en actueel extern back-upbestand | Runbook beschrijft stop/triage, afzonderlijke menselijke toestemming en offsite-evidence; externe ZIP-download/controle in oktober gerapporteerd | **Procedure aanwezig, maar geen actueel onafhankelijk geverifieerde offsite-v8-ZIP/herstelproef** |

## Productie- en bewijsgrenzen

De broncode in `private/snapshots.php` heeft dezelfde Git-blob op de genoemde `main` als de [geslaagde, afzonderlijk geautoriseerde éénbestandsupload 44569314076](https://github.com/pecharko/Veiling/actions/runs/44569314076): de uploader logt `VERIFIED private/snapshots.php c63e3396f761bed826bdacaf24ede4c3a888adacd1c6ada914fc3ff2b376dc39`. Dat is een uploader-eigen hash-herlezing, **geen afzonderlijk geauthenticeerde OVH-SFTP-readback** en geen bewijs dat live herstelfunctionaliteit werd getest. Oudere documentatie die zegt dat alle snapshotcode nog niet live is, is in die algemene vorm verouderd; maak geen uitspraak dat elk historisch herstelhulpmiddel onafhankelijk live geverifieerd is.

Op `main` blijven het fail-closed hersteljournal, checksums, locks en afzonderlijke herstelautorisaties intact. Dat uitzonderlijke scenario's niet meer tot de nieuwe productvereisten behoren, betekent **niet** dat een ambigu of beschadigd herstel op productie alsnog mag worden afgedwongen.

## Besluit voor issue #2

**Advies: nog niet als volledig bewezen afsluiten.** De reguliere kern is overtuigend getest, maar er zijn drie expliciet te beheren lacunes: (1) T-027 slechts indirect afgedekt door T-050A/T-051, (2) de OVH-opslagschatting is geen geverifieerd officieel quota, (3) geen actuele onafhankelijk geverifieerde externe v8-datasnapshot met gecontroleerde herstelbaarheid. Deze hoeven geen nieuwe ondersteuning van uitzonderlijke crashes te veroorzaken.

Voor sluiting: leg schriftelijk een productacceptatiebesluit vast over deze drie punten, of voer de nog vereiste **niet-destructieve** controles in een geïsoleerde omgeving uit. Herstel nooit destructief op productie als test. Dit rapport is uitsluitend documentatie/evidence en verleent geen merge-, deploy- of restoretoestemming.

## Referenties

- [Issue #2](https://github.com/pecharko/Veiling/issues/2) en eigenaarbesluit in issuecommentaar van 19 november 2026
- [Snapshot-integratie-CI 44553799205](https://github.com/pecharko/Veiling/actions/runs/44553799205), inclusief de snapshot-isolated job en concrete T-061-, T-050A-, T-051- en T-053-asserties
- [Snapshotcodeproductierelease 44569314076](https://github.com/pecharko/Veiling/actions/runs/44569314076)
- [docs/TESTPLAN.md](TESTPLAN.md), [docs/REQUIREMENTS.md](REQUIREMENTS.md), [RESTORE_INCIDENT_RUNBOOK.md](RESTORE_INCIDENT_RUNBOOK.md)
