<?php

declare(strict_types=1);

namespace GsppManager\Tests\Integration\Api;

use GsppManager\Controller\AdminController;
use GsppManager\Security\FieldEncryptor;
use GsppManager\Security\PasswordHasher;
use GsppManager\Tests\Integration\IntegrationTestCase;

class AdminControllerTest extends IntegrationTestCase
{
    // ─── listUsers ───────────────────────────────────────────────

    public function test_list_users_as_admin(): void
    {
        $this->loginAs('admin');

        $res = $this->callController(AdminController::class, 'listUsers');

        $this->assertSuccess($res);
        $this->assertNotEmpty($res['data']['users']);
        // All users belong to tenant 1
        foreach ($res['data']['users'] as $u) {
            $this->assertArrayHasKey('role', $u);
            $this->assertArrayHasKey('is_active', $u);
        }
    }

    public function test_list_users_forbidden_for_non_admin(): void
    {
        foreach (['isb', 'fachverantwortlich', 'auditor', 'management', 'readonly'] as $role) {
            $this->loginAs($role);
            $res = $this->callController(AdminController::class, 'listUsers');
            $this->assertFailure($res);
        }
    }

    // ─── createUser ──────────────────────────────────────────────

    public function test_create_user_success(): void
    {
        $this->loginAs('admin');

        $res = $this->callController(
            AdminController::class,
            'createUser',
            [
                'email'        => 'newuser@test.local',
                'display_name' => 'New User',
                'role'         => 'readonly',
                'password'     => 'securePassword123!',
            ],
            [],
            'POST',
        );

        $this->assertSuccess($res);
        $this->assertArrayHasKey('id', $res['data']);

        // Confirm in DB
        $row = $this->db->query("SELECT email FROM users WHERE email = 'newuser@test.local'")->fetch();
        $this->assertSame('newuser@test.local', $row['email']);
    }

    public function test_create_user_duplicate_email(): void
    {
        $this->loginAs('admin');

        $res = $this->callController(
            AdminController::class,
            'createUser',
            [
                'email'        => 'admin@test.local', // already exists
                'display_name' => 'Duplicate',
                'role'         => 'readonly',
                'password'     => 'securePassword123!',
            ],
            [],
            'POST',
        );

        $this->assertFailure($res);
        $this->assertStringContainsString('vergeben', $res['error']);
    }

    public function test_create_user_short_password(): void
    {
        $this->loginAs('admin');

        $res = $this->callController(
            AdminController::class,
            'createUser',
            [
                'email'        => 'new2@test.local',
                'display_name' => 'Short PW',
                'role'         => 'readonly',
                'password'     => 'short',
            ],
            [],
            'POST',
        );

        $this->assertFailure($res);
    }

    public function test_create_user_invalid_role(): void
    {
        $this->loginAs('admin');

        $res = $this->callController(
            AdminController::class,
            'createUser',
            [
                'email'        => 'new3@test.local',
                'display_name' => 'Bad Role',
                'role'         => 'superuser',
                'password'     => 'securePassword123!',
            ],
            [],
            'POST',
        );

        $this->assertFailure($res);
    }

    // ─── showUser ────────────────────────────────────────────────

    public function test_show_user_as_admin(): void
    {
        $this->loginAs('admin');

        $res = $this->callController(AdminController::class, 'showUser', [], ['id' => 2]);

        $this->assertSuccess($res);
        $this->assertSame('isb@test.local', $res['data']['email']);
    }

    public function test_show_user_not_found(): void
    {
        $this->loginAs('admin');

        $res = $this->callController(AdminController::class, 'showUser', [], ['id' => 9999]);

        $this->assertFailure($res);
    }

    // ─── updateUser ──────────────────────────────────────────────

    public function test_update_user_role_and_status(): void
    {
        $this->loginAs('admin');

        $res = $this->callController(
            AdminController::class,
            'updateUser',
            ['role' => 'auditor', 'is_active' => false],
            ['id' => 6],
            'PUT',
        );

        $this->assertSuccess($res);

        $row = $this->db->query("SELECT role, is_active FROM users WHERE id = 6")->fetch();
        $this->assertSame('auditor', $row['role']);
        $this->assertFalse((bool) $row['is_active']);
    }

    public function test_update_user_invalid_role_rejected(): void
    {
        $this->loginAs('admin');

        $res = $this->callController(
            AdminController::class,
            'updateUser',
            ['role' => 'god'],
            ['id' => 6],
            'PUT',
        );

        $this->assertFailure($res);
    }

    // ─── resetUserPassword ───────────────────────────────────────

    public function test_reset_user_password(): void
    {
        $this->loginAs('admin');

        $res = $this->callController(
            AdminController::class,
            'resetUserPassword',
            ['new_password' => 'AdminResetPassword1!'],
            ['id' => 6],
            'POST',
        );

        $this->assertSuccess($res);

        $row = $this->db->query("SELECT password_hash FROM users WHERE id = 6")->fetch();
        $this->assertTrue(PasswordHasher::verify('AdminResetPassword1!', $row['password_hash']));
    }

    public function test_reset_user_password_too_short(): void
    {
        $this->loginAs('admin');

        $res = $this->callController(
            AdminController::class,
            'resetUserPassword',
            ['new_password' => 'short'],
            ['id' => 6],
            'POST',
        );

        $this->assertFailure($res);
    }

    // ─── getSettings / updateSettings ────────────────────────────

    public function test_get_settings_as_admin(): void
    {
        $this->loginAs('admin');

        $res = $this->callController(AdminController::class, 'getSettings');

        $this->assertSuccess($res);
        $this->assertArrayHasKey('settings', $res['data']);
    }

    public function test_settings_forbidden_for_non_admin(): void
    {
        $this->loginAs('isb');

        $res = $this->callController(AdminController::class, 'getSettings');

        $this->assertFailure($res);
    }

    public function test_update_settings_general(): void
    {
        $this->loginAs('admin');

        $res = $this->callController(
            AdminController::class,
            'updateSettings',
            ['language' => 'en', 'timezone' => 'UTC', 'session_timeout' => 60],
            [],
            'PUT',
        );

        $this->assertSuccess($res);

        $row = $this->db->query("SELECT settings_json FROM tenants WHERE id = 1")->fetch();
        $settings = json_decode($row['settings_json'], true);
        $this->assertSame('en', $settings['language']);
        $this->assertSame('UTC', $settings['timezone']);
    }

    public function test_update_settings_smtp_password_placeholder_not_stored(): void
    {
        $this->loginAs('admin');

        // First save a real password
        $this->callController(
            AdminController::class,
            'updateSettings',
            ['smtp_pass' => 'real-password-123'],
            [],
            'PUT',
        );

        // Now save with placeholder — should keep original
        $this->callController(
            AdminController::class,
            'updateSettings',
            ['smtp_pass' => '••••••••'],
            [],
            'PUT',
        );

        $row      = $this->db->query("SELECT settings_json FROM tenants WHERE id = 1")->fetch();
        $settings = json_decode($row['settings_json'], true);
        // Password is now stored encrypted; plaintext key must not exist
        $this->assertArrayNotHasKey('smtp_pass', $settings);
        $this->assertArrayHasKey('smtp_pass_enc', $settings);
        $decrypted = (new FieldEncryptor())->decrypt($settings['smtp_pass_enc']);
        $this->assertSame('real-password-123', $decrypted);
    }
}
