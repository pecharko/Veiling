# Issue #185 — veilige releasevolgorde (voorbereiding, geen autorisatie)

Scope: PR #186, lotrechten, creator ID, auction_only, market_price en starting_bid.
Er zijn volgens de gebruiker geen oudere back-up ZIP's die ondersteund moeten worden; behoud de regressietest als defensieve controle.

## Productie-gates

1. Vereis afzonderlijke expliciete autorisatie vóór iedere databasewijziging, merge of productie-uitrol.
2. Controleer actuele hoofdbranch, exacte PR-head-SHA, volledige diff, drie verplichte groene CI-jobs, testresultaten en een releasebestandmanifest. Geen stilzwijgende auto-merge.
3. Leg schema-baseline vast via read-only `SHOW CREATE TABLE lots`, `SHOW COLUMNS FROM lots` en indexes. Controleer rolwaarden van admins en aantallen lots, inclusief gepubliceerd/markt/veiling, uitsluitend read-only.
4. Maak **vóór** wijziging een verse data-only ZIP-back-up met werkende hash-verificatie. Download/bewaar een kopie onafhankelijk van de website en voer waar mogelijk restore-repetitie uit op geïsoleerde MySQL. Geen broncode in het data-snapshot.
5. Voer uitsluitend na aparte goedkeuring de versiebeheerde migratie `database/lot_requirements_185_migration.sql` uit. Let op: MySQL ALTER TABLE is niet met een normale transactie terug te draaien; een code-rollback is **geen schema-rollback**. Herstel van een database vereist een aparte, uitdrukkelijk goedgekeurde procedure.
6. Controleer na migratie read-only dat `created_by_admin_id`, `auction_only`, `market_price` en `starting_bid` precies één keer bestaan en de defaults correct zijn. Historische NULL makers blijven NULL. Schattingen `estimate_min/max` blijven bestaan en worden niet omgezet naar marktprijs/startbod.
7. Start pas daarna de expliciet goedgekeurde code-uitrol met vastgepinde commit/SHA-bestanden, driftcontrole, codeback-up, rollback van code en HTTPS-smoketests. Maak geen automatische database-rollback.
8. Test met geschikte testaccounts server-side: assistant kan alleen eigen draft wijzigen, nooit publiceren of gepubliceerde lots bewerken/verwijderen; veilingmeester en administrator kunnen publiceren; auction_only + markt blokkeert publiceren; snapshot/herstel bewaart de nieuwe velden. Geen destructieve productieproeven.
9. Log commit, migratiestatus, verificatie, waarschuwingen en uiteindelijke goedkeuring in PR/issue. Bij afwijking stoppen en expliciete toestemming vragen.

## Belangrijkste migratierisico

De nieuwe PHP-editor en snapshots gebruiken de extra lots-kolommen; eerst code deployen kan foutmeldingen veroorzaken zolang het productieschema niet gemigreerd is. De oude source-versie moet blijven functioneren op het uitbreide schema (additieve kolommen). Het terugrollen van code herstelt niet de oude databasestructuur. Maak dus een apart migratie- en herstelplan.
