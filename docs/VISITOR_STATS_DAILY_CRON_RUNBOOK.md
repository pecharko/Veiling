# Issue #224 - OVH nachtelijke bezoekersarchivering (voorstel, NIET actief)

## Scope en bewaartermijn
- Bewaar zeven volledige UTC-dagen met detailrecords in `visitor_stats_daily`.
- Archiveer uitsluitend eerdere UTC-dagen naar `visitor_stats_day_totals` en `visitor_stats_lot_totals`.
- Eén dag per database-transactie; max. 20.000 bronrijen per dag en 14 dagen per cron-uitvoering.
- Geen scheduler geactiveerd door de PR. Een OVH-geplande taak rond 03:30 Belgische tijd is pas na afzonderlijke autorisatie mogelijk. Controleer OVH-tijdzone en zomertijd.

## Bediening en monitoring vóór activatie
1. Verifieer read-only MySQL-schema, rechten, huidige historische detailrecords en DB-quota; controleer dat de totalentabellen dezelfde structuur hebben als `database/visitor_stats_aggregates.sql`.
2. Maak een expliciet geautoriseerde data-only back-up en test herstel in isolatie. De applicatiesnapshot kan statistieken uitsluiten.
3. Valideer met een geïsoleerde MySQL 8.4-test de UTC-grens, 20.001-recordweigering, twee parallelle uitvoeringen, rollback, late historische INSERT, volledige en gedeeltelijke catch-up.
4. Controleer de PHP CLI-versie en het exacte private scriptpad op OVH; stel zo nodig een veilige CLI-launcher buiten de publieke webroot in. Configureer cron alleen na aparte toestemming.
5. Cron moet STDOUT/STDERR en exitcode vastleggen op een toegangsbeschermde locatie met logrotatie. Waarschuw een beheerder bij `archive_failed`, ontbrekend dagelijks successignaal, of `archive_catchup_limit_reached`. GitHub CI is geen live monitoring.
6. Houd de foutafhandeling fail-closed: geen hervatting door aggregaatrijen te overschrijven, geen live handmatige SQL-reparaties zonder back-up en autorisatie.
7. Bevestig na activatie de actuele dagtotalen en databasegrootte, en controleer de eerste uitvoeringen dagelijks.

## Bekende risico's
- Uitzonderlijke historische imports kunnen een reeds gearchiveerde datum opnieuw vullen. Een PK-conflict blokkeert veilig verdere verwerking; een beheerder moet dit afzonderlijk onderzoeken.
- Volledige quota-vermindering is niet gegarandeerd zonder MySQL-space accounting; InnoDB kan vrije pagina's intern hergebruiken.
- De CLI-runner logt samengevatte aantallen, geen bezoekerscodes, maar heeft nog geen configureerbare centrale statusregistratie of automatische notificatie. Activeer niet voordat een beheerder aantoonbare exitcode-/logbewaking heeft ingericht.
- Geen automatische verwijdering van de geaggregeerde statistieken op 31 oktober 2027.

## Vrijgavegrenzen
De code-PR vereist menselijke review vanwege archivering en verwijdering van historische gegevens. Merge ≠ deployment ≠ cron-activering. Productiecode, DB-writes en OVH cron vereisen afzonderlijke expliciete autorisatie.
