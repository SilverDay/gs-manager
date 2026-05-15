<?php

declare(strict_types=1);

namespace GsppManager\Router;

class Router
{
    /** @var array<string, array<string, array{controller: string, method: string, middleware: string[]}>> */
    private array $routes = [];

    /** @var array<string, callable> */
    private array $middleware = [];

    public function registerMiddleware(string $name, callable $handler): void
    {
        $this->middleware[$name] = $handler;
    }

    public function get(string $path, string $controller, string $method, array $middleware = []): void
    {
        $this->addRoute('GET', $path, $controller, $method, $middleware);
    }

    public function post(string $path, string $controller, string $method, array $middleware = []): void
    {
        $this->addRoute('POST', $path, $controller, $method, $middleware);
    }

    public function put(string $path, string $controller, string $method, array $middleware = []): void
    {
        $this->addRoute('PUT', $path, $controller, $method, $middleware);
    }

    public function delete(string $path, string $controller, string $method, array $middleware = []): void
    {
        $this->addRoute('DELETE', $path, $controller, $method, $middleware);
    }

    private function addRoute(string $httpMethod, string $path, string $controller, string $method, array $middleware): void
    {
        $this->routes[$httpMethod][$path] = [
            'controller' => $controller,
            'method'     => $method,
            'middleware'  => $middleware,
        ];
    }

    public function dispatch(string $httpMethod, string $uri): void
    {
        // Strip query string
        $path = parse_url($uri, PHP_URL_PATH);
        $path = rtrim($path, '/') ?: '/';

        // Find matching route (exact match first, then parameterized)
        $route = null;
        $params = [];

        if (isset($this->routes[$httpMethod][$path])) {
            $route = $this->routes[$httpMethod][$path];
        } else {
            // Check parameterized routes
            foreach ($this->routes[$httpMethod] ?? [] as $pattern => $routeConfig) {
                $regex = $this->patternToRegex($pattern);
                if (preg_match($regex, $path, $matches)) {
                    $route = $routeConfig;
                    $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                    break;
                }
            }
        }

        if ($route === null) {
            $this->sendJson(404, ['success' => false, 'error' => 'Route not found']);
            return;
        }

        // Run middleware
        foreach ($route['middleware'] as $middlewareName) {
            if (isset($this->middleware[$middlewareName])) {
                $result = ($this->middleware[$middlewareName])();
                if ($result === false) {
                    return; // Middleware halted the request (already sent response)
                }
            }
        }

        // Resolve controller
        $controllerClass = $route['controller'];
        $methodName = $route['method'];

        if (!class_exists($controllerClass)) {
            $this->sendJson(500, ['success' => false, 'error' => 'Controller not found']);
            return;
        }

        $controller = new $controllerClass();

        if (!method_exists($controller, $methodName)) {
            $this->sendJson(500, ['success' => false, 'error' => 'Method not found']);
            return;
        }

        // Call controller method with route params
        $controller->$methodName($params);
    }

    private function patternToRegex(string $pattern): string
    {
        // Convert {param} to named capture groups
        $regex = preg_replace('/\{([a-zA-Z_]+)\}/', '(?P<$1>[^/]+)', $pattern);
        return '#^' . $regex . '$#';
    }

    private function sendJson(int $statusCode, array $data): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}
