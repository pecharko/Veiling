-- Handmatige migratie, uitsluitend na afzonderlijke autorisatie en data-only back-up.
-- MySQL 8.4: behoud bestaande website als online (default 1).
-- Controleer vooraf via SHOW COLUMNS FROM settings LIKE 'is_site_online';
ALTER TABLE settings ADD COLUMN is_site_online TINYINT(1) NOT NULL DEFAULT 1;
-- Controleer nadien via SELECT id,is_site_online FROM settings;
