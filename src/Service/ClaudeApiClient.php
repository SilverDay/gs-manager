<?php

declare(strict_types=1);

namespace GsppManager\Service;

use RuntimeException;

class ClaudeApiClient implements AiClientInterface
{
    private const DEFAULT_MODEL = 'claude-haiku-4-5-20251001';
    private const API_URL       = 'https://api.anthropic.com/v1/messages';

    private int $lastTokenCount = 0;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = self::DEFAULT_MODEL
    ) {}

    public function complete(string $systemPrompt, string $userPrompt): string
    {
        $payload = json_encode([
            'model'      => $this->model,
            'max_tokens' => 4096,
            'system'     => $systemPrompt,
            'messages'   => [['role' => 'user', 'content' => $userPrompt]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            $detail = '';
            if ($response !== false) {
                $errData = json_decode((string) $response, true);
                $detail  = $errData['error']['message'] ?? '';
            }
            throw new RuntimeException(
                'Claude API: HTTP ' . $httpCode .
                ($detail ? ' — ' . $detail : '') .
                ($curlErr ? " ({$curlErr})" : '')
            );
        }

        $data = json_decode((string) $response, true);
        $this->lastTokenCount = (int) ($data['usage']['output_tokens'] ?? 0);

        return (string) ($data['content'][0]['text'] ?? '');
    }

    public function getProviderName(): string { return 'claude'; }
    public function getModelName(): string    { return $this->model; }
    public function getLastTokenCount(): int  { return $this->lastTokenCount; }
}
