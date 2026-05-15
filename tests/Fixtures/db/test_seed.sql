-- Test fixture seed — loaded once per suite in bootstrap.php,
-- then re-applied after each integration test via IntegrationTestCase::tearDown().
-- Password for all users: test-password (bcrypt cost 4 — fast for CI)

INSERT INTO tenants (id, name, slug, settings_json) VALUES
    (1, 'Test Mandant', 'test', '{"language":"de","timezone":"Europe/Berlin"}');

INSERT INTO users (id, tenant_id, email, password_hash, display_name, role, is_active) VALUES
    (1, 1, 'admin@test.local',             '$2y$04$gwRs5eyzTCtYeuKHcNlTrOjlit9QxBRlNX1o/w/Oym3SfNo/Bhp0O', 'Admin User',              'admin',              TRUE),
    (2, 1, 'isb@test.local',               '$2y$04$gwRs5eyzTCtYeuKHcNlTrOjlit9QxBRlNX1o/w/Oym3SfNo/Bhp0O', 'ISB User',                'isb',                TRUE),
    (3, 1, 'fachverantwortlich@test.local', '$2y$04$gwRs5eyzTCtYeuKHcNlTrOjlit9QxBRlNX1o/w/Oym3SfNo/Bhp0O', 'Fachverantwortlich User', 'fachverantwortlich', TRUE),
    (4, 1, 'auditor@test.local',            '$2y$04$gwRs5eyzTCtYeuKHcNlTrOjlit9QxBRlNX1o/w/Oym3SfNo/Bhp0O', 'Auditor User',            'auditor',            TRUE),
    (5, 1, 'management@test.local',         '$2y$04$gwRs5eyzTCtYeuKHcNlTrOjlit9QxBRlNX1o/w/Oym3SfNo/Bhp0O', 'Management User',         'management',         TRUE),
    (6, 1, 'readonly@test.local',           '$2y$04$gwRs5eyzTCtYeuKHcNlTrOjlit9QxBRlNX1o/w/Oym3SfNo/Bhp0O', 'Readonly User',           'readonly',           TRUE),
    (7, 1, 'inactive@test.local',           '$2y$04$gwRs5eyzTCtYeuKHcNlTrOjlit9QxBRlNX1o/w/Oym3SfNo/Bhp0O', 'Inactive User',           'readonly',           FALSE);
