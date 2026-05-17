<?php

declare(strict_types=1);

namespace GsppManager\Middleware;

use GsppManager\Config\Database;

/**
 * DB-backed sliding-window rate limiter.
 *
 * Usage:
 *   if (!RateLimitMiddleware::check('login', $_SERVER['REMOTE_ADDR'] ?? '', 10, 60)) {
 *       http_response_code(429);
 *       echo json_encode(['success' => false, 'error' => 'Too many requests.']);
 *       exit;
 *   }
 */
class RateLimitMiddleware
{
    /**
     * Check and increment the rate-limit counter for a given action + client IP.
     * Returns true if the request is within the allowed rate, false if it should be rejected.
     *
     * @param string $action        Identifies the endpoint/action (e.g. 'login', 'ai_explain').
     * @param string $ip            Client IP address.
     * @param int    $maxHits       Maximum number of requests allowed in the window.
     * @param int    $windowSeconds Length of the sliding window in seconds.
     */
    public static function check(string $action, string $ip, int $maxHits, int $windowSeconds): bool
    {
        $bucket      = hash('sha256', $action . ':' . $ip);
        $windowStart = date('Y-m-d H:i:s', time() - $windowSeconds);
        $pdo         = Database::getConnection();

        // Count hits in the current window
        $stmt = $pdo->prepare(
            'SELECT id, hits FROM rate_limit_attempts WHERE bucket = ? AND window_start >= ? ORDER BY window_start DESC LIMIT 1'
        );
        $stmt->execute([$bucket, $windowStart]);
        $row = $stmt->fetch();

        if ($row === false) {
            // No record in window — create a fresh entry
            $pdo->prepare(
                'INSERT INTO rate_limit_attempts (bucket, window_start, hits) VALUES (?, NOW(), 1)'
            )->execute([$bucket]);
            return true;
        }

        if ((int) $row['hits'] >= $maxHits) {
            // Limit exceeded — do NOT increment further to avoid counter inflation
            return false;
        }

        // Increment existing record
        $pdo->prepare('UPDATE rate_limit_attempts SET hits = hits + 1 WHERE id = ?')
            ->execute([$row['id']]);

        return true;
    }

    /**
     * Prune expired rate-limit records (call periodically or from a cron job).
     *
     * @param int $maxAgeSeconds Records older than this are removed (default: 24 h).
     */
    public static function prune(int $maxAgeSeconds = 86400): void
    {
        $cutoff = date('Y-m-d H:i:s', time() - $maxAgeSeconds);
        Database::getConnection()
            ->prepare('DELETE FROM rate_limit_attempts WHERE window_start < ?')
            ->execute([$cutoff]);
    }
}
