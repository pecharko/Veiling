-- VOORBEREIDING, NIET AUTOMATISCH UITVOEREN.
-- Vereist afzonderlijke exacte productieautorisatie en expliciete aanvaarding van
-- het risico op onomkeerbaar gegevensverlies zonder databaseback-up.
-- Volgens keuze voor issue #185: geen nieuwe databaseback-up en geen controle
-- van bestaande herstelpunten. Dit SQL-bestand nooit automatisch uitvoeren.
-- Bestaande kavels krijgen bewust GEEN verzonnen maker.
-- De bestaande estimate_min/estimate_max blijven bestaan tijdens de gefaseerde
-- migratie. Pas verwijderen na volledige code-, snapshot- en UI-omschakeling.
ALTER TABLE lots
  ADD COLUMN created_by_admin_id INT NULL DEFAULT NULL,
  ADD COLUMN auction_only TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN market_price DECIMAL(12,2) NULL DEFAULT NULL,
  ADD COLUMN starting_bid DECIMAL(12,2) NULL DEFAULT NULL;

-- Nog geen automatische overname: oude schattingen zijn semantisch niet
-- gelijk aan marktprijs en startbod. Valideer historische gegevens handmatig.
-- Geen cascade delete van makers: behoud auditinformatie bij accountbeheer.
