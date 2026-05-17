<?php

declare(strict_types=1);

namespace GsppManager\Tests\Integration\Api;

use GsppManager\Controller\AiController;
use GsppManager\Service\InMemoryAiClient;
use GsppManager\Tests\Integration\IntegrationTestCase;

class AiControllerTest extends IntegrationTestCase
{
    private InMemoryAiClient $stubClient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stubClient = new InMemoryAiClient('KI-Antwort (Test-Stub)');
        AiController::$testClientOverride = $this->stubClient;
    }

    protected function tearDown(): void
    {
        AiController::$testClientOverride = null;
        parent::tearDown();
    }

    // ── Permission guards ────────────────────────────────────────

    public function test_management_gets_403_on_explain(): void
    {
        $this->loginAs('management');
        $res = $this->callController(
            AiController::class,
            'explain',
            ['control_id' => 'PERS.3.1', 'control_title' => 'Test', 'description' => 'Desc'],
            [],
            'POST'
        );

        $this->assertFailure($res);
        $this->assertSame(403, http_response_code());
    }

    public function test_readonly_gets_403_on_explain(): void
    {
        $this->loginAs('readonly');
        $res = $this->callController(
            AiController::class,
            'explain',
            ['control_id' => 'PERS.3.1', 'control_title' => 'Test', 'description' => 'Desc'],
            [],
            'POST'
        );

        $this->assertFailure($res);
        $this->assertSame(403, http_response_code());
    }

    // ── Happy path ───────────────────────────────────────────────

    public function test_isb_gets_ai_response(): void
    {
        $this->loginAs('isb');
        $res = $this->callController(
            AiController::class,
            'explain',
            ['control_id' => 'PERS.3.1', 'control_title' => 'Sicherheitsschulung', 'description' => 'Regelmäßige Schulungen.'],
            [],
            'POST'
        );

        $this->assertSuccess($res);
        $this->assertSame('KI-Antwort (Test-Stub)', $res['data']['response']);
        $this->assertFalse($res['data']['cached']);
    }

    public function test_auditor_can_use_ai(): void
    {
        $this->loginAs('auditor');
        $res = $this->callController(
            AiController::class,
            'riskAnalysis',
            ['control_id' => 'PERS.3.1', 'control_title' => 'Test', 'description' => 'Desc'],
            [],
            'POST'
        );

        $this->assertSuccess($res);
    }

    // ── Validation ───────────────────────────────────────────────

    public function test_missing_required_fields_returns_422(): void
    {
        $this->loginAs('isb');
        $res = $this->callController(
            AiController::class,
            'explain',
            ['control_id' => 'PERS.3.1'],  // missing control_title and description
            [],
            'POST'
        );

        $this->assertFailure($res);
        $this->assertSame(422, http_response_code());
    }

    // ── Cache hit ────────────────────────────────────────────────

    public function test_cache_hit_skips_client_call(): void
    {
        $this->loginAs('isb');

        $payload = ['control_id' => 'BES.7.1', 'control_title' => 'Zugangskontrolle', 'description' => 'Zugang beschränken.'];

        // First call — should hit the AI client
        $res1 = $this->callController(AiController::class, 'explain', $payload, [], 'POST');
        $this->assertSuccess($res1);
        $this->assertFalse($res1['data']['cached']);
        $callsAfterFirst = count($this->stubClient->calls);

        // Second call — same payload, same tenant — should be served from cache
        $res2 = $this->callController(AiController::class, 'explain', $payload, [], 'POST');
        $this->assertSuccess($res2);
        $this->assertTrue($res2['data']['cached'], 'Second call must be served from cache');

        // The AI client must not have been called a second time
        $this->assertCount($callsAfterFirst, $this->stubClient->calls, 'Cache hit must not invoke AI client');
    }
}
