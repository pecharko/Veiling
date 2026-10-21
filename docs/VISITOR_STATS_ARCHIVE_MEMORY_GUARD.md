# Veiligheidslimiet voor handmatige statistiekarchivering

Voorbereid voor review, geen productie-uitrol.

De bestaande functie `archive_visitor_stats_before` maakt PHP-arrays met unieke dagelijkse bezoekerscodes en unieke bezoekerscodes per kavel. Zonder grens groeit het geheugengebruik met het aantal detailrecords.

Deze wijziging leest met `LIMIT 1001` maximaal 1001 records onder `FOR UPDATE` en weigert een archivering van meer dan **1000** detailrecords binnen dezelfde transactie, voordat de aggregaten worden opgeslagen of codes verwijderd. Ook bij mislukking wordt de transactie teruggedraaid. Dit is een **fail-closed beveiliging**, geen schaalbare batch-implementatie: als er meer dan 1000 oude detailrecords klaarstaan, kan de beheerder nog niet archiveren totdat een afzonderlijk getest batchingontwerp beschikbaar is. De limiet mag niet ad hoc worden verhoogd zonder nieuw geheugen- en lockingonderzoek.

De MySQL 8.4-isolatietest voegt 1001 historische unieke records toe, verwacht een weigering, onveranderde bron- en aggregaattellingen en een gesloten transactie. De eerdere tests voor tellingen, conflict, ongeldige records en herstel blijven bestaan.

**Open voor productie:** aantonen MySQL 8.4 CI groen op PR-head én finale main, PHP-lint, reële volumetest en transactielockanalyse, eventueel batch-implementatie, read-only OVH-inventaris, AVG-review, DB-back-up en geïsoleerde hersteltest, afzonderlijke SQL-toestemming en twee expliciete release/deploytoestemmingen. Geen automatische cleanup en geen wijziging aan publieke tracking.
