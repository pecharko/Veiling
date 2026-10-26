-- Issue #139: permanente reservering van gewone kavelnummers (lot_number).
-- HANDMATIGE SQL-MIGRATIE: nooit automatisch via code-deploy uitvoeren.
-- Vereist afzonderlijke productieautorisatie; controleer tabellen vóór uitvoering.
-- Reeds verwijderde historische kavels kunnen niet worden gereconstrueerd.
CREATE TABLE issued_lot_numbers (
  lot_number INT UNSIGNED NOT NULL PRIMARY KEY,
  issued_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Reserveer alle lotnummers die op het migratiemoment bestaan.
INSERT INTO issued_lot_numbers (lot_number)
  SELECT lot_number FROM lots;

-- Controle na migratie (read-only):
-- SELECT COUNT(*) FROM issued_lot_numbers;
-- SELECT COUNT(*) FROM lots;
-- SELECT l.lot_number FROM lots l LEFT JOIN issued_lot_numbers i
--   ON l.lot_number = i.lot_number WHERE i.lot_number IS NULL;
-- De reserveringstabel wordt NOOIT geleegd bij verwijderen van kavels.
-- Ook een snapshot-restore mag oude reserveringen niet verwijderen.
