<?php

declare(strict_types=1);

namespace GsppManager\Controller;

class BaseController
{
    /**
     * Send a JSON success response
     */
    protected function json(mixed $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        echo json_encode([
            'success' => true,
            'data'    => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Send a paginated JSON response
     */
    protected function paginated(array $items, int $total, int $page, int $perPage): void
    {
        $this->json([
            'items' => $items,
            'meta'  => [
                'total'     => $total,
                'page'      => $page,
                'per_page'  => $perPage,
                'last_page' => (int) ceil($total / max($perPage, 1)),
            ],
        ]);
    }

    /**
     * Send a JSON error response
     */
    protected function error(string $message, int $statusCode = 400, array $details = []): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        $payload = [
            'success' => false,
            'error'   => $message,
        ];
        if (!empty($details)) {
            $payload['details'] = $details;
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Parse JSON request body
     */
    protected function requestBody(): array
    {
        $raw = file_get_contents('php://input');
        if (empty($raw)) {
            return $_POST;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Get a query parameter with optional default
     */
    protected function queryParam(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    /**
     * Get pagination parameters from query string
     *
     * @return array{page: int, per_page: int, offset: int}
     */
    protected function pagination(): array
    {
        $page = max(1, (int) ($this->queryParam('page', 1)));
        $perPage = min(100, max(1, (int) ($this->queryParam('per_page', 25))));
        return [
            'page'     => $page,
            'per_page' => $perPage,
            'offset'   => ($page - 1) * $perPage,
        ];
    }

    /**
     * Validate required fields in request body
     *
     * @return string|null Error message or null if valid
     */
    protected function validateRequired(array $body, array $requiredFields): ?string
    {
        $missing = [];
        foreach ($requiredFields as $field) {
            if (!isset($body[$field]) || (is_string($body[$field]) && trim($body[$field]) === '')) {
                $missing[] = $field;
            }
        }
        if (!empty($missing)) {
            return 'Pflichtfelder fehlen: ' . implode(', ', $missing);
        }
        return null;
    }

    /**
     * Get current tenant ID from session
     */
    protected function tenantId(): int
    {
        return (int) ($_SESSION['tenant_id'] ?? 0);
    }

    /**
     * Get current user ID from session
     */
    protected function userId(): int
    {
        return (int) ($_SESSION['user_id'] ?? 0);
    }

    /**
     * Get current user role from session
     */
    protected function userRole(): string
    {
        return $_SESSION['user_role'] ?? '';
    }

    /**
     * Sanitise a string for use as a filename.
     * Replaces any character that is not alphanumeric, underscore, or hyphen with an underscore.
     */
    protected function safeFilename(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name) ?? 'export';
    }
}
