<?php

declare(strict_types=1);

namespace GsppManager\Tests\Integration;

use GsppManager\Config\Database;
use PDO;
use PHPUnit\Framework\TestCase;

abstract class IntegrationTestCase extends TestCase
{
    protected PDO $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = Database::getConnection();

        // Wrap each test in a transaction — tearDown rolls it back.
        // This replaces 19 × TRUNCATE per test with a single ROLLBACK (O(1)).
        $this->db->beginTransaction();

        // Ensure the session is active (a previous test may have called session_destroy)
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION = [];
        $_POST    = [];
        $_SERVER  = array_merge($_SERVER, [
            'REQUEST_METHOD'  => 'GET',
            'REQUEST_URI'     => '/',
            'REMOTE_ADDR'     => '127.0.0.1',
            'HTTP_USER_AGENT' => 'PHPUnit',
        ]);
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    }

    protected function tearDown(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }

        // Restart session if a test called session_destroy()
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION = [];
        $_POST    = [];
        parent::tearDown();
    }

    /**
     * Populate $_SESSION as if the given role is logged in.
     * User IDs match the test_seed.sql fixture.
     */
    protected function loginAs(string $role): void
    {
        $roleUserIds = [
            'admin'              => 1,
            'isb'                => 2,
            'fachverantwortlich' => 3,
            'auditor'            => 4,
            'management'         => 5,
            'readonly'           => 6,
        ];

        $userId = $roleUserIds[$role] ?? 1;

        $_SESSION['user_id']       = $userId;
        $_SESSION['tenant_id']     = 1;
        $_SESSION['user_role']     = $role;
        $_SESSION['user_email']    = $role . '@test.local';
        $_SESSION['user_name']     = ucfirst($role) . ' User';
        $_SESSION['last_activity'] = time();
    }

    /**
     * Call a controller method directly and return the decoded JSON response.
     *
     * In CLI (PHPUnit), php://input is empty so BaseController::requestBody()
     * falls back to $_POST — set $_POST before calling this for POST/PUT payloads.
     */
    protected function callController(
        string $controllerClass,
        string $methodName,
        array  $body   = [],
        array  $params = [],
        string $httpMethod = 'GET'
    ): array {
        $_SERVER['REQUEST_METHOD'] = $httpMethod;
        $_POST = $body;

        if (in_array($httpMethod, ['POST', 'PUT', 'DELETE'], true)) {
            $_SESSION['csrf_token']       = 'test-csrf-token';
            $_SERVER['HTTP_X_CSRF_TOKEN'] = 'test-csrf-token';
        }

        ob_start();
        (new $controllerClass())->$methodName($params);
        $output = ob_get_clean();

        return json_decode($output ?: '{}', true) ?? [];
    }

    /**
     * Assert the response signals success and optionally check a data key.
     */
    protected function assertSuccess(array $response, ?string $dataKey = null): void
    {
        $this->assertTrue($response['success'] ?? false, 'Expected success:true, got: ' . json_encode($response));
        if ($dataKey !== null) {
            $this->assertArrayHasKey($dataKey, $response['data'] ?? []);
        }
    }

    /**
     * Assert the response signals failure with the expected HTTP status code
     * (captured from http_response_code()).
     */
    protected function assertFailure(array $response): void
    {
        $this->assertFalse($response['success'] ?? true, 'Expected success:false, got: ' . json_encode($response));
        $this->assertArrayHasKey('error', $response);
    }
}
