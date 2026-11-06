# Beheer — Opsplitsing Veiling / Marktplaats

Onder **Admin → Veilinginfo → Publicatie & controle** staat de onafhankelijke schakelaar **Opsplitsing Veiling / Marktplaats activeren**. De keuze wordt opgeslagen in `settings.catalog_split_active`, standaard uit. Het blijft een aparte instelling van **Kavels tonen op website** (`is_published`).

- **Uit:** alle kavels in één overzicht en zonder prijzen.
- **Aan:** tabbladen Veiling (op veilingsnummer, alleen startbod) en Marktplaats (alleen marktprijs). Deze keuze werkt ook voor gewone websitebezoekers zolang de kavels gepubliceerd zijn.
- **Bekijk website:** de twee knoppen in de gele adminbalk zijn uitsluitend tijdelijke sessieoverrides; bij nieuw openen of sluiten wordt de override verwijderd. De opgeslagen instelling blijft intact.

De Docker-preview werkt uitsluitend met fictieve kavels. De previewseed voert, na controle van `LOCAL_PREVIEW_ISOLATED`, test-configuratiesleutel, databasehost en naam, een idempotente schema-aanpassing op de synthetische database uit. Start de containers opnieuw met `sh scripts/preview start` om een bestaande lokale previewdatabase bij te werken. `sh scripts/preview stop` wist de fictieve database en is niet nodig voor de migratie.

**Productie:** de afzonderlijke SQL-migratie `database/migrations/20261106_catalog_split_activation.sql` is uitsluitend voorbereid, niet uitgevoerd of geautoriseerd. Publicatie vereist eerst afzonderlijke database-, merge- en productieautorisatie met volledige gecontroleerde releaseprocedure.
