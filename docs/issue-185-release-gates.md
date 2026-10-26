# Issue #185 — veilige releasevolgorde (voorbereiding, geen autorisatie)

Scope: PR #186, lotrechten, creator ID, auction_only, market_price en starting_bid.
Er zijn volgens de gebruiker geen oudere back-up ZIP's die ondersteund moeten worden; behoud de regressietest als defensieve controle.

## Productie-gates

1. Vereis afzonderlijke expliciete autorisatie vóór iedere databasewijziging, merge of productie-uitrol.
2. Controleer actuele hoofdbranch, exacte PR-head-SHA, volledige diff, drie verplichte groene CI-jobs, testresultaten en een releasebestandmanifest. Geen stilzwijgende auto-merge.
3. Leg schema-baseline vast via read-only `SHOW CREATE TABLE lots`, `SHOW COLUMNS FROM lots` en indexes. Controleer rolwaarden van admins en aantallen lots, inclusief gepubliceerd/markt/veiling, uitsluitend read-only.
4. **Specifieke keuze voor issue #185:** gebruiker heeft uitdrukkelijk gekozen voor **geen nieuwe databaseback-up** en **geen inspectie van bestaande herstelpunten**. Respecteer die keuze: voer beide acties niet uit. Documenteer vóór de afzonderlijke migratieautorisatie het risico op onomkeerbaar gegevensverlies. Dit geldt niet als toestemming om de migratie uit te voeren.
5. Voer uitsluitend na aparte, exacte productieautorisatie en expliciete erkenning van het risico zonder back-up de versiebeheerde migratie `database/lot_requirements_185_migration.sql` uit. Let op: MySQL ALTER TABLE is niet met een normale transactie terug te draaien; een code-rollback is **geen schema-rollback**. Zonder een geverifieerde databaseback-up kan herstel onmogelijk zijn; een code-rollback verandert het databaseschema niet.
6. Controleer na migratie read-only dat `created_by_admin_id`, `auction_only`, `market_price` en `starting_bid` precies één keer bestaan en de defaults correct zijn. Historische NULL makers blijven NULL. Schattingen `estimate_min/max` blijven bestaan en worden niet omgezet naar marktprijs/startbod.
7. Start pas daarna de expliciet goedgekeurde code-uitrol met vastgepinde commit/SHA-bestanden, driftcontrole, codeback-up, rollback van code en HTTPS-smoketests. Maak geen automatische database-rollback.
8. Test met geschikte testaccounts server-side: assistant kan alleen eigen draft wijzigen, nooit publiceren of gepubliceerde lots bewerken/verwijderen; veilingmeester en administrator kunnen publiceren; auction_only + markt blokkeert publiceren; snapshot/herstel bewaart de nieuwe velden. Geen destructieve productieproeven.
9. Log commit, migratiestatus, verificatie, waarschuwingen en uiteindelijke goedkeuring in PR/issue. Bij afwijking stoppen en expliciete toestemming vragen.

## Belangrijkste migratierisico

De nieuwe PHP-editor en snapshots gebruiken de extra lots-kolommen; eerst code deployen kan foutmeldingen veroorzaken zolang het productieschema niet gemigreerd is. De oude source-versie moet blijven functioneren op het uitbreide schema (additieve kolommen). Het terugrollen van code herstelt niet de oude databasestructuur. Maak dus een apart migratie- en herstelplan.

## Releasebeslissingen en scheiding

- **Geen automatische productiehandeling:** het generieke offline-register bereidt checksums en het migratiecontract voor, maar voert geen ALTER TABLE, snapshot, remote writes of code-upload uit.
- **Migratie-gate:** pin exacte `main`-SHA, valideer live schema uitsluitend read-only, beoordeel of vier nieuwe kolommen ontbreken of al bestaan, en verkrijg separate autorisatie voordat een SQL-write wordt gestart. Geen stilzwijgend herhalen van een gedeeltelijk gelukte migratie.
- **Code-deploy-gate:** daarna afzonderlijke finale toestemming voor zeven PHP-bestanden met SHA-256-baselines, driftcontrole, servercode-back-up, best-effort rollback en HTTPS-smoketests. Geen deploy vóór bewezen succesvolle schema-uitbreiding.
- **Geen garantie op databaseherstel:** omdat de gebruiker beide backupopties overslaat, blijft een SQL-risico expliciet open; een SFTP-codeback-up kan nooit een databaseherstelpunt vervangen.
