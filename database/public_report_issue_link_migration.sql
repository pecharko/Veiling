-- Productiemigratie uitsluitend na afzonderlijke autorisatie, voorafgaande back-up en herstelplan.
-- Snapshotcontract: oude_snapshots_herstellen = nee.
-- Vooraf controleren: SHOW COLUMNS FROM settings LIKE 'show_report_issue_link';
-- Voer ALTER alleen uit als de kolom nog niet bestaat.
ALTER TABLE settings ADD COLUMN show_report_issue_link TINYINT(1) NOT NULL DEFAULT 0;
-- Nadien controleren: SELECT id, show_report_issue_link FROM settings;
-- Geen automatische gegevensopschoning en geen verwijdering van bestaande back-ups.
