<?php

declare(strict_types=1);

namespace GsppManager\Repository;

use GsppManager\Config\Database;
use PDO;

class AiCacheRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    public static function buildKey(string $promptType, array $context): string
    {
        ksort($context);
        return hash('sha256', $promptType . ':' . json_encode($context, JSON_UNESCAPED_UNICODE));
    }

    public function get(string $cacheKey, int $tenantId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT response_text FROM ai_cache WHERE tenant_id = ? AND cache_key = ? AND expires_at > NOW()'
        );
        $stmt->execute([$tenantId, $cacheKey]);
        $row = $stmt->fetch();
        return $row ? (string) $row['response_text'] : null;
    }

    public function store(
        string $cacheKey,
        int    $tenantId,
        string $responseText,
        string $promptType,
        string $provider,
        string $model,
        int    $tokens,
        int    $ttlDays = 30
    ): void {
        // INSERT IGNORE — UNIQUE(tenant_id, cache_key) prevents duplicate writes on race
        $stmt = $this->pdo->prepare('
            INSERT IGNORE INTO ai_cache
                (tenant_id, cache_key, prompt_type, response_text, provider, model, tokens_used, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))
        ');
        $stmt->execute([$tenantId, $cacheKey, $promptType, $responseText, $provider, $model, $tokens, $ttlDays]);
    }

    /**
     * Remove all expired cache entries.
     * Call periodically (e.g. from a cron job or the notify-deadlines script).
     */
    public function prune(): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM ai_cache WHERE expires_at <= NOW()');
        $stmt->execute();
        return (int) $stmt->rowCount();
    }
}
