-- UP
ALTER TABLE users
    ADD COLUMN is_superadmin BOOLEAN NOT NULL DEFAULT FALSE
        AFTER is_active;

ALTER TABLE tenants
    ADD COLUMN is_active BOOLEAN NOT NULL DEFAULT TRUE
        AFTER slug;

-- Promote the first admin user of the first tenant to superadmin
-- so the platform owner has immediate access to tenant management.
UPDATE users
SET is_superadmin = TRUE
WHERE tenant_id = (SELECT MIN(id) FROM tenants)
  AND role = 'admin'
ORDER BY id
LIMIT 1;

-- DOWN
ALTER TABLE users DROP COLUMN is_superadmin;
ALTER TABLE tenants DROP COLUMN is_active;
