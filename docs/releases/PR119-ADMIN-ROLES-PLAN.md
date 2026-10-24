# PR #119 — rollenbeheer, productie-releaseplan (VOORBEREIDING)

Status: uitsluitend voorbereid; **niet** geautoriseerd voor databasemigratie, SFTP-upload of andere productiewijzigingen.

## Vastgelegde keuze

- Releaseprofiel: eenvoudige **code**-uitrol zonder codeback-up, rollbackartifact of automatische rollback, expliciet gekozen op 2026-10-24.
- Consequentie: Bij een fout is er geen automatische of voorbereide rollback; herstel vergt een nieuwe handmatige correctie of herdeploy en kan downtime veroorzaken.
- Database-migratie is **niet** inbegrepen in deze toestemming: aparte impactreview, gecontroleerde data-back-up en aparte expliciete autorisatie noodzakelijk.
- Bron: mergecommit `bb8dd6dedc6061ae404441bbb93caeb628bac75c`; deploy alléén na vergelijking met de actuele `main`-head en expliciete finale autorisatie.

## Exacte runtime-allowlist (4 bestanden)

1. `catalogus-public/admin/gebruikers.php` (nieuw)
2. `catalogus-public/admin/login.php`
3. `private/snapshots.php`
4. `private/bootstrap.php` (**laatst activeren**)

Geen testbestanden, SQL, uploads, snapshots, `.htaccess`, secrets, `private/config.php` of overige paden uploaden.

## Blokkerende afhankelijkheid: database

`admins.role` is verplicht voor autorisatie en gebruikersbeheer. Het voorbereidende schema staat in `database/admin_roles_migration.sql`:

```sql
ALTER TABLE admins ADD COLUMN role ENUM('administrator','assistant') NOT NULL DEFAULT 'administrator';
```

- Dit script **niet** uitvoeren zonder afzonderlijke toestemming; bevestig eerst daadwerkelijke kolommen, rechten, huidige Administrator-accounts, en veilige, herstelbare databaseback-up.
- Bestaande accounts krijgen met de migratie rol Administrator, onafhankelijk van UI-deployment.
- Als SQL-migratie uitgevoerd is maar upload niet kan starten, herstelstrategie en onderhoudsvenster vooraf afspreken.
- Extra aandacht: snapshots vóór deze wijziging bevatten geen `role` en herstel mag bestaande accounts niet onbedoeld degraderen of de laatste Administrator doen verdwijnen. Test zowel nieuwe als oudere snapshotformaten, restore en sessie-intrekking.
- De geïsoleerde tests zijn geslaagd, maar vormen geen bewijs van productiecompatibiliteit.

## Offline controles vóór productiepreflight

- Pin de exacte bron-SHA (geen bewegende `main`) en bereken per runtimebestand SHA-256, controleer grootte en dat het gewone bestanden zijn.
- PHP lint op alle vier bestanden en regressietests op diezelfde exacte commit; huidige verplichte CI: PHP syntax, kaveleditor, snapshot en herstel.
- Controleer handmatig de authentificatie- en autorisatiewijzigingen en de garantie van minimaal één Administrator.
- Valideer nieuwe/gewijzigde GitHub workflow-YAML met YAML parser en bij voorkeur actionlint, vóór PR.
- Bepaal of een bestaande `workflow_dispatch` exact de vier paden ondersteunt. Zo niet: maak apart beoordeelde releaseworkflow met modes `plan`, `preflight`, `deploy`; nooit `push`-deploy.

## Read-only OVH preflight (nog NIET uitgevoerd)

1. Verifieer bekende SSH-hostkey, SFTP-account, expliciete remote root en verwachte directorystructuur.
2. Controleer remote bestandstypen; bestaande productiehashes van `login.php`, `snapshots.php`, `bootstrap.php` en afwezigheid van `gebruikers.php` vaststellen. Bij afwijking: STOP en review.
3. Verifieer productie-PHP-versie, database-schema en back-up/herstelplan, zonder gegevens te wijzigen.
4. Controleer publieke HTTPS-homepagina, ongeauthenticeerde adminredirect, uitgeschakelde importen en geen herstelmarker.
5. Documenteer het afzonderlijke technische goedkeuringsmoment voor SQL en de mogelijke tijdelijke inconsistentie tussen migratie en codeactivering.

## Geplande uitvoering — alleen na afzonderlijke autorisaties

1. Formele securityreview en go/no-go; controleer CI op ongewijzigde SHA en read-only preflight.
2. Afzonderlijk goedgekeurde database-back-up en migratie, bij voorkeur in gepland onderhoudsvenster.
3. Nieuwe pagina `gebruikers.php` uitsluitend toevoegen indien afwezig; overige 3 bestanden alleen na driftcontrole uploaden; `bootstrap.php` laatst. Verifieer remote SHA-256 van elk bestand.
4. Stop bij afwijking: geen automatische rollback. Rapporteer gedeeltelijk gewijzigde bestanden en herstelplan.
5. Read-only HTTP-controles; daarna menselijke acceptatie: Administrator alle menu's, Assistent alleen Kavels/Nieuw kavel, URL-restricties, nieuwe gebruiker, wissel rollen, behoud laatste Administrator.
6. Werk releaseverslag en tests/documentatie bij met exacte workflow-run, checksums en menselijke acceptatie.

**Deze documentwijziging creëert geen deployworkflow en voert geen productieactie uit.**
