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
