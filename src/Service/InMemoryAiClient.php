<?php

declare(strict_types=1);

namespace GsppManager\Service;

class InMemoryAiClient implements AiClientInterface
{
    public array $calls = [];

    public function __construct(private readonly string $stubResponse = 'KI-Antwort (Test-Stub)') {}

    public function complete(string $systemPrompt, string $userPrompt): string
    {
        $this->calls[] = compact('systemPrompt', 'userPrompt');
        return $this->stubResponse;
    }

    public function getProviderName(): string { return 'memory'; }
    public function getModelName(): string    { return 'stub'; }
    public function getLastTokenCount(): int  { return 0; }
}
