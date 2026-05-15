<?php

declare(strict_types=1);

namespace GsppManager\Tests\Unit\Middleware;

use GsppManager\Middleware\CsrfMiddleware;
use GsppManager\Tests\Unit\UnitTestCase;

class CsrfMiddlewareTest extends UnitTestCase
{
    public function test_generate_token_creates_64_char_hex_string(): void
    {
        $token = CsrfMiddleware::generateToken();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function test_generate_token_returns_same_token_when_session_already_set(): void
    {
        $first  = CsrfMiddleware::generateToken();
        $second = CsrfMiddleware::generateToken();

        $this->assertSame($first, $second);
    }

    public function test_rotate_token_returns_new_token(): void
    {
        $original = CsrfMiddleware::generateToken();
        $rotated  = CsrfMiddleware::rotateToken();

        $this->assertNotSame($original, $rotated);
        $this->assertSame($rotated, $_SESSION['csrf_token']);
    }

    public function test_handle_passes_get_request_without_token(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->assertTrue(CsrfMiddleware::handle());
    }

    public function test_handle_passes_head_request_without_token(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'HEAD';

        $this->assertTrue(CsrfMiddleware::handle());
    }

    public function test_handle_returns_false_for_post_without_token(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SESSION['csrf_token']    = 'valid-token';
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);

        ob_start();
        $result = CsrfMiddleware::handle();
        ob_end_clean();

        $this->assertFalse($result);
    }

    public function test_handle_returns_false_for_post_with_wrong_token(): void
    {
        $_SERVER['REQUEST_METHOD']    = 'POST';
        $_SESSION['csrf_token']       = 'correct-token';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'wrong-token';

        ob_start();
        $result = CsrfMiddleware::handle();
        ob_end_clean();

        $this->assertFalse($result);
    }

    public function test_handle_returns_true_for_post_with_correct_header_token(): void
    {
        $_SERVER['REQUEST_METHOD']    = 'POST';
        $_SESSION['csrf_token']       = 'correct-token';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'correct-token';

        $this->assertTrue(CsrfMiddleware::handle());
    }

    public function test_handle_returns_true_for_post_with_correct_form_token(): void
    {
        $_SERVER['REQUEST_METHOD']    = 'POST';
        $_SESSION['csrf_token']       = 'correct-token';
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
        $_POST['_csrf_token']         = 'correct-token';

        $this->assertTrue(CsrfMiddleware::handle());

        unset($_POST['_csrf_token']);
    }

    public function test_handle_returns_false_when_no_session_token_exists(): void
    {
        $_SERVER['REQUEST_METHOD']    = 'POST';
        unset($_SESSION['csrf_token']);
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'any-token';

        ob_start();
        $result = CsrfMiddleware::handle();
        ob_end_clean();

        $this->assertFalse($result);
    }
}
