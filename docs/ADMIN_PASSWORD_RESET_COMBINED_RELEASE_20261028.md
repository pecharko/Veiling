# Gecombineerde productiewijziging: wachtwoordreset en tijdelijke testpagina opruimen

## Uitvoerbare voorbereidende stap
De read-only releaseaanvraag in deze PR vraagt baselines voor precies vijf runtimebestanden:
- catalogus-public/admin/login.php
- catalogus-public/admin/wachtwoord-vergeten.php
- catalogus-public/admin/wachtwoord-reset.php
- private/admin_password_reset.php
- private/snapshots.php

## Afzonderlijke voorwaarden vóór productie
1. Read-only controle van actuele productie-DB: admins.id-type, admins.is_active, en afwezigheid/structuur van admin_password_resets. Back-up en hersteltest, daarna expliciete autorisatie voor database/admin_password_reset_migration.sql; DB eerst migreren.
2. Het verwijderen van catalogus-public/admin/mailtest-eenmalig.php is niet ondersteund als delete-operatie in scripts/shared_ovh_release.py (deze eist een bestaand bronbestand en een SHA). Niet via uploadmanifest proberen en de oude endpoint niet ongemerkt laten staan.
3. Ontwerp een afzonderlijk goedgekeurde fail-closed delete-stap met actuele remote SHA, backup/rollback en post-delete 404-controle. Gecombineerde acceptatie vergt zowel vijf uploads als deze ene verwijdering. Laat de live testpagina niet staan na activatie.
4. Bij elke afzonderlijke productieactie exact immutable main-SHA en actuele remote baselines vastleggen; gebruikersautorisatie vereist. Geen nieuwe CI/security gates omzeilen.

Deze PR bevat **geen uitvoerende delete-code**, geen SQL-uitvoering, geen mail, geen productie-upload en geen releaseautorisatie.
