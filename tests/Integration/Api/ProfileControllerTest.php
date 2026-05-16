<?php

declare(strict_types=1);

namespace GsppManager\Tests\Integration\Api;

use GsppManager\Controller\ProfileController;
use GsppManager\Security\PasswordHasher;
use GsppManager\Security\TotpService;
use GsppManager\Security\FieldEncryptor;
use GsppManager\Tests\Integration\IntegrationTestCase;

class ProfileControllerTest extends IntegrationTestCase
{
    // ─── show ────────────────────────────────────────────────────

    public function test_show_returns_own_profile(): void
    {
        $this->loginAs('isb');

        $res = $this->callController(ProfileController::class, 'show');

        $this->assertSuccess($res);
        $this->assertSame('isb@test.local', $res['data']['email']);
        $this->assertSame('isb', $res['data']['role']);
        $this->assertArrayHasKey('totp_enabled', $res['data']);
    }

    // ─── update ──────────────────────────────────────────────────

    public function test_update_display_name(): void
    {
        $this->loginAs('readonly');

        $res = $this->callController(
            ProfileController::class,
            'update',
            ['display_name' => 'Updated Name'],
            [],
            'PUT',
        );

        $this->assertSuccess($res);

        // Verify in DB
        $row = $this->db->query("SELECT display_name FROM users WHERE id = 6")->fetch();
        $this->assertSame('Updated Name', $row['display_name']);
    }

    public function test_update_email_requires_password(): void
    {
        $this->loginAs('readonly');

        $res = $this->callController(
            ProfileController::class,
            'update',
            ['email' => 'new@test.local'],
            [],
            'PUT',
        );

        // Should fail: no password provided for email change
        $this->assertFailure($res);
    }

    public function test_update_email_with_correct_password(): void
    {
        $this->loginAs('readonly');

        $res = $this->callController(
            ProfileController::class,
            'update',
            ['email' => 'new-readonly@test.local', 'password' => 'test-password'],
            [],
            'PUT',
        );

        $this->assertSuccess($res);
    }

    public function test_update_email_rejected_with_wrong_password(): void
    {
        $this->loginAs('readonly');

        $res = $this->callController(
            ProfileController::class,
            'update',
            ['email' => 'new@test.local', 'password' => 'wrong-password'],
            [],
            'PUT',
        );

        $this->assertFailure($res);
    }

    public function test_update_email_rejected_if_taken(): void
    {
        $this->loginAs('readonly');

        $res = $this->callController(
            ProfileController::class,
            'update',
            ['email' => 'admin@test.local', 'password' => 'test-password'],
            [],
            'PUT',
        );

        $this->assertFailure($res);
        $this->assertStringContainsString('vergeben', $res['error']);
    }

    // ─── changePassword ──────────────────────────────────────────

    public function test_change_password_success(): void
    {
        $this->loginAs('readonly');

        $res = $this->callController(
            ProfileController::class,
            'changePassword',
            [
                'current_password'    => 'test-password',
                'new_password'        => 'newSecurePassword123!',
                'new_password_confirm' => 'newSecurePassword123!',
            ],
            [],
            'POST',
        );

        $this->assertSuccess($res);

        // Verify the new hash works
        $row = $this->db->query("SELECT password_hash FROM users WHERE id = 6")->fetch();
        $this->assertTrue(PasswordHasher::verify('newSecurePassword123!', $row['password_hash']));
    }

    public function test_change_password_wrong_current(): void
    {
        $this->loginAs('readonly');

        $res = $this->callController(
            ProfileController::class,
            'changePassword',
            [
                'current_password'    => 'wrong-password',
                'new_password'        => 'newSecurePassword123!',
                'new_password_confirm' => 'newSecurePassword123!',
            ],
            [],
            'POST',
        );

        $this->assertFailure($res);
    }

    public function test_change_password_too_short(): void
    {
        $this->loginAs('readonly');

        $res = $this->callController(
            ProfileController::class,
            'changePassword',
            [
                'current_password'    => 'test-password',
                'new_password'        => 'short',
                'new_password_confirm' => 'short',
            ],
            [],
            'POST',
        );

        $this->assertFailure($res);
        $this->assertStringContainsString('12 Zeichen', $res['error']);
    }

    public function test_change_password_mismatch(): void
    {
        $this->loginAs('readonly');

        $res = $this->callController(
            ProfileController::class,
            'changePassword',
            [
                'current_password'    => 'test-password',
                'new_password'        => 'newSecurePassword123!',
                'new_password_confirm' => 'differentPassword123!',
            ],
            [],
            'POST',
        );

        $this->assertFailure($res);
    }

    // ─── sessions ────────────────────────────────────────────────

    public function test_sessions_returns_current_session(): void
    {
        $this->loginAs('isb');

        $res = $this->callController(ProfileController::class, 'sessions');

        $this->assertSuccess($res);
        $this->assertNotEmpty($res['data']['sessions']);
        $this->assertTrue($res['data']['sessions'][0]['current']);
    }

    // ─── TOTP setup ──────────────────────────────────────────────

    public function test_totp_setup_returns_secret_and_uri(): void
    {
        $this->loginAs('isb');

        $res = $this->callController(ProfileController::class, 'totpSetup', [], [], 'POST');

        $this->assertSuccess($res);
        $this->assertArrayHasKey('secret', $res['data']);
        $this->assertArrayHasKey('otpauth_uri', $res['data']);
        $this->assertStringStartsWith('otpauth://totp/', $res['data']['otpauth_uri']);
    }

    public function test_totp_setup_rejected_if_already_enabled(): void
    {
        $this->loginAs('isb');
        // Enable TOTP manually
        $this->db->exec("UPDATE users SET totp_enabled = TRUE WHERE id = 2");

        $res = $this->callController(ProfileController::class, 'totpSetup', [], [], 'POST');

        $this->assertFailure($res);
        $this->assertStringContainsString('bereits aktiviert', $res['error']);
    }

    // ─── TOTP confirm ────────────────────────────────────────────

    public function test_totp_confirm_activates_totp(): void
    {
        $this->loginAs('isb');

        // First call setup to store secret
        $setupRes = $this->callController(ProfileController::class, 'totpSetup', [], [], 'POST');
        $this->assertSuccess($setupRes);
        $secret = $setupRes['data']['secret'];

        // Generate valid TOTP code
        $code = sprintf('%06d', (new \ReflectionClass(TotpService::class))
            ->getMethod('hotp')
            ->invokeArgs(null, [
                (new \ReflectionClass(TotpService::class))
                    ->getMethod('base32Decode')
                    ->invokeArgs(null, [$secret]),
                (int) floor(time() / 30),
            ]));

        $res = $this->callController(
            ProfileController::class,
            'totpConfirm',
            ['code' => $code],
            [],
            'POST',
        );

        $this->assertSuccess($res);

        $row = $this->db->query("SELECT totp_enabled FROM users WHERE id = 2")->fetch();
        $this->assertTrue((bool) $row['totp_enabled']);
    }

    public function test_totp_confirm_rejects_wrong_code(): void
    {
        $this->loginAs('isb');
        $this->callController(ProfileController::class, 'totpSetup', [], [], 'POST');

        $res = $this->callController(
            ProfileController::class,
            'totpConfirm',
            ['code' => '000000'],
            [],
            'POST',
        );

        $this->assertFailure($res);
    }

    // ─── TOTP delete ─────────────────────────────────────────────

    public function test_totp_delete_disables_totp(): void
    {
        $this->loginAs('isb');
        // Enable TOTP manually
        $secret    = TotpService::generateSecret();
        $encryptor = new FieldEncryptor();
        $this->db->prepare("UPDATE users SET totp_enabled = TRUE, totp_secret_enc = ? WHERE id = 2")
            ->execute([$encryptor->encrypt($secret)]);

        $res = $this->callController(
            ProfileController::class,
            'totpDelete',
            ['password' => 'test-password'],
            [],
            'DELETE',
        );

        $this->assertSuccess($res);

        $row = $this->db->query("SELECT totp_enabled FROM users WHERE id = 2")->fetch();
        $this->assertFalse((bool) $row['totp_enabled']);
    }

    public function test_totp_delete_wrong_password(): void
    {
        $this->loginAs('isb');
        $this->db->exec("UPDATE users SET totp_enabled = TRUE WHERE id = 2");

        $res = $this->callController(
            ProfileController::class,
            'totpDelete',
            ['password' => 'wrong'],
            [],
            'DELETE',
        );

        $this->assertFailure($res);
    }
}
