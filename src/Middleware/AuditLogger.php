<?php

declare(strict_types=1);

namespace GsppManager\Middleware;

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
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        ]);
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
