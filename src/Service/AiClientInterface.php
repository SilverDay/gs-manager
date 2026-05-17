<?php

declare(strict_types=1);

namespace GsppManager\Service;

interface AiClientInterface
{
    public function complete(string $systemPrompt, string $userPrompt): string;
    public function getProviderName(): string;
    public function getModelName(): string;
    public function getLastTokenCount(): int;
}
