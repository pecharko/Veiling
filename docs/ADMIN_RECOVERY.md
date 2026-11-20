# Beveiligd herstel bij ontbrekende administrators of MySQL-tabellen

**Issue:** [#4](https://github.com/pecharko/Veiling/issues/4) · **Status:** operatorhandleiding, geen autonome herstelactie · **Datum:** 20 november 2026

Deze handleiding is alleen voor de bevoegde websitebeheerder. Zij is **geen SQL-installatiebestand**, migratie, toestemming tot een databasewijziging of publiek toegankelijk setupformulier. Gebruik geen `/admin/setup.php`: die route bestaat bewust niet. `sql/install.sql` bestaat evenmin en mag niet uit oude voorbeelden worden samengesteld.

## Wat betekent de fout?

- Wanneer `admins` **geen enkel account** bevat, geeft `/admin/login.php` vóór verwerking van GET/POST-inloggegevens **HTTP 503**, `Cache-Control: no-store` en een neutrale melding. Er verschijnt geen installatieformulier. Bij bestaande accounts blijft de normale aanmeldpagina beschikbaar.
- Wanneer de verbinding of de vereiste tabellen ontbreken, meldt de reeds geauthenticeerde `/admin/systeemcheck.php` dat de database niet betrouwbaar beschikbaar is. Een fout is **geen** uitnodiging om willekeurige SQL te importeren.
- Wanneer er nog accounts bestaan maar geen actieve Administrator met geldige credentials, is dit **een ander account-/rechtenincident**. Maak geen publieke noodlogin, stel geen bestaande beveiliging buiten werking.

## Alleen-lezen triage door een geautoriseerde operator

1. Bevestig eerst de werkelijk bedoelde OVH-hostingomgeving, database en betrokken websiteversie. Controleer de log- en fouttijd, zonder wachtwoorden, configinhoud, e-mailadressen of ruwe sessiecookies naar GitHub te kopiëren.
2. Inspecteer in een **beveiligde, geauthenticeerde beheerverbinding** uitsluitend de actuele tabelstructuren en anonieme aantallen, bijvoorbeeld `SHOW TABLES LIKE 'admins';`, `SHOW COLUMNS FROM admins;`, `SELECT COUNT(*) AS total_accounts FROM admins;` en — alleen als het schema die kolommen ondersteunt — `SELECT COUNT(*) AS active_administrators FROM admins WHERE role='administrator' AND is_active=1;`. Leg alleen de aantallen en de schemastatus vast.
3. Controleer of de andere vereiste tabellen en de volgens deze installatie toegepaste migraties aanwezig zijn; zie [DATABASE.md](DATABASE.md) en `database/migrations/`. De historische DDL in de documentatie is **geen** gegarandeerd actueel productie-`install.sql`. Verander niets bij onduidelijke schema- of migratiestatus.
4. Controleer beschikbaarheid, datum, scope en integriteit van een **apart veiliggestelde databaseback-up**. De huidige catalogussnapshot v8 bewaart **geen adminaccounts of bezoekersstatistieken** en kan niet worden gebruikt om verdwenen administrators terug te halen. Een catalogusrestore is hiervoor geen gepaste oplossing.

## Herstel alleen na aparte toestemming

1. Leg eerst een exact herstelplan voor: oorzaak, bevestigde toestand, toegestane gegevens, doelomgeving, schema, rekening houden met huidige rollen, regressietests en stopcriteria. Vraag afzonderlijke, concrete eigenaarautorisatie voor iedere databasewrite.
2. Voer een proef uit op een **geïsoleerde testdatabase** met fictieve accounts of een beschermde, geschikte offline back-up. Controleer geldige actuele kolommen, unieke e-mail, `password_hash`, de `administrator`-rol, `is_active`, migratiecompatibiliteit en het behoud van bestaande accounts/gegevens. Geen wachtwoorden of hashes in issuecommentaren of CI-logs.
3. Alleen een bevoegde operator mag na de noodzakelijke vrijgave een **minimale, exact gescopeerde** herstelbewerking uitvoeren via de bestaande private DB-beheerroute. Bewaar audit- en rollbackinformatie privé. **Geen** webinstaller, GitHub Actions writer, algemene reset, noodwachtwoord in broncode of automatische herhaalpoging.
4. Controleer na herstel zonder testaccounts in productie: HTTPS-login werkt weer, anonieme adminroutes blijven beschermd, POST/CSRF en rollen blijven intact, de bestaande kavel- en verkopergegevens zijn bewaard. Sluit de incidentadministratie pas met geverifieerde back-up- en herstelbewijzen.

## Gerichte regressie en bestaande beslissingen

- `tests/admin_zero_users_fail_closed_contract_test.php` controleert de 503/no-store-guard en verbiedt een publieke setup-route; `tests/admin_zero_users_http_test.py` test het HTTP-gedrag met een DB-stub.
- `tests/admin_zero_users_mysql_http_test.php` test dezelfde nul-admin-grens met **echte PHP-HTTP en uitsluitend wegwerp-MySQL in CI**; de oorspronkelijke fictieve administrator wordt veilig teruggezet.
- De placeholder voor fotoloze kavels is eerder op productie geverifieerd (T-028). De historische CSV-import/voorbeeld-download blijven bewust uitgeschakeld (T-042), ook na dit herstel.
- Een eenmalige wijziging van `admin/systeemcheck.php` wordt pas na exact geautoriseerde merge én afzonderlijk geautoriseerde release uitgerold; de bestaande veilige login van 19 november wordt niet opnieuw aangepast.

**Stop:** ontbreken van een onafhankelijke, geschikte back-up, schema-onzekerheid, afwijkende accountaantallen, credentialrisico of ontbrekende eigenaarautorisatie betekent **geen databasewrite**.
