-- Proposed isolated artist overview publication flag; production migration requires
-- separate database authorization, verified backup and snapshot-compatibility decision.
-- Defaults ON: preserves the existing public overview until the administrator disables it.
ALTER TABLE settings ADD COLUMN show_artists_overview TINYINT(1) NOT NULL DEFAULT 1;
