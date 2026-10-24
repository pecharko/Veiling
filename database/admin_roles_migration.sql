-- MANUAL MIGRATION ONLY. Run once after verified DB backup and explicit approval.
-- Existing accounts retain full access.
ALTER TABLE admins ADD COLUMN role ENUM('administrator','assistant') NOT NULL DEFAULT 'administrator';
