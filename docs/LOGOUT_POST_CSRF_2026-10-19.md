# Beveiliging beheerder-uitloggen — voorstel 19 oktober 2026

**Doel:** voorkom dat een externe GET-link of browser-preview de beheerderssessie onbedoeld afsluit.

## Wijzigingen in voorgestelde PR

- `admin/logout.php` vereist bestaande beheerdersauthenticatie, HTTP POST en een geldig CSRF-token, vóór sessiedestructie; andere ingelogde methoden geven HTTP 405 met `Allow: POST`.
- De admin-navigatie gebruikt een normaal formulier met verborgen CSRF-token en knop. CSS houdt de huidige linkweergave, keyboard-focus en mobiele navigatie in stand.
- Bij geldige POST worden sessiegegevens verwijderd, sessiecookie met de ingestelde eigenschappen (inclusief SameSite) verlopen en de server-side sessie vernietigd; daarna HTTP 303 naar login.

## Geïsoleerde HTTP-acceptatie

De bestaande disposable MySQL/localhost integratietests zijn uitgebreid met:
1. Geen uitloggen via geauthenticeerde GET, wel 405; sessie blijft bruikbaar.
2. POST met ongeldige CSRF geeft 403; sessie blijft bruikbaar.
3. POST met geldige CSRF geeft 303 naar login; herbezoek beschermde admin geeft 303.
4. Voor niet-ingelogde bezoekers blijft de logoutroute door de login afgeschermd.
5. De navigatie heeft een POST-formulier in plaats van een GET-link.

**Scope:** geen wijziging aan loginverificatie, accounts, wachtwoorden of de inhoud van kavels. Een eventuele inactiviteitslimiet is een **aparte productbeslissing** onder [issue #23](https://github.com/pecharko/Veiling/issues/23).

**Vrijgave:** dit is beveiligingsgevoelige PHP-code en vereist menselijke review, alle drie groene CI-checks, handmatige merge, een passende gerichte deploymentworkflow (inclusief de aangepaste CSS en de nav-bootstrap), een geslaagd offline plan, rollback en afzonderlijke expliciete toestemming voor productie. Merge alleen is geen uitrol.
