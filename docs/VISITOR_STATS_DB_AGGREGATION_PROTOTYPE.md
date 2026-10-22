# SQL-aggregatie per UTC-dag — onderzoeksprototype

**NIET releaseklaar, geen productie-ingreep.**

In deze branch vervangt SQL-side groepering de PHP-arrays met per dag en kavel unieke bezoekerscodes. De archivering loopt per historische UTC-dag door `INSERT ... SELECT COUNT(DISTINCT visitor_id)` en telt views in MySQL. Alle dagen blijven vooralsnog in **een enkele transactie** om te voorkomen dat bij fouten een deel van de historische dagen definitief gearchiveerd wordt.

Isolatietests omvatten nu 1001 bezoekersrecords op een historische datum plus eerdere testen voor tellingen, conflict, foutdata, herstel en behoud van recente gegevens. De vroegere 1000-recordgrens is daarom uit deze onderzoeksbranch verwijderd.

**Openstaande blokkeringen**: aantonen dat de transactie onder gelijktijdige INSERT/UPDATE-gebeurtenissen daadwerkelijk voldoende locks houdt; test MySQL 8.4 en queryplannen; lockduur/performance bij grotere gegevensvolumes; PHP/PDO MySQL buffered result bij de datumlijst; database-temporary-table ruimte bij `COUNT(DISTINCT)`; deadline-/memorygrenzen. Tot dan NIET mergen of deployen. Alternatief: begrensde batches met hervatbare checkpoints, na aparte evaluatie van gedeeltelijke archivering.

SQL-schema, GitHub Actions-workflows, publieke tracking en OVH zijn niet gewijzigd. Geen automatische opschoning.

## Isolated concurrency check added

Two independent MySQL sessions now test an uncommitted UPDATE lock on a historic source record. The archive must fail closed with lock-wait error, roll back without deleting details or retaining day aggregates, and succeed after the competing transaction is rolled back. This tests an **existing-row lock conflict only**. It does **not** establish protection against a concurrent new INSERT into the archived day (phantom row) or all possible isolation levels. No production authorization until those cases, lock scope and query plans are independently proven safe.

## Phantom INSERT gap-lock isolation regression

Added a two-session isolated MySQL test: a REPEATABLE READ locking date-range read on the composite primary-key prefix `visit_date` should reject a concurrent INSERT into that historical date while the lock is held (lock timeout). This validates the **locking primitive only**. The current archive implementation does not yet explicitly acquire this date-range locking read; therefore this test is **not evidence the archive itself prevents phantom inserts**. Keep this PR in Draft, do not deploy. Determine a bounded-memory way to acquire suitable date-range locks before aggregation, then add a deterministic end-to-end concurrent archive/INSERT test. Verify behavior at OVH's actual isolation level in a disposable environment, never on live data.

## Prototype locking update (requires CI and review)

The archive now requests REPEATABLE READ for its transaction and scans each historical UTC date using `SELECT page_kind, views ... FORCE INDEX(PRIMARY) ... FOR UPDATE`. PDO MySQL buffered queries are disabled only for this streaming lock scan and restored after closing the cursor. This avoids building a PHP-side per-visitor list while acquiring InnoDB next-key locks for each selected historic day. This is still a prototype: test under actual MySQL 8.4 isolation, including INSERT attempts while archival transaction is active and a date initially contains no rows. Review lock wait time, deadlocks and undo/temporary storage. The date discovery query is not a locking read; if a new previously absent historic day can be inserted during the same transaction, a separate range-level guarantee is required. Do not merge or deploy until proven.

## Initially empty historic day regression

An additional isolated MySQL two-session test now acquires a locking read on a **previously empty historic UTC day** and checks whether an INSERT into that date is blocked. This isolates the index-gap behavior. It does not prove the current archiver, whose non-locking date discovery may miss a newly inserted date; only an end-to-end deterministic test of the archive function can close that gap. CI on the new PR head must be green before interpreting the test. Retain Draft and no-production status until both pre-existing and previously-empty date INSERT races are resolved, with bounded locking and documented isolation assumptions.

## Historical-prefix range protection proposal

The archiver's initial day discovery now uses an unbuffered `SELECT visit_date ... WHERE visit_date < ? ORDER BY visit_date FOR UPDATE` with a forced primary index under REPEATABLE READ. It keeps only distinct dates in PHP while attempting to hold the *entire historical index prefix* locked through aggregation and deletion, including gaps for previously empty historical dates. This supersedes the earlier nonlocking date-discovery query. This implementation has not yet been confirmed under CI on the latest commit. A deterministic end-to-end race test against `archive_visitor_stats_before()` itself is still required before merge. This can create wide locks and block historical writes; measure timeout and deadlock behavior, and do not run on production. The SQL schema and public tracking are untouched.

## End-to-end archive / phantom INSERT regression

An end-to-end test now starts an uncommitted INSERT for a previously empty historical UTC date on a second MySQL connection, then calls the real `archive_visitor_stats_before()` on the first connection with a short lock timeout. Expected: archive fails closed without a persisted aggregate, no open transaction remains; after INSERT commits, a fresh archive includes it. This intentionally exposes unsafe historical-prefix locking if the archive returns success. The test must actually pass on the latest PR SHA before approving anything. This is distinct from the earlier gap-lock primitive tests; there is still no production validation or authorization.

## Isolated load regression — 3,000 historical records

A new test in `tests/visitor_stats_mysql_integration.php` inserts 3,000 distinct visitors into an isolated historical UTC day, runs the real archiver, checks aggregate counts and source deletion, measures elapsed time and peak PHP memory, and fails if PHP peak memory reaches 64 MiB. There is deliberately no strict wall-clock threshold on a shared CI runner. This is a small-scale regression, NOT a production-scale benchmark; MySQL server memory, disk, temporary-table use, lock waits, and worst-case campaign sizes remain unproven. No schema, workflow or production actions.

## Competing historical row lock regression

An added two-session isolated MySQL regression holds an uncommitted `SELECT ... FOR UPDATE` lock on an old visitor row, invokes the actual archive with a 1-second InnoDB lock-wait timeout, verifies fail-closed with no day aggregate and no open archive transaction, releases the lock and verifies successful retry. The test accepts either MySQL lock timeout (1205) or deadlock victim (1213); it does not force a genuine cyclic deadlock. CI must pass on the exact updated head. A separate deterministic two-way deadlock simulation and server resource profiling remain open, and neither production data nor hosting is touched.

## Synthetic cyclic deadlock regression

A separate isolated-MySQL test now forks a second PHP process, creates independent PDO connections, has each hold a row lock on opposite historical UTC dates, and requests the other's lock concurrently. It checks that InnoDB reports a deadlock victim (`1213`), releases both transactions, and verifies that normal archiving works afterward. This is a **synthetic database deadlock**, not a guaranteed archive-versus-archive deadlock. The test is conditional on `pcntl_fork` and `stream_socket_pair` availability; CI must verify these functions are present, otherwise the scenario is skipped and cannot be claimed tested. No production operation is authorized.

## Technische review 2026-10-21 — vrijgavevoorwaarden

- Exacte CI-head `385aef28ab827a9cdc4883aa0312c1195506927a`: alle vier controles geslaagd. MySQL-job bevestigt expliciet `Cyclic InnoDB deadlock regression passed: victim 1213 and archive recovery` en 3.000 bronrecords in 0,016 s bij gemeten PHP-piek 2,0 MiB.
- Dit is een **synthetische twee-verbindingen-deadlock** en een kleine geïsoleerde dataset; het bewijst niet dat twee gelijktijdige archiefverzoeken veilig zijn en meet geen MySQL-side geheugen-, tijdelijke-schijf- of undo-ruimte op OVH.
- **Blokkerend voor runtimevrijgave:** volledige historische `SELECT ... FOR UPDATE` blijft gedurende alle SQL-aggregaties en deletes vergrendeld. Beoordeel maximumduur, concurrentie met bezoekersregistraties en adminverzoeken, herstel na lock wait/deadlock en databasequota vóór productie.
- **Blokkerend voor schema/datarelease:** maak een controleerbare statistiek-SQL-back-up, test herstel in isolatie en review migratie/ruimtebeslag. Een groene GitHub-CI is geen productie-autorisatie.
- **Mergegate:** deze Draft PR bevat database-archivering en gegevensintegriteit en vereist daarom menselijke review en expliciete mergebeslissing; nooit auto-merge. Release en eventueel databasescript krijgen elk afzonderlijke autorisatie. Geen productieacties zijn uitgevoerd.

## Capaciteitsbeoordeling en preproductie-acceptatie (geen OVH-toegang)

Code-inspectie: de eerste `SELECT visit_date ... WHERE visit_date < ? ... FOR UPDATE` neemt locks over **alle** historische records vóór de grensdatum, en houdt die vast tot alle dagaggregaties, kavelaggregaties en deletes zijn gecommit. Daarom begrenst SQL-aggregatie wel de PHP-geheugenopbouw van bezoeker-ID's, maar **niet** de hoeveelheid MySQL-werk, tijdelijke opslag of de duur van de locks. Een archiveringsverzoek met veel historische dagen is één enkele transactie; de grootste risico's zijn langdurige blokkering van gelijktijdige writes, grote undo/redo-activiteit en temp tables voor `COUNT(DISTINCT visitor_id)` en `GROUP BY lot_id`.

Voer vóór runtimevrijgave in een **wegwerpdatabase** met productie-achtige, niet-identificeerbare testdata uit:

1. Vul ten minste kleine, normale en extreme datasets, waaronder veel unieke bezoekers per dag, veel kavels, meerdere historische UTC-dagen en gelijktijdige schrijfacties. Registreer bronrecordaantal, omvang van tabellen/indexen en totale DB-ruimte.
2. Inspecteer `EXPLAIN` voor historische lockscan en beide `INSERT ... SELECT`-aggregaties; leg gebruikte indexen, scans en eventuele tijdelijke tabellen vast. Gebruik uitvoeringsmetingen zonder privégegevens op te nemen.
3. Meet doorlooptijd, InnoDB row-lock waits, deadlocks, piek `Created_tmp_disk_tables`, tijdelijke ruimte en undo/redo-groei; controleer of de bestaande 250 MB OVH-databasequota met voldoende marge gerespecteerd kan worden. De PHP-meting van 2 MiB is hier **geen** bewijs voor.
4. Start tijdens een langlopende archivering concurrerende inserts voor nieuwe/historische dagen en een tweede archivering; bewijs fail-closed of gecontroleerde serialisatie zonder gedeeltelijke aggregaten. Controleer expliciet dat publiek verkeer niet onaanvaardbaar wordt geblokkeerd.
5. Stel objectieve limieten vast voor maximaal aantal bronrijen/dagen per handmatige batch en maximale lockduur. Indien die niet bewezen kunnen worden, ontwerp eerst een kortere, gecontroleerde batchtransactie met veilige opvolgende hervatting; **niet** simpelweg een timeout instellen zonder te testen of herstel correct blijft.
6. Voor productie: maak een apart verifieerbaar data-only SQL-back-up- en herstelplan voor de drie statistiektabellen; review schema-impact en privacybewaring. Geen enkele databasehandeling is door deze PR geautoriseerd.

Uitkomst huidige review: **niet vrijgeven voor productieruntime op basis van de 3.000-rijentest alleen**. Geen productiebenchmarks of productiegegevens geraadpleegd.

## Prototype veiligheidslimiet, nog geen batchverwerking

De archiveringsfunctie weigert een handmatige opdracht zodra de historische prefix meer dan **20.000 bronrijen** bevat, vóór aggregatie of verwijdering. De geïsoleerde regressietest controleert dat 20.001 bronrijen geen aggregaten of dataverlies veroorzaken en dat een teruggebrachte dataset van 15.000 rijen vervolgens wordt verwerkt. Dit is een conservatieve **fail-closed bovengrens**, niet de gevraagde daadwerkelijke batcharchivering. De bestaande `FOR UPDATE`-prefixscan loopt ook bij weigering over maximaal 20.001 records en legt hierbij locks; locks en MySQL-capaciteit in productie zijn nog niet gekwantificeerd. Bij meer dan 20.000 historische bronrijen is archivering niet mogelijk totdat een afzonderlijk ontworpen en getest kort-transactiebatchmechanisme is geïmplementeerd. Geen automatische herhaling, migratie, merge of productiehandeling.

## Ontwerp voor echte kortdurende batcharchivering (nog niet geïmplementeerd)

**Incrementele, handmatig gestarte verwerking per volledige UTC-dag**, in chronologische volgorde. Iedere dag is een afzonderlijke transactie: begin, vergrendel alleen de indexrange van die dag, valideer bronrecords en recordaantal, aggregeer per dag en per kavel, verwijder exact dezelfde dagbronrecords, controleer deletecount, commit. Op fouten: rollback alleen van de lopende dag; eerdere dagen zijn reeds duurzaam verwerkt. Resultaat aan de beheerder moet expliciet het aantal afgeronde dagen, resterende dagen, verwerkte bronrecords en eventuele onderbrekingsreden tonen. Een tweede handmatige uitvoering hervat door de oudste nog aanwezige bronrecords te selecteren; geen cron, achtergrondjob of automatische retry.

**Veiligheid en concurrency:** een eenvoudige `LIMIT 20000` op de huidige volledige historische `FOR UPDATE`-scan beperkt de lockscope niet voldoende. Eerst de oudste nog niet gearchiveerde dag veilig bepalen, vervolgens met een locking range-scan per exacte dag de bestaande rijen en indexgaps beschermen; phantom inserts vóór/binnen het gekozen dagbereik en gelijktijdige archivering moeten expliciet worden getest. Eén dag boven de maximaal toegestane limiet mag niet half worden verwerkt: rollback, foutmelding met dag en bronrecordaantal, volgende dag niet starten. Voor exact-once gedrag moeten unieke aggregate keys en de volledige dagverwerking in dezelfde transactie zitten. Een tweede archiveringsverzoek moet wachten of zonder dataverlies falen, nooit gedeeltelijk overschrijven.

**Cruciale beperking:** dag-voor-dag batching garandeert geen korte transacties als één historische dag miljoenen records bevat. Bewijs een harde limiet per dag voordat de transactie destructieve queries uitvoert, en ontwerp pas daarna eventueel een complexer systeem met tijdvenster/batch-ID of een gecontroleerde writer-cutoff indien nodig. Houd uitgebreide MySQL-tests voor phantom inserts, deadlock, conflict, retry, half-afgeronde multi-day archivering, idempotentie, hoogvolume dag, en herstel van SQL-back-up. Laat dezelfde read-only statistiekweergave correct functioneren voor gemengde gearchiveerde en actuele dagen.

**Vrijgavebesluit:** dit is een ontwerpspecificatie, geen werkende batchimplementatie. De huidige code verwerkt nog steeds één volledige historische prefix in één transactie en is beperkt tot maximaal 20.000 bronrecords. Geen merge/productiecode of live data via deze documentatie-update.

## Geïsoleerd eendagsprototype

`private/visitor_stats_archive_one_day_experimental.php` bevat een afzonderlijke, **niet aangesloten** functie die per expliciete aanroep precies de oudste historische UTC-dag binnen één transactie probeert te archiveren. De bestaande admin-archivering blijft onveranderd. De test controleert twee achtereenvolgende handmatige aanroepen, één dag per commit, hervatten en idempotentie. Maximaal 20.000 bronrecords per dag; overschrijding moet vóór aggregatie en verwijdering stoppen. De initiële `MIN(visit_date)` is een niet-lockende ontdekking; een gelijktijdige oudere insert kan bij een volgende aanroep worden verwerkt. Er is nog geen bewezen oplossing voor alle parallelle archiveringsverzoeken en phantom-insertgevallen. Deze prototypefunctie **niet koppelen aan de admin, mergen of deployen** zonder een aanvullende locking/concurrency-review en groene CI op de exacte commit.

## Incidentafhandeling: statistieken definitief wissen

De organisatie aanvaardt dat bezoekersstatistieken bij een ernstig probleem **definitief verloren mogen gaan**. Er is daarom **geen afzonderlijke SQL-export, back-up- of herstelprocedure** vereist als releasevoorwaarde voor de statistiekmodule.

De bestaande, met adminlogin afgeschermde pagina `catalogus-public/admin/statistieken.php` bevat al een handmatige tweestapsactie **Alles verwijderen** (voorvertoning, tijdelijke bevestigingstoken en letterlijk `VERWIJDER`). Die wist in één database-transactie de drie statistiektabellen `visitor_stats_lot_totals`, `visitor_stats_day_totals` en `visitor_stats_daily`; bij een fout wordt teruggedraaid. Er is ook een aparte optie voor records vóór een UTC-datum. De verwijdering raakt geen kavels, accounts of foto's en herstelt verloren statistieken niet.

Na een volledige verwijdering kunnen nieuwe bezoekersregistraties opnieuw worden opgebouwd vanaf nul, zolang de trackingfunctionaliteit zelf werkt. Een eventuele storing in de code of het databaseschema wordt **niet** door gegevensverwijdering gerepareerd; daarvoor blijft afzonderlijke diagnose en een gecontroleerde code-/schemawijziging noodzakelijk. Bij uitval van de admininterface kan de bestaande adminverwijdering niet zonder meer worden gebruikt; elke alternatieve live databasehandeling vereist aparte expliciete autorisatie.

Nog open vóór productievrijgave: onafhankelijke databasecode-review, beoordeling van lockdruk en quota, en veilige afhandeling van historische dagen boven de limiet van 20.000 bronrecords. PR blijft Draft; geen merge of productiehandeling.
