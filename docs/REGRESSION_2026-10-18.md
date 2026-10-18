# Regressiecontrole — fase 1 (18 oktober 2026)

**Project:** 41 Lokeren online veilingcatalogus  
**Referenties:** `docs/REQUIREMENTS.md`, `docs/TESTPLAN.md`, `docs/DATABASE.md`.  
**Scope:** aanmelding/autorisatie, kavelbeheer en CSV-import.  
**Status 18 oktober 2026:** drie anonieme adminroutes visueel afgeschermd (T-001); adminoverzicht na aanmelden bereikbaar (T-001B). Een later Chrome-beveiligingspaneel bevestigt **'Connection is secure' en 'Certificate is valid' voor 41lokeren.be**. Vervolgens bewees de gebruiker met `curl -I http://41lokeren.be/admin/login.php` dat HTTP **`200 OK`** zonder redirect retourneert. **T-040 is voor HTTP-afdwinging mislukt**, ondanks geldig HTTPS-certificaat. Zie [HTTPS_REMEDIATION.md](HTTPS_REMEDIATION.md). Sessie-/logouttests blijven open. Geen muterende datatests uitgevoerd.

## Wat is daadwerkelijk gecontroleerd?

| Onderdeel | Broncodebewijs | Bevinding |
|---|---|---|
| Toegang admin | `private/bootstrap.php`: `require_auth()` | Admin-GET's vragen ingelogde sessie. Alleen statisch geverifieerd. |
| Formulierbeveiliging | `private/bootstrap.php`: `csrf_field()`, `check_csrf()` | Tokens worden gegenereerd en op POST gecontroleerd. Alleen statisch geverifieerd. |
| Login | `catalogus-public/admin/login.php` | `password_verify()`, sessie-ID-regeneratie bij succes, IP-hash-gebaseerde mislukte-pogingentelling en tijdelijke blokkering zijn in de code aanwezig. Alleen statisch geverifieerd. |
| Kavelnummer | `catalogus-public/admin/kavel.php` plus geverifieerde MySQL-DDL | Lotnummer 1..999999; foutmelding bij SQLSTATE 23000; UNIQUE-index op `lots.lot_number` bewezen via phpMyAdmin. |
| Kavelvelden | `catalogus-public/admin/kavel.php` | Titel verplicht; negatieve schattingen en omgekeerd bereik worden tegengehouden. Alleen statisch geverifieerd. |
| CSV-import | `catalogus-public/admin/import.php` | CSV max. 3 MiB en max. 2.000 regels; dubbels binnen één CSV worden geweigerd; `ON DUPLICATE KEY UPDATE` voor bestaande lotnummers; transactionele uitvoering van SQL-rijen. Alleen statisch geverifieerd. |
| Publicatiestatus bij CSV | `catalogus-public/admin/import.php` | Zonder `publiceren`-kolom blijft de bestaande status behouden; nieuwe lots worden draft. Alleen statisch geverifieerd. |

**De live website kon niet betrouwbaar worden opgehaald via externe GET-controle; daaruit volgt geen bewijs voor werking of uitval.** De browser-/adminchecks vragen een gebruiker met een geldige sessie. Er zijn geen bestaande kavels aangepast of uploads/imports uitgevoerd.

## Resultaat eerste handmatige browsertest (18 oktober 2026)

- **T-001 — Geslaagd voor de basiscontrole:** de gebruiker opende naar aanleiding van de instructies `https://41lokeren.be/admin/index.php` in een incognitovenster en stuurde een screenshot van het scherm **Beheerderstoegang / Welkom terug** met e-mailadres- en wachtwoordvelden. Afgeschermde kavelgegevens waren niet zichtbaar.
- **Tweede toegangscontrole:** de gebruiker opende vervolgens volgens de instructie `/admin/import.php` in hetzelfde incognitovenster en deelde opnieuw een screenshot van het loginformulier in plaats van het CSV-importscherm. Daarmee is de visuele toegangsafscherming voor zowel `/admin/index.php` als `/admin/import.php` bevestigd.
- **Derde toegangscontrole:** na het openen van `/admin/kavel.php` in hetzelfde incognitovenster toonde de gebruiker opnieuw het loginformulier, nu met de adresbalk `41lokeren.be/admin/login.php` zichtbaar. Het afgeschermde kaveleditorformulier was niet zichtbaar. De drie gevraagde routes hebben hiermee een geslaagde visuele basiscontrole; de exacte redirect is nu voor de derde controle in beeld.
- **Beperking bewijs:** het screenshot toont de eind-URL van de derde controle, niet gelijktijdig de vooraf gevraagde kaveleditor-URL. De navigatie is beoordeeld binnen de gevolgde testinstructie. Inloggen met correcte credentials, uitloggen en andere adminroutes zijn nog niet functioneel getest.
- **Datamutaties:** geen. Geen wachtwoorden of sessiegegevens verzameld.

## Aanmelding met geldig account en mogelijke HTTPS-fout (18 oktober 2026)

De gebruiker deelde na de instructie om met een bestaand beheerdersaccount in te loggen een screenshot van `41lokeren.be/admin/index.php` met een ingelogde beheerinterface. Zichtbaar zijn een kaveloverzicht, 10 kavels, alle 10 gepubliceerd, 10 kavels met een foto, een ingeschakelde publieke catalogus en het menu-item Back-up & herstel. **T-001B: toegang na aanmelding bevestigd.**

**Beveiligingswaarschuwing T-040:** Chrome toont op dezelfde screenshot links van het adres `Not Secure` (rood). Daardoor is **niet aangetoond dat de inlogverbinding versleuteld en correct gevalideerd is**. De precieze oorzaak van de melding en het daadwerkelijke protocol zijn niet af te leiden uit het screenshot alleen. Rechtstreekse externe TLS-/HTTP-controle vanuit de assistentomgeving is niet betrouwbaar gelukt. De mogelijke HTTPS-/certificaatfout is als **P0 GitHub [issue #6](https://github.com/pecharko/Veiling/issues/6)** vastgelegd.

**Volgorde:** vóór andere adminacties eerst het browserbeveiligingspaneel controleren en indien nodig de OVHcloud SSL-domeinkoppeling/certificaatconfiguratie repareren. Geen wachtwoorden, cookies of persoonsgegevens delen; geen certificaatwaarschuwingen omzeilen. Tests van kavelbewerking, CSV-import, fotoupload en logout blijven voorlopig open.

### Aanvullend bewijs HTTPS (18 oktober 2026)

Een derde browserscreenshot toont het Chrome-sitebeveiligingspaneel van **41lokeren.be** met de letterlijke meldingen **`Connection is secure`** en **`Certificate is valid`**. Daarmee is voor de op dat moment geopende beheerderspagina een veilige, door Chrome gevalideerde verbinding aangetoond. De oorspronkelijke rode waarschuwing is op recentere screenshots verdwenen. Deze browserbevestiging bewijst geen HTTP-redirect. **Aanvullend bewijs op 18-10-2026:** `curl -I http://41lokeren.be/admin/login.php` antwoordt met `HTTP/1.1 200 OK`, dus een automatische HTTP→HTTPS-redirect **ontbreekt aantoonbaar** voor die route. [Issue #6](https://github.com/pecharko/Veiling/issues/6) blijft daarom open voor de resterende HTTPS-hardeningcontrole. De administratie kan verder worden getest via uitdrukkelijke HTTPS-URL's, zonder destructieve acties.

### Definitieve HTTPS-doorverwijzing bevestigd (18 oktober 2026)

Na de tijdelijke succesvolle 302-test heeft de gebruiker de permanente 301 ingesteld. Twee herhaalde `curl -sSI`-controles tonen **`HTTP/1.1 301 Moved Permanently`**: voor `/admin/login.php` naar `https://41lokeren.be/admin/login.php`, en voor `/` naar `https://41lokeren.be/`. Samen met het eerdere Chrome-certificaatbewijs, de `HTTP/2 200` op de HTTPS-login en de redirectketentest zonder lus is de belangrijkste HTTP→HTTPS-beveiligingsfout voor de geteste routes hersteld. **T-040: redirect- en certificaatdeel geslaagd.** De andere adminroutes en de werking na logout staan nog open (T-041); het live `.htaccess`-bestand is nog niet als versiebeheerde broncode geverifieerd.

## Vastgesteld probleem in code (nog geen interactief testresultaat)

**Issue [#5](https://github.com/pecharko/Veiling/issues/5) — P2: verlies van formulierinvoer na bewaarfout.**

In `admin/kavel.php` worden na een POST-fout de formulierwaarden opgebouwd uit `$lot` (bestaande DB-rij) of defaults, **niet uit de ingestuurde POST-velden**. Bij een mislukte aanmaakpoging verschijnt daarom een leeg/default formulier; bij een mislukte bewerking de vorige opgeslagen waarden. Dat vergroot de kans op gegevensverlies voor de beheerder (T-038: statisch afwijking, runtime nog te reproduceren).

**Partiële opslag bij ongeldige fotoupload:** de SQL-insert/update gebeurt voordat `photo_files()` eventuele uploadfouten kan signaleren. Daardoor kan de kavel zijn bewaard terwijl de browser een fout toont. T-039 is op basis van code-inspectie een risico, maar nog niet via testdata gevalideerd. Test niet rechtstreeks op productie.

## Praktijktests in veilige volgorde

### A. Alleen-lezen op productie (uit te voeren door bevoegde gebruiker)

1. **T-001 — Toegang zonder login:** open een volledig nieuw **privé-/incognitovenster**, zonder bestaande beheerderscookies. Open `https://41lokeren.be/admin/index.php`. **Verwacht:** doorsturen naar `/admin/login.php` en geen kaveloverzicht tonen. Controleer ook `/admin/import.php` en `/admin/kavel.php` in datzelfde anonieme venster.
2. **T-001 vervolg — Geldige login:** open je normale browser, log met je eigen beheerdersaccount in op `/admin/login.php` (deel geen wachtwoord of sessiecookie). **Verwacht:** toegang tot kaveloverzicht. Een gewone succesvolle login is geen test van de blokkering na 8 fouten.
3. **Read-only UI-check:** open `/admin/index.php` en `/admin/kavel.php` en `/admin/import.php` via het menu. Controleer dat alle velden en functies zichtbaar zijn, maar **klik niet op opslaan/importeren/verwijderen**.
4. **CSV-downloadlink:** klik desgewenst alleen op `Download voorbeeld-CSV`, en controleer of een geldig CSV-bestand wordt gedownload. Dit verandert de database niet. De broncode verwijst naar `assets/voorbeeld-kavels.csv`, dat niet in de GitHub-baseline is aangetroffen; zie issue #4. Dit bewijst niet of het bestand op OVHcloud ontbreekt.
5. **Systeemcheck:** open `/admin/systeemcheck.php`. Noteer uitsluitend de statussen OK/AANDACHT/FOUT, **geen databaseconfiguratie, accountgegevens of wachtwoorden delen**.

Noteer per test datum, gebruikte browser en resultaat **geslaagd / mislukt / geblokkeerd**, met eventueel screenshot waarop geen persoonsgegevens of sessiegegevens staan.

### B. Alleen in een geïsoleerde testomgeving met testdatabase en testuploads

1. **T-002/T-004:** maak een testkavel, heropen en bewerk het; controleer correct opslaan.
2. **T-003:** probeer een bestaand lotnummer te gebruiken; verwacht duidelijke fout, geen tweede identiek lot, en behoud van ingevoerde waarden (T-038).
3. **T-005:** importeer CSV met dubbele nummers **binnen CSV**; verwacht weigering zonder gedeeltelijke imports. Test daarnaast CSV-upsert van al bestaand nummer en statusbehoud zonder statuskolom.
4. **T-039:** simuleer een uploadfout; controleer of de kavel al dan niet is bewaard, of de foutmelding dat correct beschrijft en of er geen verweesde foto's ontstaan.
5. **T-037:** test 8 mislukte logins, blokkering en vrijgave uitsluitend met een **testaccount** op een geïsoleerde testserver. Geen productie-IP blokkeren.
6. **T-019:** controleer ongeldige CSRF-POST's alleen in de testomgeving.

**Vooraf:** aparte schema-/datakopie zonder echte accounts/geheimen, afgeschermde test-URL of lokale testserver, nooit een restore naar productie. Fotobestanden/backup-testdata horen buiten Git te blijven.

## Vastleggen van resultaten

Werk `docs/TESTPLAN.md` bij met de echte resultaten. Markeer een testcase pas als **Geslaagd** nadat die effectief is uitgevoerd, niet op basis van alleen de aanwezigheid van PHP-code. Werk `docs/REQUIREMENTS.md` en `CHANGELOG.md` gelijktijdig bij bij iedere functionele wijziging.

## Automatische codecontrole

`.github/workflows/php-lint.yml` controleert bij push en pull request de **PHP 8.2-syntax** van versioneerde PHP-bestanden. Controleer onder de GitHub-tab **Actions** of deze workflow voor `main` werd gestart en geslaagd is. Een geslaagde syntaxcontrole is **geen** functionele regressietest of OVHcloud-compatibiliteitsbewijs.
