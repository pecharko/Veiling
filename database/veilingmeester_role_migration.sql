-- Manual production migration: only after database backup and separate authorization.
-- Add veilingmeester to the existing admins.role enum; stored roles unchanged.
ALTER TABLE admins MODIFY COLUMN role ENUM('administrator','assistant','veilingmeester') NOT NULL DEFAULT 'administrator';
