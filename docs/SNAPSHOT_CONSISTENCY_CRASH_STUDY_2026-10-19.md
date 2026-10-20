# Issue #2 — consistentie- en crashonderzoek voor snapshots

**Datum:** 19 oktober 2026  
**Scope:** analyse en geïsoleerde, niet-productieve fault-injectiontests. Er zijn **geen** wijzigingen aan `private/snapshots.php`, `admin/backup.php`, productiedata of echte foto's. Dit document ontwerpt een toekomstige oplossing; het levert die oplossing **nog niet**.

## Vervolg op reproduceerbare tests — beperkte DB-fix in aparte PR

Na het mergen van PR #30 wordt als eerste, afzonderlijk te reviewen stap alleen de **databasehelft** van `snap_create()` aangepakt: `SET TRANSACTION ISOLATION LEVEL REPEATABLE READ`, een eigen `PDO::beginTransaction()`, alle `SNAP_TABLES` lezen en commit/rollback in alle foutpaden. Een bestaande transactie wordt geweigerd. Hiermee moeten alle vijf InnoDB-tabellen één consistent leesmoment delen. De aangepaste T-050A test dit expliciet met een gelijktijdige atomaire update; de oude onveilige uitkomst mag de test **niet** meer laten slagen.

**Niet inbegrepen:** atomair opslaan van DB én bestanden, afschermen van live fotobewerkingen tijdens ZIP-opbouw, recoveryjournal, onderhoudsmodus en herstel na een crash. T-050B blijft daarom een rode veiligheidsbevinding hoewel zijn huidige risicoreproducerende CI-test groen is. Alle onderstaande bevindingen beschrijven de historische uitgangssituatie vóór de DB-fix. Er is geen productieautorisatie verleend.

## Fase 2 in voorbereiding — application-wide MySQL-advisorylock

Na merge van PR #31 staat de consistente MySQL-read-view op `main`, maar **niet op productie**. De volgende PR voegt `private/catalog_mutex.php` toe met MySQL `GET_LOCK`/`RELEASE_LOCK`. Alle momenteel bekende adminschrijfroutes (`kavel.php`, `instellingen.php`, `login.php`) moeten de mutex bezitten gedurende hun volledige POST, inclusief opslag/verwijdering van foto's. `snap_create()` en `snap_restore()` verwerven dezelfde mutex voor hun volledige bewerking; de backup-UI houdt de mutex ook buitenom vast over **veiligheidssnapshot en daadwerkelijke restore** zodat geen nieuwe adminwrite ertussen kan plaatsvinden.

**T-051 geïsoleerd geslaagd op 19-10-2026:** [snapshot-CI-run #39321531424](https://github.com/pecharko/Veiling/actions/runs/39321531424) bevestigt dat concurrerende MySQL-verbindingen elkaar blokkeren, een nested functie kan dezelfde mutex veilig herhaald verwerven/vrijgeven, een geweigerde kavel-POST retourneert HTTP 503 zonder kavelmutatie en het stoppen van de eigenaar geeft de mutex op MySQL vrij. Niet-ondersteunde mutaties via phpMyAdmin of SFTP gebruiken de applicatiemutex niet. MySQL-connectieverlies kan het lock voortijdig vrijgeven; een duurzame hersteljournal blijft noodzakelijk voor volledige crashveiligheid.

De mutex vervangt geen onderhoudsmodus: bezoekers kunnen nog data en foto's op verschillende momenten lezen tijdens een restore. Door een SIGKILL na de fotoswap blijft de actief getoonde fotomap inconsistent met de database. De huidige T-050B detecteert dit nog steeds **bewust als aanwezig risico**. Voor echte crashbestendigheid blijft een idempotent hersteljournal, onderhoudsblokkade en fault-injection van elke restorefase nodig; het issue blijft P1 en open.

## Fase 3A — fail-closed hersteljournal (in PR, niet live)

Na merges #30–#32 zijn de MySQL-tabelmomenten en applicatiegecoördineerde foto-/adminwrites verbeterd in GitHub, maar de runtime is niet op productie bijgewerkt. De volgende ontwikkelstap publiceert onder `private/backups/` een kleine privé `.restore-active.json` vóór de eerste directory-rename. De fasen (`prepared`, `old_saved`, `photos_active`, `database_committed`) worden als atomair vervangende bestanden bijgewerkt; oude en tijdelijke bestanden blijven behouden zolang een hersteluitkomst onzeker is. `bootstrap.php` schakelt PHP-pagina's van site en admin over op HTTP 503 als de marker, een onvoltooid journaltempbestand of een verweesde oude fotomap aanwezig is. Na een **bewezen complete** restore of afgehandelde pre-commit-rollback wordt de marker gewist.

De huidige oplossing stopt niet vanzelf een corruptie van DB+files in het proces zelf; hij voorkomt alleen dat het PHP-portaal na een crash die mogelijke inconsistentie ongemerkt presenteert. Ook een DB-commit met onbekende uitkomst wordt **niet** automatisch teruggedraaid. **In [geïsoleerde Snapshot-CI-run #39324527061](https://github.com/pecharko/Veiling/actions/runs/39324527061) geslaagd:** T-050B/T-052 tonen echte SIGKILL en onderhoudsstatus; T-053 rolt een geïnjecteerde SQL-fout vóór commit volledig terug. T-054 toont de blijvende blokkering wanneer een reeds gecommitte database nog een verouderde journalfase heeft. Statische foto-URLs, CDN/browsercaches, al begonnen verzoeken en direct DB-/SFTP-gebruik zijn nog onbeheerd. File-`fsync` garandeert niet vanzelf dat de directoryentry op alle OVH-filesystemen crashdurable is.

**Extra regressie T-054:** een succesvolle MySQL-commit kan gevolgd worden door SIGKILL vóór de PHP-code de journalfase bijwerkt. De journalfase `photos_active` bewijst dan **niet** dat de database nog de oude toestand heeft. Test daarom zowel een crash vóór commit (T-050B/T-052) als onmiddellijk erna (T-054). Alleen na verificatie van doelgegevens en SHA-256 van de ZIP-foto's mag in de geïsoleerde testomgeving het achtergebleven fotobewijs worden opgeruimd. De productieapplicatie doet dit nadrukkelijk niet automatisch.

**Volgende stap na fase 3A:** bewijs eerst afzonderlijk welke MySQL-versie gecommit is (oude/nieuwe toestand) met een duurzame DB-markering of gevalideerde datafingerprint, maak een operator-geautoriseerde idempotente recoveryprocedure voor de verschillende fotomap- en transactiefasen en test elk SIGKILL-venster in CI. Pas daarna kan de onderhoudsblokkering gecontroleerd worden opgeheven. Uit veiligheid blijft een live restore verboden als test.

## Fase 3B — read-only classificatie vóór iedere recoveryactie (in review)

Na merge van PR #33 bevat de broncode een fail-closed hersteljournal en een geteste onderhoudsblokkering. De volgende veiligheidsstap is een **alleen-lezen incidentinspectie** (`private/restore_diagnosis.php`, `scripts/restore_diagnose.php --plan`): een inspecteur vergelijkt de bron-ZIP-checksum met het journal, valideert alle fotobytes binnen de ZIP, controleert alle vijf MySQL-tabellen in één consistent leesmoment en controleert de complete actieve fotomap zonder symlinks of onverwachte bestanden. Hij meldt afwijkingen of een mogelijke complete doeltoestand als niet-gevoelige status.

**Een journalfase is nooit commitbewijs.** T-050B controleert dat de classifier de gemengde DB/fototoestand na een pre-commit-SIGKILL herkent; T-054 controleert dat de classifier na een post-commit-SIGKILL de doeldatabase én foto's kan herkennen maar **de onderhoudsmarker niet wist**. **T-055 geïsoleerd geslaagd in [Snapshot-run #39326595129](https://github.com/pecharko/Veiling/actions/runs/39326595129):** tien checks, plus positieve classificatie na post-commit-SIGKILL en afwijzing na pre-commit-SIGKILL. T-055 controleert uiteenlopende DB- en fotowijzigingen, gemanipuleerde ZIP-hash, onvolledige journalgegevens, een verweesde fotomap en het ontbreken van herstelmutatiecommando's. Dit biedt geen automatische recovery, geen volledige bescherming van statische foto-URLs en geen algemene garantie tegen directe SQL/SFTP-wijzigingen. Alle proeven uitsluitend in CI.

## Fase 3C — geautoriseerde eindafhandeling, zonder gokrollback (in review)

Na fase 3B kan de volledige doeltoestand onafhankelijk worden beoordeeld. De volgende ontwikkeling biedt uitsluitend het **veilig beëindigen van een al aantoonbaar succesvol herstel na een procescrash**. Zij vereist een apart CLI-plan met doel- en veiligheidssnapshot-SHA-256, exact journalrun-ID, operatoridentiteit, huidige bewaarde fotomap en een SHA-256-approvalcode. Alleen na een andere, expliciete CLI-aanroep, exacte Nederlandse toestemmingszin en door operator geattesteerde offsite veiligheidskopie waarvan de berekende SHA-256 exact overeenkomt met de safety-ZIP mag het systeem het incident afronden.

Bij afronding worden alle gegevens opnieuw onder MySQL-advisorylock en de bestaande snapshot-`flock` gecontroleerd. De oude fotomap moet indien aanwezig **byte-voor-byte** overeenkomen met de safety-ZIP en wordt op hetzelfde filesystem atomair naar `private/backups/` overgebracht, zonder verwijdering. Een beperkt privéauditreceipt wordt opgeslagen; pas daarna wordt het journal verwijderd. Als het proces opnieuw stopt, kan een operator met een **nieuwe** challenge vanuit het resterende journal verder, maar de tool mag nooit zelfstandig verderwerken. T-056 gebruikt daarom een echte SIGKILL vlak na DB-commit én tijdens de latere afronding, en controleert de volledige approvalketen uitsluitend met fictieve data. **25 controles geslaagd** in [snapshot-CI-run #39372914703](https://github.com/pecharko/Veiling/actions/runs/39372914703) op 20-10-2026.

Een **pre-commit** procescrash die echte DB/foto-mismatch veroorzaakte blijft een afzonderlijke herstelzaak. Het berekenen van een target-match is geen legitimatie voor een automatische rollback, het verwijderen van fotoarchieven of het overschrijven van een onzekere database. Het issue blijft open zolang die gevallen niet gegarandeerd veilig reconstrueerbaar zijn. Ongeautoriseerde directe SQL-/SFTP-writes en niet ondersteunde hosting-fsync blijven beperkingen.

## Fase 3D — beperkte, goedgekeurde terugkeer naar originele fotomap na pre-commit-crash

Bij een bewezen processtop tussen foto's wisselen en MySQL-commit kan de MySQL-database nog exact dezelfde volledige toestand bevatten als de afzonderlijke safety-ZIP van vóór het herstel. Als zowel de oude als de nieuwe fotomap ook volledig aan de corresponderende safety- en doelarchieven voldoen, bestaat er een *foto-only* herstelpad zonder SQL-reconstructie. Een read-only plan met alle vijf database-tabellen en de volledige SHA-256-fotolijsten is de eerste, verplichte stap. Een aparte menselijke goedkeuring bindt run-ID, doel-/veiligheids-ZIP-hashes, operator, huidige herstelstatus en offsite-back-up-SHA.

Daarna bewaart `private/restore_precommit.php` alle doelfoto's in `private/backups/.restore-preserved-target-<run-id>`, reactiveert de oorspronkelijke fotomap en controleert alle tabellen plus beide fotocollecties opnieuw voordat een privéauditreceipt wordt geschreven en de journalmarker wordt vrijgegeven. Geen SQL-mutaties en **geen deletions van foto's**. Drie herhaalbare, met een nieuw plan te beoordelen statussen beschermen de twee niet-atomisch te combineren filesystem-renames na een SIGKILL. T-057 heeft dit met **22 geslaagde controles** getest in [Snapshot-CI-run #39374712561](https://github.com/pecharko/Veiling/actions/runs/39374712561), uitsluitend op fictieve MySQL-gegevens en foto's, nooit op een echte server. Dit is een **smalle** herstelmogelijkheid; pre-commit-crashes met gedeeltelijk gecommitte/gewijzigde SQL-data, een ontbrekende safety-ZIP, onbetrouwbare foto-evidence of een externe SFTP-mutatie blijven bewust in onderhoud totdat een expert een afzonderlijk herstelplan maakt. [Issue #2](https://github.com/pecharko/Veiling/issues/2) blijft P1/open tot bredere garanties aantoonbaar zijn.


## Fase 3E — behoudend en alleen-lezen onderzoek van onvergelijkbare database-inhoud

Na fase 3D bestaan alleen herstelacties wanneer de volledige actuele MySQL-database aantoonbaar exact overeenkomt met de vorige safety-ZIP (foto-only rollback) of de doel-ZIP (finalisatie van reeds gecommit herstel). Voor een werkelijk *gemengde* database is geen veilige automatische reconstructie beschikbaar. Fase 3E voegt uitsluitend een forensisch rapport toe: één `REPEATABLE READ`-read-view vergelijkt alle vijf tabellen gelijktijdig met safety en target, en meldt `both`, `safety_only`, `target_only` of `neither`. Een tweede databaseview, geverifieerde ZIP- en journal-SHA-256 en alle oorspronkelijke, actieve en privé bewaarde foto-SHA's helpen niet-coöperatieve mutaties tijdens onderzoek signaleren.

**T-058 geïsoleerd geslaagd (16 controles)** in [Snapshot-run #39376629673](https://github.com/pecharko/Veiling/actions/runs/39376629673): gemengde of derde rijtoestanden, aangetaste en veranderende fotobytes, gemanipuleerde ZIP, ontbrekend journal en afwezigheid van herstelcommando's. Uitsluitend wegwerp-CI-data. De uitkomst is **nooit** een SQL-hersteladvies of vrijgave van de PHP-onderhoudsmodus. Een live database of hostingincident vergt nog steeds deskundige beoordeling, externe veiligheidskopie en een apart geautoriseerd herstelplan. [Issue #2](https://github.com/pecharko/Veiling/issues/2) blijft P1/open.


## Vastgestelde risico's uit de huidige broncode

1. **Snapshot van verschillende momenten.** `snap_create()` leest `settings`, `admins`, `lots`, `lot_images` en `login_attempts` via afzonderlijke SELECT-statements zonder expliciete `START TRANSACTION WITH CONSISTENT SNAPSHOT`. De exclusieve `.operation.lock` wordt uitsluitend door snapshot- en herstelacties gebruikt, niet door gewone kavel-, veilinginfo- of fotoschrijfacties. Een gelijktijdige, atomaire wijziging van bijvoorbeeld `settings` en `lots` kan in de ZIP verschijnen als de oude instellingen plus het nieuwe kavel.
2. **Foto-bestand en metadata lopen uiteen.** `snap_assets()` scant een dynamische uploadmap en `ZipArchive::addFile()` kan bestanden later lezen dan `hash_file()`. Uploads of verwijderingen tijdens het opbouwen kunnen daardoor een onvolledige snapshot of checksumfout opleveren. De huidige controle valideert checksums wanneer snapshots worden geverifieerd, maar maakt de oorspronkelijke snapshot niet atomair.
3. **Procescrash in restore.** `snap_restore()` zet eerst `uploads` apart en activeert een nieuwe map, vóór `$pdo->beginTransaction()` en `$pdo->commit()`. De bestaande `catch`-rollback helpt bij afgehandelde exceptions, maar wordt niet uitgevoerd als PHP/host abrupt stopt (SIGKILL, stroomstoring). Een verweesde `.uploads-before-restore-*` blijft achter zonder duurzame hersteljournal of opstartblokkering.
4. **Cross-resource-transactie bestaat niet.** De MySQL-transactie kan databasewijzigingen terugdraaien maar niet een al uitgevoerde `rename()` van foto's. De omgekeerde volgorde (eerst DB, dan bestanden) verschuift alleen het crashvenster. Veilig herstel vereist een protocol en tijdelijk ontoegankelijke bewerkingen en publiekslezingen.
5. **Beperkte opslag.** Een kopie van foto's naast de bestaande map, ZIP-archieven en mogelijke oude herstelmap kan tijdelijk dubbel of meer schijfruimte nodig hebben. Op OVHcloud is de geregistreerde quota klein; controleer capaciteit vóór het maken van herstelstaging en breng die kosten in kaart. Een nieuwe journalingstrategie mag oude uploads niet vroegtijdig opruimen.

## Geïsoleerde reproducerende tests — T-050

De aparte CI-job `Snapshot en herstel — geisoleerde tests` draait op tijdelijke MySQL 8.4 / PHP 8.2 en gebruikt uitsluitend fictieve kavels en foto's:

| Scenario | Testbestand | Observatie waarop de test *moet* slagen (bewijs van huidig risico) |
| --- | --- | --- |
| T-050A, twee SELECT-momenten | `tests/snapshot_concurrent_probe.php` | Tussen het lezen van `settings` en `lots` pauzeert een **tijdelijke, alleen door het testproces ingeladen kopie** van de snapshotcode. Een tweede PDO-verbinding commit één transactie die beide tabellen wijzigt. De snapshot bevat de oude instelling en het nieuwe kavel. |
| T-050B, abrupte processtop | `tests/snapshot_sigkill_probe.php` | Na het activeren van de gerestaureerde fotomap maar vóór de DB-transactie forceert de tijdelijke kopie SIGKILL. De bestaande DB heeft nog een verwijzing naar een nieuw bestand dat niet in de actuele `uploads/` aanwezig is; oude foto's bestaan wel in de bewaarde voorgangersmap. De test herstelt **alleen zijn eigen fictieve CI-data** handmatig. |

**Belangrijke interpretatie:** groen in deze twee tests betekent *kwetsbaarheid reproduceerbaar vastgesteld*, **niet** dat snapshot/restore nu crashbestendig is. Verander later de acceptatieverwachtingen en tests wanneer de productiecode een oplossing bevat. De tests zullen bewust moeten worden herschreven naar een groen-veilig invariant zodra de fix bestaat.

Testcodes mogen alleen draaien met `GITHUB_ACTIONS=true`, `CI_SNAPSHOT_TEST=yes`, `CI_DB_HOST=127.0.0.1`, `CI_DB_NAME=auction_test` én een werkelijke verbinding naar database `auction_test`. Geen production secrets, DB-export of SFTP. Er wordt niets permanent aan de broncode gewijzigd; instrumentatie is tijdelijk en wordt opgeruimd. Een groen CI-resultaat geeft geen productie-autorisatie.

## Ontwerp voor een volgende, afzonderlijk te reviewen herstel-PR

**Fase A — applicatiebreed onderhouds- en schrijflock:**
- Maak een centraal protocol dat ALLE muterende routes dekt: kavel opslaan/verwijderen, fotoupload/-verwijderen, veilinginstellingen wijzigen, snapshot maken/herstellen en relevante beheer-mutaties. Documenteer hoe uitlog-/loginpogingen en `login_attempts` behandeld worden.
- Bij restore: sluit nieuwe muterende requests uit en wacht bestaande mutaties veilig af. Toon een duidelijke onderhoudsstatus op publiek/admin zolang een herstel niet aantoonbaar voltooid is. Lezingen mogen niet een half bijgewerkte DB/fotomap combinatie zien.
- Gebruik geen PHP-sessielock als applicatiebreed datalock. Een OS-bestandslock met expliciete time-outs kan werk verdelen, maar is niet voldoende voor crashherstel.

**Fase B — consistente snapshot:**
- Kies een consistente MySQL-InnoDB-read-view voor alle vijf tabellen (bijvoorbeeld `REPEATABLE READ` + `START TRANSACTION WITH CONSISTENT SNAPSHOT`) en verifieer tabelengines en MySQL-dialect in CI.
- Maak onder hetzelfde applicatiebrede schrijflock een stabiele bestandsset; voorkom mutatie tussen foto-hash en ZIP-sluiting. Test fotoverwijdering, upload en concurrerende settings-/lotupdate.
- Verifieer het gemaakte ZIP volledig met manifestchecksums vóór publicatie van de snapshot; een incomplete ZIP blijft onzichtbaar voor beheerders.

**Fase C — crashbestendig herstelprotocol:**
- Leg vóór directory-swap een durable, privé, strikt gevalideerd hersteljournal vast met run-ID, origineel snapshot-ID, rollbacklocatie, verwachte hashes en fase. Stel onderhoudsstatus ook persistent in; verwijder die niet automatisch bij het opstarten.
- Hou de originele fotomap en veiligheidskopie vast tot herstel en controles volledig zijn geslaagd. Ontwerp idempotente, handmatig of gecontroleerd automatisch te hervatten/terugdraaien states, zowel vóór swap, na swap, tijdens DB-commit als na commit.
- Verifieer zichtbaarheid, sessie-invalidatie, checksumherstel en foutpaden onder SIGKILL bij **elke** state. Speciale aandacht: MySQL-commit kan voltooid zijn terwijl PHP het resultaat nooit heeft ontvangen — een recoveryroutine moet de DB-versie vaststellen in plaats van dat resultaat te gokken.
- Geef de beheerderspagina een expliciete foutmelding/onderhoudsschakelaar en herstelprocedure zonder publieke installer. Test toegang via onbevoegde requests.

**Fase D — veilige release:**
- Eerst een aparte runtime-PR met tests voor herstelbaarheid (groene **veilige** invarianten), documenten FR-015/017/019/020, rollback- en migratiedocumenten, gevolgd door handmatige review.
- Geen hersteltest op productie. Het eerste productiepad zou een alleen-codewijziging via expliciet toegestane bestandslijst, offline plan, hashcontrole, pre-upload-codeback-up, read-only smoke-tests en afzonderlijke dubbele goedkeuring moeten zijn.
- Open issue #2 tot de tests niet alleen de crash reproduceren maar ook het herstel na echte processtop in disposable CI met automatische detectie aantonen.

Voor de handmatige triage van een echte processtop, uitsluitend met veilige leesacties, zie ook [RESTORE_INCIDENT_RUNBOOK.md](RESTORE_INCIDENT_RUNBOOK.md). Dit is geen automatische hersteltool.

## Voorlopige noodprocedure (alleen voor incidentanalyse; niet blind uitvoeren)

Als een herstelactiescherm onverwacht stopt: **geen** nieuwe snapshots, uploads, deletes of restorepogingen uitvoeren totdat de toestand is vastgesteld. Noteer het tijdstip en de fout, controleer onder strikt beperkte toegang of `.uploads-before-restore-*` en herstelstaging bestaan en bewaar die zonder verwijdering. Bepaal daarna met offline kopieën welke MySQL- en foto-versie bij elkaar hoort; probeer geen `mv` of database-rollback op productie zonder een apart goedgekeurd incidentplan en actuele back-up. Bevoegde beheerder/hostingexpert voert herstel met gecontroleerde downtime uit.
