<?php

declare(strict_types=1);

namespace GsppManager\Service;

use RuntimeException;

class GeminiApiClient implements AiClientInterface
{
    private const DEFAULT_MODEL = 'gemini-2.5-flash';
    private const API_BASE      = 'https://generativelanguage.googleapis.com/v1beta/models/';

    private int $lastTokenCount = 0;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = self::DEFAULT_MODEL
    ) {}

    public function complete(string $systemPrompt, string $userPrompt): string
    {
        // Gemini does not have a separate system role — prepend system to user turn
        $combinedPrompt = $systemPrompt . "\n\n" . $userPrompt;

        $payload = json_encode([
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $combinedPrompt]]],
            ],
            'generationConfig' => ['maxOutputTokens' => 4096],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $url = self::API_BASE . urlencode($this->model) . ':generateContent?key=' . urlencode($this->apiKey);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
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
                'Gemini API: HTTP ' . $httpCode .
                ($detail ? ' — ' . $detail : '') .
                ($curlErr ? " ({$curlErr})" : '')
            );
        }

        $data = json_decode((string) $response, true);
        $this->lastTokenCount = (int) ($data['usageMetadata']['candidatesTokenCount'] ?? 0);

        return (string) ($data['candidates'][0]['content']['parts'][0]['text'] ?? '');
    }

    public function getProviderName(): string { return 'gemini'; }
    public function getModelName(): string    { return $this->model; }
    public function getLastTokenCount(): int  { return $this->lastTokenCount; }
}
