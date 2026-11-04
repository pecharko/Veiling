# Lokale UI-preview (geen OVH-deployment)

Doel: snelle UI-iteraties op een afzonderlijke developmentbranch in een wegwerpomgeving met **uitsluitend fictieve data**.

## Voorwaarden
- Mac met Docker Desktop, Git, Node.js/npm.
- Vrije poort 8080 op localhost; geen andere lokale applicatie op deze poort.
- Werk in een **afzonderlijke Git-worktree** zonder `private/config.php` of productiegegevens. Gebruik nooit `main`.
- De MySQL 8.4-container heeft geen gepubliceerde DB-poort. De website is alleen beschikbaar op `127.0.0.1:8080`; de Docker-netwerkverbinding is intern. Gebruik geen productiesnapshots of production secrets.

## Starten
Vanuit de ontwikkel-worktree:
```sh
sh scripts/preview start
```
Website: http://localhost:8080
Beheer: http://localhost:8080/admin/login.php

**Fictieve login:** `ci-admin@example.invalid` / `ci-password-not-a-real-secret`.
De setup gebruikt de bestaande CI-databaseschemafixture, maakt zes verzonnen kavels, en weigert bestaande niet-previewconfiguratie. PHP- en CSS-wijzigingen verschijnen na browserrefresh, zonder PR of deployment.

## Visueel testen en stoppen
```sh
sh scripts/preview test
sh scripts/preview status
sh scripts/preview stop
```
`test` installeert Playwright Chromium indien nodig, test desktop/mobiel voor publieke catalogus en admin en schrijft PNG's naar `dev/preview/.screenshots/` (niet in Git).
`stop` verwijdert **alleen de synthetische Docker-previewdatabase** en de uitsluitend voor deze preview gemaakte `private/config.php`. Herstarten maakt nieuwe fictieve gegevens.

## Codex-werkflow
Laat Codex meerdere layoutaanpassingen maken **op dezelfde developmentbranch**. Bekijk na elke wijziging de localhost-preview. Aan het einde: Playwright-test, één PR, CI, één afzonderlijke exact-head mergeautorisatie. Pas later, indien nodig, één onafhankelijk geautoriseerde productie-uitrol.

## Veiligheidsgrenzen
Geen OVH/SFTP/cron/production secrets, geen echte bezoekers/kavels/foto's, geen verbinding met de productie-DB, geen automatische workflowdispatch. Start weigert een bestaande config die niet expliciet als previewconfig is gemarkeerd en weigert `main`. De previewconfig en screenshots zijn uitgesloten van versiebeheer. Als Docker niet beschikbaar is, faalt `start` zonder een fallback naar productie.
