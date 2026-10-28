-- Issue #289. VOORSTEL, NIET AUTOMATISCH UITVOEREN OP OVH.
-- Controleer vlak voor de migratie met SHOW CREATE TABLE lots en SELECT status,COUNT(*) FROM lots GROUP BY status.
-- Maak eerst een goedgekeurde data-only back-up en test het herstel geisoleerd.
-- Voer uitsluitend uit na afzonderlijke expliciete autorisatie voor de productiedatabase.
ALTER TABLE lots
  MODIFY COLUMN status ENUM('draft','published','sold','canceled')
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
  NOT NULL DEFAULT 'draft';
-- Controleer na afloop de ENUM via SHOW COLUMNS FROM lots LIKE 'status';
-- Behoud alle bestaande kavelstatussen; maak hier geen data-UPDATE deel van.
