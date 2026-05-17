<?php

declare(strict_types=1);

namespace GsppManager\Tests\Unit\Service;

use GsppManager\Repository\AiCacheRepository;
use GsppManager\Service\InMemoryAiClient;
use GsppManager\Tests\Unit\UnitTestCase;

class AiClientTest extends UnitTestCase
{
    // ── InMemoryAiClient ─────────────────────────────────────────

    public function test_inmemory_client_returns_stub_response(): void
    {
        $client = new InMemoryAiClient('Test-KI-Antwort');
        $result = $client->complete('System-Prompt', 'User-Prompt');
        $this->assertSame('Test-KI-Antwort', $result);
    }

    public function test_inmemory_client_records_calls(): void
    {
        $client = new InMemoryAiClient();
        $client->complete('sys', 'user prompt');
        $this->assertCount(1, $client->calls);
        $this->assertSame('sys',         $client->calls[0]['systemPrompt']);
        $this->assertSame('user prompt', $client->calls[0]['userPrompt']);
    }

    public function test_inmemory_client_metadata(): void
    {
        $client = new InMemoryAiClient();
        $this->assertSame('memory', $client->getProviderName());
        $this->assertSame('stub',   $client->getModelName());
        $this->assertSame(0,        $client->getLastTokenCount());
    }

    // ── AiCacheRepository::buildKey ──────────────────────────────

    public function test_cache_key_is_deterministic(): void
    {
        $key1 = AiCacheRepository::buildKey('explain', ['control_id' => 'PERS.3.1', 'control_title' => 'Test']);
        $key2 = AiCacheRepository::buildKey('explain', ['control_id' => 'PERS.3.1', 'control_title' => 'Test']);
        $this->assertSame($key1, $key2);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key1);
    }

    public function test_cache_key_collision_resistance(): void
    {
        $key1 = AiCacheRepository::buildKey('explain', ['control_id' => 'PERS.3.1']);
        $key2 = AiCacheRepository::buildKey('explain', ['control_id' => 'PERS.3.2']);
        $key3 = AiCacheRepository::buildKey('risk',    ['control_id' => 'PERS.3.1']);
        $this->assertNotSame($key1, $key2, 'Different context must produce different key');
        $this->assertNotSame($key1, $key3, 'Different prompt type must produce different key');
    }

    public function test_cache_key_is_order_independent(): void
    {
        $key1 = AiCacheRepository::buildKey('explain', ['a' => '1', 'b' => '2']);
        $key2 = AiCacheRepository::buildKey('explain', ['b' => '2', 'a' => '1']);
        $this->assertSame($key1, $key2, 'Key must be identical regardless of array key order');
    }
}
