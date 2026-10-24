# Read-only databasepreflight: verkoopplaats en veilingsnummer

Doel: vóór een afzonderlijk goedgekeurde MySQL-migratie controleren of de productie-DB van 41lokeren.be de velden en unieke index al heeft. Gebruik **uitsluitend** de juiste OVH phpMyAdmin-database. Dit document voert niets uit.

## Stap 1 — alleen metadata (ook veilig vóór migratie)

```sql
SELECT DATABASE() AS selected_database;
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'lots'
  AND COLUMN_NAME IN ('id','lot_number','sale_location','auction_number')
ORDER BY ORDINAL_POSITION;

SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME, SEQ_IN_INDEX
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME='lots'
  AND (COLUMN_NAME IN ('lot_number','auction_number') OR INDEX_NAME='uq_lots_auction_number')
ORDER BY INDEX_NAME, SEQ_IN_INDEX;

SELECT CONSTRAINT_NAME, CHECK_CLAUSE
FROM information_schema.CHECK_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA=DATABASE()
  AND CONSTRAINT_NAME='chk_lots_auction_number';

SELECT COUNT(*) AS total_lots, MIN(lot_number) AS min_lot_number,
       MAX(lot_number) AS max_lot_number
FROM lots;
```

Stop als de gekozen database niet herkenbaar is als de productiecatalogus, of als één nieuw veld wel bestaat en het andere niet.

## Stap 2 — uitsluitend als beide nieuwe kolommen bestaan

```sql
SELECT sale_location, COUNT(*) AS lots_count
FROM lots GROUP BY sale_location;
SELECT COUNT(*) AS assigned_numbers, COUNT(DISTINCT auction_number) AS distinct_numbers,
       MIN(auction_number) AS min_auction_number, MAX(auction_number) AS max_auction_number
FROM lots WHERE auction_number IS NOT NULL;
SELECT COUNT(*) AS invalid_location_or_number
FROM lots
WHERE sale_location NOT IN ('markt','veiling')
   OR (auction_number IS NOT NULL AND auction_number < 1)
   OR (sale_location='veiling' AND auction_number IS NULL);
```

## Beoordeling en veiligheidsgrenzen

- **Beide nieuwe kolommen ontbreken:** migratie nog nodig; pas plannen na een actuele, geverifieerde data-only back-up en afzonderlijke autorisatie.
- **Beide bestaan met correcte index:** niet opnieuw migreren; vergelijk eerst code, constraints en aanwezige data.
- **Slechts één kolom of afwijkende index/constraint:** STOP; eerst afwijking onderzoeken, geen automatische migratie.
- De nieuwe velden mogen nooit leiden tot een wijziging van bestaande `lot_number`-waarden.
- Een veilingsnummer wordt nooit opnieuw gebruikt zolang de rij bestaat; bij het verwijderen van een kavel kan het huidige MAX+1-algoritme de laatst gebruikte waarde hergebruiken. **Dit is een open ontwerprisico** als veilingsnummers ook na verwijdering permanent uniek moeten blijven. Beoordeel een aparte sequence/tombstone vóór productie.
- Controleer dat `private/snapshots.php` mee in de release-allowlist komt. Voorafgaand aan deployment ook herstel van nieuwe en bestaande snapshots in een geïsoleerde omgeving testen.
- Voer uitsluitend SELECT-statements uit; geen ALTER, UPDATE, INSERT, DELETE, ZIP-restore of uploads. Noteer resultaten zonder secrets of persoonsgegevens in een reviewverslag.

## Volgende gate

Na bovenstaande resultaten: documenteer exacte productie-baseline, maak een migratieplan en een apart PHP-releaseplan. Geen automatische merge of productieactie volgt uit deze preflight.
