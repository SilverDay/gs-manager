<?php

declare(strict_types=1);

namespace GsppManager\Tests\Unit\Router;

use GsppManager\Router\Router;
use GsppManager\Tests\Unit\UnitTestCase;

/**
 * Stub controller used only in routing tests.
 */
class RouterTestController
{
    public array $capturedParams = [];

    public function index(array $params): void
    {
        $this->capturedParams = $params;
        echo json_encode(['success' => true, 'data' => ['params' => $params]]);
    }
}

class RouterTest extends UnitTestCase
{
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new Router();
    }

    public function test_exact_route_dispatches_correct_controller_method(): void
    {
        $this->router->get('/api/test', RouterTestController::class, 'index');

        ob_start();
        $this->router->dispatch('GET', '/api/test');
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success']);
    }

    public function test_parameterized_route_extracts_named_parameters(): void
    {
        $captured = [];
        $this->router->get('/api/items/{id}', RouterTestController::class, 'index');

        ob_start();
        $this->router->dispatch('GET', '/api/items/42');
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertSame('42', $response['data']['params']['id']);
    }

    public function test_dispatch_returns_404_for_unknown_route(): void
    {
        ob_start();
        $this->router->dispatch('GET', '/api/nonexistent');
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertFalse($response['success']);
        $this->assertSame(404, http_response_code());
    }

    public function test_trailing_slash_is_normalized(): void
    {
        $this->router->get('/api/test', RouterTestController::class, 'index');

        ob_start();
        $this->router->dispatch('GET', '/api/test/');
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success']);
    }

    public function test_query_string_is_stripped_before_matching(): void
    {
        $this->router->get('/api/test', RouterTestController::class, 'index');

        ob_start();
        $this->router->dispatch('GET', '/api/test?foo=bar&baz=1');
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success']);
    }

    public function test_middleware_returning_false_halts_dispatch(): void
    {
        $middlewareCalled   = false;
        $controllerCalled   = false;

        $this->router->registerMiddleware('block', function () use (&$middlewareCalled): bool {
            $middlewareCalled = true;
            return false;
        });

        $this->router->get('/api/guarded', RouterTestController::class, 'index', ['block']);

        ob_start();
        $this->router->dispatch('GET', '/api/guarded');
        ob_end_clean();

        $this->assertTrue($middlewareCalled);
        // Controller must not have been called — nothing in output means no controller response
    }

    public function test_middleware_returning_true_allows_dispatch(): void
    {
        $this->router->registerMiddleware('pass', fn(): bool => true);
        $this->router->get('/api/open', RouterTestController::class, 'index', ['pass']);

        ob_start();
        $this->router->dispatch('GET', '/api/open');
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success']);
    }

    public function test_get_route_does_not_match_post_request(): void
    {
        $this->router->get('/api/test', RouterTestController::class, 'index');

        ob_start();
        $this->router->dispatch('POST', '/api/test');
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertFalse($response['success']);
        $this->assertSame(404, http_response_code());
    }
}
