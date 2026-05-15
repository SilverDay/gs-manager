-- UP
-- Default tenant and admin user (password: changeme!)
-- Admin MUST change password on first login
INSERT INTO tenants (name, slug, settings_json) VALUES
    ('Standard', 'default', '{"language": "de", "timezone": "Europe/Berlin"}')
ON DUPLICATE KEY UPDATE name = name;

-- Password hash for 'changeme!' using PASSWORD_BCRYPT cost 12
-- Generate fresh hash: php -r "echo password_hash('changeme!', PASSWORD_BCRYPT, ['cost' => 12]);"
INSERT INTO users (tenant_id, email, password_hash, display_name, role, is_active) VALUES
    (1, 'admin@localhost', '$2y$12$pvOeAKCnY4019IwFApAbu.ph5wFR.xrIiEo0xGPOar4CmV.BLoNAy', 'Administrator', 'admin', TRUE)
ON DUPLICATE KEY UPDATE email = email;

-- DOWN
DELETE FROM users WHERE email = 'admin@localhost' AND tenant_id = 1;
DELETE FROM tenants WHERE slug = 'default';
