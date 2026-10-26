-- Manual schema change; requires separate production authorization before PHP deploy.
-- Disabled accounts are denied at login and every authenticated request.
ALTER TABLE admins ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1;
-- Existing administrator accounts remain enabled by default.
-- Verify: SELECT id,email,role,is_active FROM admins;
