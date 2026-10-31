# Database And Snapshots

Deze referentie bevat bestaande instructies en casussen, zonder nieuwe autorisatie. Volg eerst SKILL.md en het actuele repositorycontract.

## Databaseback-up bij productie-DDL — optioneel, expliciet besluit (31 oktober 2026)

- Een **extra databaseback-up is niet verplicht** voor een database-/schemamigratie. Leg voor elke release vast: `extra_db_backup = ja | nee`. De eigenaar kan expliciet `nee` kiezen; vraag dit niet opnieuw zolang dezelfde release en keuze gelden. Bij onbekende keuze: geen productie-DDL.
- Bij `nee`: documenteer vooraf de exacte schema-uitgangstoestand, impact op bestaande tabellen/rijen, of MySQL-DDL gedeeltelijk kan slagen, mogelijke onomkeerbaarheid of dataverlies, een concreet stop-/reconciliatieplan na gedeeltelijke uitvoering, en de uitdrukkelijke acceptatie van het resterende risico. Noem dit nooit gelijkwaardig aan een echte database-back-up en beloof geen herstel van verloren data.
- Beperk bij een additieve migratie de DDL tot de geautoriseerde nieuwe tabellen. Controleer bestaande objecten read-only, voer stappen gecontroleerd en in volgorde uit, verifieer ieder resultaat, stop bij onverwachte toestand, en doe geen automatische retry of destructieve rollback (zoals `DROP TABLE`) zonder afzonderlijke toestemming.
- Een bestaand backup-/restore-, snapshot-, cron- of code-releasemechanisme wordt hierdoor **niet** gewijzigd of uitgeschakeld. Expliciete exacte eigenaarautorisatie voor productie-SQL, migratiepad, rollback-/incidentafhandeling en eventuele andere mutaties blijft verplicht.
- Dit is uitsluitend een **voorgestelde beleidswijziging** tot een afzonderlijk geautoriseerde merge. Een draft-PR of groene CI geeft geen productieautorisatie.

## Databasewijzigingen: vooraf snapshotcompatibiliteit uitvragen (28 oktober 2026)

- **Verplicht beslismoment bij elke DB-schema- of datamigratie:** vraag vóór ontwerp of aanpassing van migratie, snapshotcode, restorecontract en tests één gerichte vraag: **"Moeten snapshots/back-ups van vóór deze databasewijziging na de upgrade nog kunnen worden hersteld?"** Leg het antwoord per issue/release expliciet vast als `oude_snapshots_herstellen = ja | nee | onbekend`. Vraag dit niet opnieuw voor hetzelfde wijzigingspakket wanneer het al beantwoord is; neem niets stilzwijgend aan. Bij `onbekend` geen destructieve herstelontwerpbeslissing of productiemigratie voorbereiden alsof er toestemming is.
- **Bij `ja`:** ontwerp en test achterwaarts compatibele snapshots/restores, schema-transformaties en ontbrekende/extra kolommen; verifieer met representatieve oude snapshots in een geïsoleerde testdatabase. Bestaande productieautorisatie- en rollbackgates blijven gelden. De databaseback-up is een afzonderlijke, expliciet te kiezen optie volgens het migratiecontract hieronder.
- **Bij `nee`:** ondersteun oude snapshotformaten niet onnodig; beperk het restorecontract en regressietests tot snapshots gemaakt na de migratie. Maak expliciet duidelijk dat oude snapshots mogelijk niet herstelbaar zijn. Dit is **geen toestemming om bestaande snapshots te verwijderen**. Een aanvullende databaseback-up is optioneel conform het migratiecontract hieronder; een risicobeoordeling en herstelplan blijven vereist. Weiger of markeer oude snapshots helder in de UI in plaats van ze half te herstellen, indien relevant.
- **Specifieke keuze issue #354:** gebruiker heeft bevestigd dat oudere snapshots niet ondersteund hoeven te worden. Registreer `oude_snapshots_herstellen = nee` voor de uitnodigings- en last_login_at-migratie. Pas bij verdere ontwikkeling van issue #354 de snapshot-/restorecode en tests zo nodig aan aan dit contract; geen onnodige legacy-compatibiliteit. Dit wijzigt **niet** automatisch reeds gemergde code of een lopende release-PR. Herbeoordeel de diff en CI vóór merge/release volgens bestaande gates.


## Issue #224: dagelijkse archivering bezoekersstatistieken

De vierbestanden-release op commit `e2f8a800725166f3dae188ff1a6b3fa2ec301b88` is geaccepteerd na run `40411854612`. PR #228 corrigeerde uitsluitend de exitcode van het cron-script: `private/visitor_stats_daily_cron.php` geeft bij `archive_catchup_limit_reached` exitcode 2. PR-head `746ffe630bf3e3245433537c55a4f0862f6010de`, mergecommit `0df667a67d9b877b4b4a691358a6ce26ec3d4ed5`. Code staat op GitHub main; **niet als productiedeploy beschouwen**. Na die merge: main push-CI en specifieke éénbestands-read-only OVH preflight verder voorbereiden volgens het automatische stappenplan.

Cron is **niet geactiveerd** en archivering **niet uitgevoerd**. Voor activatie vereist: OVH CLI/cronmogelijkheden en exact absoluut pad, tijdzone en DST voor gewenste 03:30 Europe/Brussels, MySQL-schema/quotum read-only, data-only back-up met geïsoleerde hersteltest, exitcode/stderr monitoring inclusief catchup-limit en ontbrekende successen, expliciete autorisatie voor cronactivering en aparte autorisatie voor eerste archivering met DELETE. Bewaar detailrecords ten minste zeven volledige UTC-dagen; archiefaggregaten volgens afgesproken einddatum 31 oktober 2027. Cron scheduler verwerkt hoogstens 14 dagen per run en maximaal 20.000 bronrijen per UTC-dag. Dat de code `exit(2)` heeft, bewijst nog geen werkende notificatie.


