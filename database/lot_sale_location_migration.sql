-- T-: verkoopplaats en unieke veilingsnummers.
-- MANUEEL uitvoeren op MySQL 8.4, na data-only backup, vóór uitrol van PHP.
-- Verifieer vooraf dat deze twee kolommen nog niet bestaan.
-- Bestaande kavels worden standaard 'markt'; veilingsnummers zijn NULL.
-- Een veilingsnummer blijft gereserveerd wanneer een kavel terug naar markt gaat.
ALTER TABLE lots
  ADD COLUMN sale_location ENUM('markt','veiling') NOT NULL DEFAULT 'markt',
  ADD COLUMN auction_number INT UNSIGNED NULL DEFAULT NULL,
  ADD UNIQUE KEY uq_lots_auction_number (auction_number),
  ADD CONSTRAINT chk_lots_auction_number_positive
    CHECK (auction_number IS NULL OR auction_number >= 1);

-- Handmatige verificatie, read-only:
-- SHOW COLUMNS FROM lots LIKE 'sale_location';
-- SHOW COLUMNS FROM lots LIKE 'auction_number';
-- SHOW INDEX FROM lots WHERE Key_name='uq_lots_auction_number';
-- SELECT sale_location,COUNT(*) FROM lots GROUP BY sale_location;
