# Issue #224 - OVH nachtelijke bezoekersarchivering (controle en vrijgave)

## Scope en bewaartermijn
- Bewaar drie volledige afgesloten UTC-dagen met detailrecords in `visitor_stats_daily`, plus de lopende UTC-dag.
- De cron gebruikt de gedeelde expliciete UTC-berekening `visitor_stats_utc_cutoff()` en archiveert uitsluitend dagen met `visit_date < cutoff` naar `visitor_stats_day_totals` en `visitor_stats_lot_totals`; pas na een geslaagde transactie worden de bijbehorende detailrecords verwijderd. Voorbeeld: op 20 november (UTC) blijven 17, 18, 19 en de lopende 20 november detaildagen; 16 november en ouder komen voor archivering in aanmerking.
- Eén dag per database-transactie; max. 20.000 bronrijen per dag en 14 dagen per cron-uitvoering.
- De eigenaar heeft in OVHcloud Manager een **actieve dagelijkse cron** voor `private/visitor_stats_daily_cron.php` getoond, ingesteld op `39 0 * * *`, PHP 8.5. Het private cronlog toonde succesvolle maar lege uitvoeringen op 18, 19 en 20 november 2026 (`days=0`, `rows=0`). Dit is **eigenaar-aangeleverd schermbewijs**, geen onafhankelijke OVH-readback of bewijs van effectieve retentie/aggregatie. De hosting staat in Canada; het verschil tussen OVH-planning en gelogde tijd wordt door de eigenaar aan tijdzones toegeschreven. De bewaartermijn van drie dagen betekent niet dat de cron om de drie dagen draait. De ontwikkelbranch of een PR verandert de bestaande planning niet.

## Read-only vaststelling van de actuele OVH-cron

1. Controleer in **OVHcloud Manager → Web Cloud → Hosting → Geplande taken (Cron)** of er een actieve dagelijkse taak bestaat. Noteer uitsluitend de instellingstekst: scriptpad `private/visitor_stats_daily_cron.php`, ingestelde PHP-versie, frequentie, tijdzone en eventuele foutstatus. Deze stap activeert of wijzigt niets.
2. Open als beheerder op `admin/statistieken.php` de sectie **Automatische archivering — cronlog**. Controleer het tijdstip van de recentste succesvolle uitvoering, eventuele `error` of `limit`-waarschuwingen en de verwerkte dag-/rijaantallen. Dit is het private JSON-log van maximaal 30 uitvoeringen; een ontbrekend log alleen bewijst noch activiteit noch inactiviteit.
3. Vergelijk het uitgevoerde script en de relevante PHP-configuratie met de actuele repo-code en de gegevensbankstructuur. Een groene CI of bestaande croncode bewijst geen geslaagde geplande uitvoeringen. Leg meettijd en herkomst van het bewijs vast.
4. Bestaande productieplanning of -retentie **niet aanpassen**; wijziging van cron, archiveringscode, gegevensbank of back-ups vereist afzonderlijke expliciete toestemming. De geaggregeerde campagneresultaten blijven tot en met 31 oktober 2027 bewaard, zonder automatische volledige verwijdering.

## Nog vereiste validatie van de al actieve taak en toekomstige codevrijgave
1. Verifieer read-only MySQL-schema, rechten, huidige historische detailrecords en DB-quota; controleer dat de totalentabellen dezelfde structuur hebben als `database/visitor_stats_aggregates.sql`.
2. Maak een expliciet geautoriseerde data-only back-up en test herstel in isolatie. De applicatiesnapshot kan statistieken uitsluiten.
3. Valideer met een geïsoleerde MySQL 8.4-test de UTC-grens, 20.001-recordweigering, twee parallelle uitvoeringen, rollback, late historische INSERT, volledige en gedeeltelijke catch-up.
4. Controleer read-only of de werkelijk gebruikte PHP CLI-versie en het private scriptpad overeenkomen met de getoonde OVH-configuratie. Wijzig geen launcher, planning of cron zonder afzonderlijke toestemming.
5. Cron moet STDOUT/STDERR en exitcode vastleggen op een toegangsbeschermde locatie met logrotatie. Waarschuw een beheerder bij `archive_failed`, ontbrekend dagelijks successignaal, of `archive_catchup_limit_reached`. GitHub CI is geen live monitoring.
6. Houd de foutafhandeling fail-closed: geen hervatting door aggregaatrijen te overschrijven, geen live handmatige SQL-reparaties zonder back-up en autorisatie.
7. Bevestig read-only de actuele dagtotalen, de nog aanwezige details ouder dan de UTC-cutoff en het databasevolume. De waargenomen 0-rij-uitvoeringen bewijzen geen verliesvrije archivering van oudere details.

## Read-only MySQL-validatie (handmatig, geen productie-schrijfactie)

Voer uitsluitend onderstaande `SELECT`-queries uit via een bevoegd **read-only** databaseaccount, nadat de actuele schema- en toegangsrechten zijn gecontroleerd. Geen `CALL`, `SET`, migratie, transactie met wijziging, `DELETE`, `UPDATE`, `INSERT` of herstelactie. Exporteer geen bezoekers-ID's en publiceer geen database-inloggegevens.

```sql
SELECT UTC_DATE() AS utc_today,
       DATE_SUB(UTC_DATE(), INTERVAL 3 DAY) AS earliest_retained_utc_date;

SELECT COUNT(*) AS stale_detail_rows,
       COUNT(DISTINCT visit_date) AS stale_detail_days,
       MIN(visit_date) AS oldest_stale_day,
       MAX(visit_date) AS newest_stale_day
FROM visitor_stats_daily
WHERE visit_date < DATE_SUB(UTC_DATE(), INTERVAL 3 DAY);

SELECT COUNT(*) AS archived_day_rows,
       COALESCE(SUM(views), 0) AS archived_views,
       COALESCE(SUM(lot_views), 0) AS archived_lot_views
FROM visitor_stats_day_totals
WHERE visit_date < DATE_SUB(UTC_DATE(), INTERVAL 3 DAY);

SELECT COUNT(*) AS archived_lot_rows,
       COALESCE(SUM(views), 0) AS archived_lot_views
FROM visitor_stats_lot_totals
WHERE visit_date < DATE_SUB(UTC_DATE(), INTERVAL 3 DAY);
```

Controleer op **hetzelfde UTC-meetmoment** ook de laatste cronlog-entry en eventuele waarschuwingen; dagwissels tussen queries kunnen de cutoff veranderen. Een waarde `stale_detail_rows > 0` vereist onderzoek naar achterstallige dagen, veiligheidslimieten of fouten, **geen automatische herstart of handmatige verwijdering**. `stale_detail_rows = 0` bewijst op zichzelf geen correcte historische aggregatie: alle dagen kunnen leeg zijn geweest. Vergelijk totalen alleen voor werkelijk gearchiveerde dagen met eerder veilig vastgelegde bronmetingen; zonder dergelijke baselines kan verliesvrijheid niet retrospectief worden bewezen. De twee `archived_lot_views`-waarden moeten voor overeenkomstige volledig gearchiveerde dagen overeenkomen, maar samengetelde totalen over verschillende dekking zijn geen sluitend bewijs.

## Bekende risico's
- Uitzonderlijke historische imports kunnen een reeds gearchiveerde datum opnieuw vullen. Een PK-conflict blokkeert veilig verdere verwerking; een beheerder moet dit afzonderlijk onderzoeken.
- Volledige quota-vermindering is niet gegarandeerd zonder MySQL-space accounting; InnoDB kan vrije pagina's intern hergebruiken.
- De reeds actieve CLI-runner logt samengevatte aantallen, geen bezoekerscodes; automatische externe notificatie is niet aangetoond. Onderzoek read-only de feitelijke exitcode-/logbewaking vóór eventuele wijzigingen.
- Geen automatische verwijdering van de geaggregeerde statistieken op 31 oktober 2027.

## Codevrijgave van de gedeelde UTC-berekening

Gebruik de bestaande gespecialiseerde workflow `issue-224-archive-code-release.yml` met `scripts/deploy_issue224_archive.py`. De vaste scope bevat `catalogus-public/admin/statistieken.php`, `catalogus-public/privacy.php`, `private/visitor_stats_archive_one_day_experimental.php` en `private/visitor_stats_daily_cron.php`. Voor deze wijziging veranderen drie runtimebestanden; de privacypagina blijft inhoudelijk gelijk en wordt bij gelijke bron- en remote-inhoud overgeslagen.

De nieuwe helper moet vóór de adminpagina en cron worden gepubliceerd: die kunnen de nieuwe functie `visitor_stats_utc_cutoff()` aanroepen. De uploader gebruikt daarom de volgorde helper → adminpagina → privacypagina → cron. Bij een fout herstelt de bestaande rollback de gewijzigde bestanden in omgekeerde volgorde, zodat de aanroepers vóór de helper worden teruggezet. De volgorde van de vier goed te keuren remotehashes blijft adminpagina, privacypagina, helper, cron; verwissel die workflowinputs niet met de uploadvolgorde.

Code, regressietests en de aanpassing van de uploadvolgorde worden in dezelfde PR beoordeeld. Deze gespecialiseerde route gebruikt haar bestaande vaste bestandslijst en preflightartifact; een algemeen shared-OVH-manifest is daarvoor geen vervanging. Na een geautoriseerde merge moeten de CI en de automatische read-only preflight voor de nieuwe exacte `main`-SHA worden gecontroleerd. Gebruik de daar geverifieerde bron- en remotehashes voor de afzonderlijke productieautorisatie en hercontroleer drift vlak vóór upload.

De codevrijgave wijzigt of start geen geplande taak. Omdat de eigenaar een reeds actieve OVH-cron heeft getoond, kan de eerstvolgende geplande uitvoering na een codevrijgave wel de nieuwe code gebruiken. Controleer vervolgens het cronlog en de UTC-cutoff zonder een extra archivering handmatig te starten.

## Vrijgavegrenzen
De code-PR vereist menselijke review vanwege archivering en verwijdering van historische gegevens. Merge ≠ deployment ≠ wijziging van de bestaande OVH-cron. Productiecode, DB-writes en OVH-cronwijzigingen vereisen afzonderlijke expliciete autorisatie.
