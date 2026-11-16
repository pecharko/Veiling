-- Artist overview publication flag: additive and default ON to preserve current visibility.
-- Owner snapshot decision (2026-11-16): older snapshots need not be restore-compatible
-- after this schema migration. Existing snapshots must remain untouched.
-- No existing artist, seller, lot, photograph or setting values may be deleted.
-- Production requires separate exact DB authorization and independently verified
-- full database backup; execute this migration once, then check information_schema.
ALTER TABLE settings ADD COLUMN show_artists_overview TINYINT(1) NOT NULL DEFAULT 1;
