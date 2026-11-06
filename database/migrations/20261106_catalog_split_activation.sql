-- Execute only after explicit production database authorization and controlled preflight.
-- Default OFF: no change to public layout until the administrator activates the split.
ALTER TABLE settings ADD COLUMN catalog_split_active TINYINT(1) NOT NULL DEFAULT 0;
