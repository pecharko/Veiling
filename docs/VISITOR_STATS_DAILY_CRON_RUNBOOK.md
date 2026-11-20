# Issue #224 - OVH nachtelijke bezoekersarchivering (controle en vrijgave)

## Scope en bewaartermijn
- Bewaar drie volledige afgesloten UTC-dagen met detailrecords in `visitor_stats_daily`, plus de lopende UTC-dag.
- De cron bepaalt `$cutoff = gmdate('Y-m-d', strtotime('-3 days'))` en archiveert uitsluitend dagen met `visit_date < cutoff` naar `visitor_stats_day_totals` en `visitor_stats_lot_totals`; pas na een geslaagde transactie worden de bijbehorende detailrecords verwijderd. Voorbeeld: op 20 november (UTC) blijven 17, 18, 19 en de lopende 20 november detaildagen; 16 november en ouder komen voor archivering in aanmerking.
- Eén dag per database-transactie; max. 20.000 bronrijen per dag en 14 dagen per cron-uitvoering.
- De code, dit runbook en een PR activeren geen OVH-taak. De daadwerkelijke status van de bestaande OVH-scheduler is nog niet onafhankelijk vastgesteld. Een dagelijkse uitvoering rond 03:30 Belgische tijd was voorgesteld; controleer de bestaande planning en de OVH-tijdzone/zomertijd zonder instellingen te wijzigen. De bewaartermijn van drie dagen betekent niet dat de cron om de drie dagen draait.

## Read-only vaststelling van de actuele OVH-cron

1. Controleer in **OVHcloud Manager → Web Cloud → Hosting → Geplande taken (Cron)** of er een actieve dagelijkse taak bestaat. Noteer uitsluitend de instellingstekst: scriptpad `private/visitor_stats_daily_cron.php`, ingestelde PHP-versie, frequentie, tijdzone en eventuele foutstatus. Deze stap activeert of wijzigt niets.
2. Open als beheerder op `admin/statistieken.php` de sectie **Automatische archivering — cronlog**. Controleer het tijdstip van de recentste succesvolle uitvoering, eventuele `error` of `limit`-waarschuwingen en de verwerkte dag-/rijaantallen. Dit is het private JSON-log van maximaal 30 uitvoeringen; een ontbrekend log alleen bewijst noch activiteit noch inactiviteit.
3. Vergelijk het uitgevoerde script en de relevante PHP-configuratie met de actuele repo-code en de gegevensbankstructuur. Een groene CI of bestaande croncode bewijst geen geslaagde geplande uitvoeringen. Leg meettijd en herkomst van het bewijs vast.
4. Bestaande productieplanning of -retentie **niet aanpassen**; wijziging van cron, archiveringscode, gegevensbank of back-ups vereist afzonderlijke expliciete toestemming. De geaggregeerde campagneresultaten blijven tot en met 31 oktober 2027 bewaard, zonder automatische volledige verwijdering.

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
