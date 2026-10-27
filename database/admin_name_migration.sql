-- Afzonderlijk te autoriseren productiemigratie; uitvoeren VÓÓR de PHP-code.
-- Bestaande accounts blijven werken: naam is initieel leeg en wordt via Gebruikersbeheer ingevuld.
-- Eerst in een geïsoleerde MySQL 8.4-testomgeving controleren.
ALTER TABLE admins ADD COLUMN name VARCHAR(120) NOT NULL DEFAULT '' AFTER email;

-- Read-only verificatie na expliciet goedgekeurde uitvoering:
-- SHOW COLUMNS FROM admins LIKE 'name';
-- SELECT id, name, email FROM admins ORDER BY id;
