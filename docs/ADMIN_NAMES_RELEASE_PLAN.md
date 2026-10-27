# Accountnamen in Gebruikersbeheer — uitrolvolgorde

1. **Apart autoriseren:** voer de read-only controle `SHOW COLUMNS FROM admins LIKE 'name'` uit. Is de kolom nog afwezig, voer dan pas na een aparte productieautorisatie en een gepaste databaseback-up `database/admin_name_migration.sql` uit; geen automatisch schemawerk vanuit PHP.
2. Controleer na de migratie dat `name VARCHAR(120) NOT NULL DEFAULT ''` bestaat. Bestaande accounts krijgen geen verzonnen naam en kunnen zoals voorheen inloggen.
3. **Afzonderlijke code-release:** upload `catalogus-public/admin/gebruikers.php`, `catalogus-public/admin/kavel.php`, `private/admin_names.php`, `private/snapshots.php` en `private/restore_diagnosis.php` pas nadat de migratie bevestigd is. Gebruik de bestaande OVH-writegates met source/remote SHA, back-up, driftcontrole en rollback. De migratie wordt niet teruggedraaid door een gewone code-rollback.
4. Functionele acceptatie: vul een naam in voor een bestaand account, maak een nieuw account met naam aan, controleer naam en e-mail afzonderlijk in het overzicht, controleer 'Aangemaakt door' op een bestaand kavel en voer op een geïsoleerde omgeving een snapshot/restore inclusief naam uit. Bij een historisch account zonder naam toont het kavel voorlopig 'Naam nog niet ingevuld'; het e-mailadres wordt niet als naam gebruikt.
5. Vorige snapshots zonder de naamkolom blijven herstelbaar: het naamveld wordt dan leeg ingevuld. Namen in nieuwe snapshots worden meegenomen en gecontroleerd. Geplande (semi)automatische databaseherstellen blijven een afzonderlijke bevoegdheid.

Geen productie-SQL, code-upload, restore of rechtenwijziging door het mergen van een PR.
