<?php

declare(strict_types=1);

namespace GsppManager\Tests\Integration\Api;

use GsppManager\Controller\AuthController;
use GsppManager\Tests\Integration\IntegrationTestCase;

class AuthControllerTest extends IntegrationTestCase
{
    // ── login ──────────────────────────────────────────────────────────────

    public function test_login_succeeds_with_valid_credentials(): void
    {
        $response = $this->callController(
            AuthController::class,
            'login',
            ['email' => 'admin@test.local', 'password' => 'test-password'],
            httpMethod: 'POST'
        );

        $this->assertSuccess($response);
        $this->assertArrayHasKey('user', $response['data']);
        $this->assertArrayHasKey('csrf_token', $response['data']);
        $this->assertSame('admin', $response['data']['user']['role']);
    }

    public function test_login_sets_session_on_success(): void
    {
        $this->callController(
            AuthController::class,
            'login',
            ['email' => 'isb@test.local', 'password' => 'test-password'],
            httpMethod: 'POST'
        );

        $this->assertSame(2, $_SESSION['user_id']);
        $this->assertSame(1, $_SESSION['tenant_id']);
        $this->assertSame('isb', $_SESSION['user_role']);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $response = $this->callController(
            AuthController::class,
            'login',
            ['email' => 'admin@test.local', 'password' => 'wrong-password'],
            httpMethod: 'POST'
        );

        $this->assertFailure($response);
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function test_login_fails_with_unknown_email(): void
    {
        $response = $this->callController(
            AuthController::class,
            'login',
            ['email' => 'nobody@test.local', 'password' => 'test-password'],
            httpMethod: 'POST'
        );

        $this->assertFailure($response);
    }

    public function test_login_fails_for_inactive_user(): void
    {
        $response = $this->callController(
            AuthController::class,
            'login',
            ['email' => 'inactive@test.local', 'password' => 'test-password'],
            httpMethod: 'POST'
        );

        $this->assertFailure($response);
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function test_login_fails_with_missing_email_field(): void
    {
        $response = $this->callController(
            AuthController::class,
            'login',
            ['password' => 'test-password'],
            httpMethod: 'POST'
        );

        $this->assertFailure($response);
    }

    public function test_login_fails_with_missing_password_field(): void
    {
        $response = $this->callController(
            AuthController::class,
            'login',
            ['email' => 'admin@test.local'],
            httpMethod: 'POST'
        );

        $this->assertFailure($response);
    }

    public function test_login_does_not_expose_whether_user_exists(): void
    {
        $unknownUserResponse = $this->callController(
            AuthController::class,
            'login',
            ['email' => 'nobody@test.local', 'password' => 'test-password'],
            httpMethod: 'POST'
        );
        $wrongPasswordResponse = $this->callController(
            AuthController::class,
            'login',
            ['email' => 'admin@test.local', 'password' => 'wrong'],
            httpMethod: 'POST'
        );

        // Both must return the same error message (no user enumeration)
        $this->assertSame($unknownUserResponse['error'], $wrongPasswordResponse['error']);
    }

    // ── logout ─────────────────────────────────────────────────────────────

    public function test_logout_clears_session(): void
    {
        $this->loginAs('admin');

        $this->callController(AuthController::class, 'logout', httpMethod: 'POST');

        // session_destroy() in logout means $_SESSION is cleared
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }

    // ── me ─────────────────────────────────────────────────────────────────

    public function test_me_returns_current_user_data(): void
    {
        $this->loginAs('isb');

        $response = $this->callController(AuthController::class, 'me');

        $this->assertSuccess($response);
        $this->assertSame('isb', $response['data']['user']['role']);
        $this->assertSame(1, $response['data']['user']['tenant_id']);
    }

    // ── csrf-token ─────────────────────────────────────────────────────────

    public function test_csrf_token_endpoint_returns_token(): void
    {
        $response = $this->callController(AuthController::class, 'csrfToken');

        $this->assertSuccess($response);
        $this->assertArrayHasKey('csrf_token', $response['data']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $response['data']['csrf_token']);
    }

    // ── password reset ─────────────────────────────────────────────────────

    public function test_password_reset_request_always_returns_generic_message(): void
    {
        // Unknown email
        $res1 = $this->callController(
            AuthController::class,
            'passwordResetRequest',
            ['email' => 'nobody@test.local'],
            httpMethod: 'POST',
        );
        $this->assertTrue($res1['success']);
        $this->assertStringContainsString('registriert', $res1['data']['message']);

        // Known email
        $res2 = $this->callController(
            AuthController::class,
            'passwordResetRequest',
            ['email' => 'admin@test.local'],
            httpMethod: 'POST',
        );
        $this->assertTrue($res2['success']);
        // Both responses must be identical (no enumeration)
        $this->assertSame($res1['data']['message'], $res2['data']['message']);
    }

    public function test_password_reset_confirm_with_valid_token(): void
    {
        // Insert a token directly (bypassing SMTP)
        $token     = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expires   = date('Y-m-d H:i:s', time() + 3600);
        $this->db->prepare(
            'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (1, ?, ?)'
        )->execute([$tokenHash, $expires]);

        $res = $this->callController(
            AuthController::class,
            'passwordResetConfirm',
            [
                'token'               => $token,
                'new_password'        => 'NewValidPassword123!',
                'new_password_confirm' => 'NewValidPassword123!',
            ],
            httpMethod: 'POST',
        );

        $this->assertSuccess($res);

        // Token should now be marked used
        $row = $this->db->query("SELECT used_at FROM password_reset_tokens WHERE token_hash = '{$tokenHash}'")->fetch();
        $this->assertNotNull($row['used_at']);
    }

    public function test_password_reset_confirm_with_invalid_token(): void
    {
        $res = $this->callController(
            AuthController::class,
            'passwordResetConfirm',
            [
                'token'               => 'completelyinvalidtoken',
                'new_password'        => 'NewValidPassword123!',
                'new_password_confirm' => 'NewValidPassword123!',
            ],
            httpMethod: 'POST',
        );

        $this->assertFailure($res);
    }

    public function test_password_reset_confirm_with_expired_token(): void
    {
        $token     = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expired   = date('Y-m-d H:i:s', time() - 3601); // already expired
        $this->db->prepare(
            'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (1, ?, ?)'
        )->execute([$tokenHash, $expired]);

        $res = $this->callController(
            AuthController::class,
            'passwordResetConfirm',
            [
                'token'               => $token,
                'new_password'        => 'NewValidPassword123!',
                'new_password_confirm' => 'NewValidPassword123!',
            ],
            httpMethod: 'POST',
        );

        $this->assertFailure($res);
        $this->assertStringContainsString('abgelaufen', $res['error']);
    }

    public function test_password_reset_confirm_brute_force_limit(): void
    {
        $token     = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expires   = date('Y-m-d H:i:s', time() + 3600);
        // Pre-set attempt_count to 4 (one more will hit the limit)
        $this->db->prepare(
            'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, attempt_count) VALUES (1, ?, ?, 4)'
        )->execute([$tokenHash, $expires]);

        $res = $this->callController(
            AuthController::class,
            'passwordResetConfirm',
            [
                'token'               => $token,
                'new_password'        => 'NewValidPassword123!',
                'new_password_confirm' => 'NewValidPassword123!',
            ],
            httpMethod: 'POST',
        );

        $this->assertFailure($res);
        $this->assertStringContainsString('Zu viele', $res['error']);

        // Token must be deleted
        $row = $this->db->query("SELECT id FROM password_reset_tokens WHERE token_hash = '{$tokenHash}'")->fetch();
        $this->assertFalse($row);
    }

    public function test_password_reset_confirm_rejects_password_mismatch(): void
    {
        $token     = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expires   = date('Y-m-d H:i:s', time() + 3600);
        $this->db->prepare(
            'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (1, ?, ?)'
        )->execute([$tokenHash, $expires]);

        $res = $this->callController(
            AuthController::class,
            'passwordResetConfirm',
            [
                'token'               => $token,
                'new_password'        => 'NewValidPassword123!',
                'new_password_confirm' => 'DifferentPassword456!',
            ],
            httpMethod: 'POST',
        );

        $this->assertFailure($res);
    }

    // ── password rehash ────────────────────────────────────────────────────

    public function test_login_rehashes_bcrypt_password_to_argon2id(): void
    {
        // Insert a user with a bcrypt hash (simulates pre-migration account)
        $bcryptHash = password_hash('test-password', PASSWORD_BCRYPT, ['cost' => 4]);
        $this->db->prepare(
            "UPDATE users SET password_hash = ? WHERE email = 'admin@test.local'"
        )->execute([$bcryptHash]);

        $this->callController(
            AuthController::class,
            'login',
            ['email' => 'admin@test.local', 'password' => 'test-password'],
            httpMethod: 'POST'
        );

        $row = $this->db->query(
            "SELECT password_hash FROM users WHERE email = 'admin@test.local'"
        )->fetch();

        $this->assertStringStartsWith('$argon2id$', $row['password_hash']);
    }
}
