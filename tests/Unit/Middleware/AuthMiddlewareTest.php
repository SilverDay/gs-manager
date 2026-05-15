<?php

declare(strict_types=1);

namespace GsppManager\Tests\Unit\Middleware;

use GsppManager\Middleware\AuthMiddleware;
use GsppManager\Tests\Unit\UnitTestCase;

class AuthMiddlewareTest extends UnitTestCase
{
    public function test_handle_returns_false_without_session(): void
    {
        ob_start();
        $result = AuthMiddleware::handle();
        ob_end_clean();

        $this->assertFalse($result);
    }

    public function test_handle_returns_true_with_valid_session(): void
    {
        $_SESSION['user_id']       = 1;
        $_SESSION['tenant_id']     = 1;
        $_SESSION['last_activity'] = time();
        $_ENV['SESSION_LIFETIME']  = '30';

        $this->assertTrue(AuthMiddleware::handle());
    }

    public function test_handle_returns_false_when_session_expired(): void
    {
        $_SESSION['user_id']       = 1;
        $_SESSION['tenant_id']     = 1;
        $_SESSION['last_activity'] = time() - 3600; // 1 hour ago
        $_ENV['SESSION_LIFETIME']  = '30';           // 30-minute window

        ob_start();
        $result = AuthMiddleware::handle();
        ob_end_clean();

        $this->assertFalse($result);
    }

    public function test_handle_updates_last_activity_on_valid_request(): void
    {
        $before = time() - 5;
        $_SESSION['user_id']       = 1;
        $_SESSION['tenant_id']     = 1;
        $_SESSION['last_activity'] = $before;
        $_ENV['SESSION_LIFETIME']  = '30';

        AuthMiddleware::handle();

        $this->assertGreaterThan($before, $_SESSION['last_activity']);
    }

    public function test_require_role_returns_true_for_allowed_role(): void
    {
        $_SESSION['user_role'] = 'admin';

        $this->assertTrue(AuthMiddleware::requireRole(['admin', 'isb']));
    }

    public function test_require_role_returns_false_for_disallowed_role(): void
    {
        $_SESSION['user_role'] = 'readonly';

        ob_start();
        $result = AuthMiddleware::requireRole(['admin', 'isb']);
        ob_end_clean();

        $this->assertFalse($result);
    }

    public function test_current_user_id_returns_null_without_session(): void
    {
        $this->assertNull(AuthMiddleware::currentUserId());
    }

    public function test_current_user_id_returns_int_with_session(): void
    {
        $_SESSION['user_id'] = 42;

        $this->assertSame(42, AuthMiddleware::currentUserId());
    }

    public function test_current_role_returns_null_without_session(): void
    {
        $this->assertNull(AuthMiddleware::currentRole());
    }
}
