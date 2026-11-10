-- One-time, separately authorized MySQL 8.4 data migration.
-- Requires a verified independent database backup and catalog snapshot.
-- Execute only once; CREATE TABLE intentionally fails on repeat execution.
-- All pre-existing lots lose their old category assignment as requested.
-- No production data is touched by creating/merging this file.
CREATE TABLE lot_categories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  UNIQUE KEY uq_lot_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO lot_categories (name)
VALUES ('Moderne Kunst'), ('Oude Meesters');

UPDATE lots SET category='' WHERE category<>'';

ALTER TABLE lots ALTER COLUMN category SET DEFAULT '';
