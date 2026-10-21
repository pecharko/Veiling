# Gezamenlijke release bezoekersstatistieken + privacyvermelding (VOORBEREID)

**Releaseprofiel:** eenvoudig / zonder codeback-up, rollbackartifact of herstelactie (uitdrukkelijk gekozen op 21-10-2026). **Dit document is geen uitrolautorisatie.** Geen productie-upload of DB-wijziging door het voorbereiden of mergen van de PR.

**Risico:** Bij een fout is er geen automatische of voorbereide rollback; herstel vergt een nieuwe handmatige correctie of herdeploy en kan downtime veroorzaken.

## Vastgezette allowlist (uploadvolgorde)

1. `catalogus-public/privacy.php` — nieuw; vermeldingen vóór activatie publiek beschikbaar
2. `private/visitor_stats.php` — nieuw; geen automatische gegevensverwijdering
3. `catalogus-public/admin/statistieken.php` — nieuw; alleen admin heeft toegang tot manueel wissen, bevestiging in twee stappen
4. `private/bootstrap.php` — bestaande adminnavigatie
5. `catalogus-public/index.php` — bestaande homepage, tracker en footerlink
6. `catalogus-public/kavel.php` — bestaande detailpagina, tracker en footerlink

De laatste twee gewijzigde publieke entrypoints worden als laatste geüpload. Alleen de zes vermelde PHP-bestanden mogen via deze workflow naar productie. **Geen** config/secrets, SQL/schema, uploads, foto's, snapshots, verwijderacties of ongecontroleerde synchronisatie.

## Technische releasegates

- Exacte actuele main-SHA en GitHub Actions CI op de exacte SHA; zes PHP-lints, fake-SFTP-regressies en privacylinktest.
- Handmatige `workflow_dispatch` van `.github/workflows/visitor-stats-six-simple-release.yml`, eerst `mode=plan`, zonder netwerktoegang in de plan-job en met een zeven dagen bewaard manifestartifact.
- De deploy-job accepteert uitsluitend een aantoonbaar succesvolle `plan`-run op **dezelfde commit en dezelfde workflow**, en controleert afzonderlijk SQL-schema, private analytics-secret, privacyreview en expliciete eenvoudige-releaseautorisatie.
- Pinned SSH-hostkey, root-/herstelmarkercontroles, streng vastgelegde allowlist, read-only drifthercontrole van drie bestaande PHP-bestanden tegen T-062-hashes en verificatie dat drie nieuwe bestanden nog ontbreken.
- Geen upload zonder afzonderlijke releasegoedkeuring **en daarna** finale deploy-autorisatie. Alle nieuwe bestanden uitsluitend via exclusieve SFTP-create, post-upload SHA-256 en directe STOP bij enige fout; **geen retry** bij gedeeltelijke upload.
- Na een eventueel goedgekeurde uitrol: read-only HTTPS smoke-tests, admin-auth, CSV-import weigering GET/lege POST, visuele privacyvermelding en overzicht/adminbeheer; geen destructieve productietest.

## Nog open, vóór releasegoedkeuring

- Juridisch juiste rechtsgrond en transparantie, verwerkingsverantwoordelijke en contactadres, maximale bewaartermijn en organisatorische frequentie van handmatige verwijdering expliciet vastleggen. Korte privacyvermelding is geen bewijs van volledige AVG-conformiteit.
- Productiebaseline/deployherkomst handmatig beoordelen (T-062/T-063 tonen SHA-matches, maar bevestigen expliciet nog géén formele goedkeuring). Herhaal read-only inspectie net vóór upload.
- Live database/schema en secret zijn eerder afzonderlijk ingericht; release mag ze niet wijzigen. Controleer geldigheid voor activatie.
- Verifieer of OVH de huidige privacy.php nog niet heeft; stop als enig voorgesteld nieuw bestand al bestaat.

**Status:** uitsluitend releaseworkflow en bijbehorende offline tests voorbereid op reviewbranch. Geen productie-uitrol.
