<?php

declare(strict_types=1);

namespace GsppManager\Middleware;

use GsppManager\Config\AppConfig;
use GsppManager\Config\Database;

class AuditLogger
{
    /**
     * Log a data-changing action
     *
     * @param string     $action     create|update|delete|export|login|logout
     * @param string     $entityType Table/entity name
     * @param int|null   $entityId   Primary key of affected row
     * @param array|null $changes    ['field' => ['old' => ..., 'new' => ...]]
     */
    public static function log(
        string $action,
        string $entityType,
        ?int $entityId = null,
        ?array $changes = null
    ): void {
        $tenantId = $_SESSION['tenant_id'] ?? null;
        $userId = $_SESSION['user_id'] ?? null;

        if ($tenantId === null) {
            return; // Can't log without tenant context
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            INSERT INTO audit_log
                (tenant_id, user_id, action, entity_type, entity_id, changes_json, ip_address, user_agent)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $tenantId,
            $userId,
            $action,
            $entityType,
            $entityId,
            $changes !== null ? json_encode($changes, JSON_UNESCAPED_UNICODE) : null,
            self::resolveClientIp(),
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        ]);
    }

    /**
     * Resolve the real client IP.
     * Trusts X-Forwarded-For only when TRUST_PROXY=true is set in the environment,
     * to prevent IP spoofing on direct-Internet deployments.
     */
    private static function resolveClientIp(): ?string
    {
        $trustProxy = filter_var(AppConfig::get('TRUST_PROXY', 'false'), FILTER_VALIDATE_BOOLEAN);
        if ($trustProxy && isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips     = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $firstIp = trim($ips[0]);
            if (filter_var($firstIp, FILTER_VALIDATE_IP)) {
                return $firstIp;
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? null;
    }

    /**
     * Compute diff between old and new values for audit log
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public static function diff(array $old, array $new, array $trackFields): array
    {
        $changes = [];
        foreach ($trackFields as $field) {
            $oldVal = $old[$field] ?? null;
            $newVal = $new[$field] ?? null;
            if ($oldVal !== $newVal) {
                $changes[$field] = ['old' => $oldVal, 'new' => $newVal];
            }
        }
        return $changes;
    }
}
