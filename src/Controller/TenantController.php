<?php

declare(strict_types=1);

namespace GsppManager\Controller;

use GsppManager\Config\Database;
use GsppManager\Middleware\AuditLogger;
use GsppManager\Security\PasswordHasher;

class TenantController extends BaseController
{
    // ── Guard ─────────────────────────────────────────────────────────────────

    private function requireSuperAdmin(): bool
    {
        if (empty($_SESSION['is_superadmin'])) {
            $this->error('Kein Zugriff. Nur Plattform-Administratoren dürfen Mandanten verwalten.', 403);
            return false;
        }
        return true;
    }

    // ── GET /api/superadmin/tenants ───────────────────────────────────────────

    public function list(array $params): void
    {
        if (!$this->requireSuperAdmin()) return;

        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT
                t.id, t.name, t.slug, t.is_active, t.created_at,
                COUNT(DISTINCT u.id)  AS user_count,
                COUNT(DISTINCT d.id)  AS domain_count
            FROM tenants t
            LEFT JOIN users u ON u.tenant_id = t.id
            LEFT JOIN information_domains d ON d.tenant_id = t.id
            GROUP BY t.id
            ORDER BY t.created_at DESC
        ");
        $stmt->execute([]);
        $rows = $stmt->fetchAll();

        $this->json(['tenants' => array_map(fn($r) => [
            'id'           => (int) $r['id'],
            'name'         => $r['name'],
            'slug'         => $r['slug'],
            'is_active'    => (bool) $r['is_active'],
            'user_count'   => (int) $r['user_count'],
            'domain_count' => (int) $r['domain_count'],
            'created_at'   => $r['created_at'],
        ], $rows)]);
    }

    // ── POST /api/superadmin/tenants ──────────────────────────────────────────

    public function create(array $params): void
    {
        if (!$this->requireSuperAdmin()) return;

        $body = $this->requestBody();
        $err  = $this->validateRequired($body, ['name', 'slug']);
        if ($err !== null) {
            $this->error($err, 422);
            return;
        }

        $name = trim($body['name']);
        $slug = trim($body['slug']);

        if (!preg_match('/^[a-z0-9\-]{2,100}$/', $slug)) {
            $this->error('Slug darf nur Kleinbuchstaben, Ziffern und Bindestriche enthalten (2–100 Zeichen).', 422);
            return;
        }

        $pdo = Database::getConnection();

        $chk = $pdo->prepare('SELECT id FROM tenants WHERE slug = ?');
        $chk->execute([$slug]);
        if ($chk->fetch() !== false) {
            $this->error('Dieser Slug ist bereits vergeben.', 409);
            return;
        }

        $pdo->prepare('INSERT INTO tenants (name, slug, is_active, settings_json) VALUES (?, ?, TRUE, ?)')
            ->execute([$name, $slug, json_encode(['language' => 'de', 'timezone' => 'Europe/Berlin'])]);

        $tenantId = (int) $pdo->lastInsertId();

        AuditLogger::log('superadmin.tenant_created', 'tenants', $tenantId, ['name' => $name, 'slug' => $slug]);

        // Optionally create first admin user
        $adminEmail    = trim($body['admin_email']    ?? '');
        $adminName     = trim($body['admin_name']     ?? '');
        $adminPassword = $body['admin_password'] ?? '';

        if ($adminEmail !== '') {
            if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                $this->error('Ungültige Admin-E-Mail-Adresse.', 422);
                return;
            }
            if (strlen($adminPassword) < 12) {
                $this->error('Admin-Passwort muss mindestens 12 Zeichen lang sein.', 422);
                return;
            }
            $hash = PasswordHasher::hash($adminPassword);
            $pdo->prepare('
                INSERT INTO users (tenant_id, email, password_hash, display_name, role, is_active)
                VALUES (?, ?, ?, ?, \'admin\', TRUE)
            ')->execute([$tenantId, $adminEmail, $hash, $adminName ?: $adminEmail]);

            AuditLogger::log('superadmin.tenant_admin_created', 'users', (int) $pdo->lastInsertId(), [
                'tenant_id' => $tenantId,
                'email'     => $adminEmail,
            ]);
        }

        $this->json(['tenant_id' => $tenantId, 'message' => 'Mandant angelegt.'], 201);
    }

    // ── PUT /api/superadmin/tenants/{id} ──────────────────────────────────────

    public function update(array $params): void
    {
        if (!$this->requireSuperAdmin()) return;

        $id  = (int) ($params['id'] ?? 0);
        $pdo = Database::getConnection();

        $chk = $pdo->prepare('SELECT id FROM tenants WHERE id = ?');
        $chk->execute([$id]);
        if ($chk->fetch() === false) {
            $this->error('Mandant nicht gefunden.', 404);
            return;
        }

        $body   = $this->requestBody();
        $fields = [];
        $binds  = [];

        if (isset($body['name'])) {
            $fields[] = 'name = ?';
            $binds[]  = trim($body['name']);
        }

        if (isset($body['slug'])) {
            $slug = trim($body['slug']);
            if (!preg_match('/^[a-z0-9\-]{2,100}$/', $slug)) {
                $this->error('Ungültiger Slug.', 422);
                return;
            }
            $dup = $pdo->prepare('SELECT id FROM tenants WHERE slug = ? AND id != ?');
            $dup->execute([$slug, $id]);
            if ($dup->fetch() !== false) {
                $this->error('Dieser Slug ist bereits vergeben.', 409);
                return;
            }
            $fields[] = 'slug = ?';
            $binds[]  = $slug;
        }

        if (array_key_exists('is_active', $body)) {
            $fields[] = 'is_active = ?';
            $binds[]  = (bool) $body['is_active'] ? 1 : 0;
        }

        if (empty($fields)) {
            $this->error('Keine Änderung angegeben.', 422);
            return;
        }

        $binds[] = $id;
        $pdo->prepare('UPDATE tenants SET ' . implode(', ', $fields) . ' WHERE id = ?')
            ->execute($binds);

        AuditLogger::log('superadmin.tenant_updated', 'tenants', $id);

        $this->json(['message' => 'Mandant aktualisiert.']);
    }

    // ── GET /api/superadmin/tenants/{id}/users ────────────────────────────────

    public function listUsers(array $params): void
    {
        if (!$this->requireSuperAdmin()) return;

        $id  = (int) ($params['id'] ?? 0);
        $pdo = Database::getConnection();

        $chk = $pdo->prepare('SELECT id FROM tenants WHERE id = ?');
        $chk->execute([$id]);
        if ($chk->fetch() === false) {
            $this->error('Mandant nicht gefunden.', 404);
            return;
        }

        $stmt = $pdo->prepare('
            SELECT id, email, display_name, role, is_active, is_superadmin, last_login_at, created_at
            FROM users
            WHERE tenant_id = ?
            ORDER BY display_name
        ');
        $stmt->execute([$id]);
        $users = $stmt->fetchAll();

        $this->json(['users' => array_map(fn($u) => [
            'id'            => (int) $u['id'],
            'email'         => $u['email'],
            'display_name'  => $u['display_name'],
            'role'          => $u['role'],
            'is_active'     => (bool) $u['is_active'],
            'is_superadmin' => (bool) $u['is_superadmin'],
            'last_login_at' => $u['last_login_at'],
            'created_at'    => $u['created_at'],
        ], $users)]);
    }

    // ── POST /api/superadmin/tenants/{id}/users ───────────────────────────────

    public function createUser(array $params): void
    {
        if (!$this->requireSuperAdmin()) return;

        $tenantId = (int) ($params['id'] ?? 0);
        $pdo      = Database::getConnection();

        $chk = $pdo->prepare('SELECT id FROM tenants WHERE id = ?');
        $chk->execute([$tenantId]);
        if ($chk->fetch() === false) {
            $this->error('Mandant nicht gefunden.', 404);
            return;
        }

        $body = $this->requestBody();
        $err  = $this->validateRequired($body, ['email', 'display_name', 'role', 'password']);
        if ($err !== null) {
            $this->error($err, 422);
            return;
        }

        $email    = trim($body['email']);
        $name     = trim($body['display_name']);
        $role     = $body['role'];
        $password = $body['password'];

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Ungültige E-Mail-Adresse.', 422);
            return;
        }

        $validRoles = ['admin', 'isb', 'fachverantwortlich', 'auditor', 'management', 'readonly'];
        if (!in_array($role, $validRoles, true)) {
            $this->error('Ungültige Rolle.', 422);
            return;
        }

        if (strlen($password) < 12) {
            $this->error('Passwort muss mindestens 12 Zeichen lang sein.', 422);
            return;
        }

        $dup = $pdo->prepare('SELECT id FROM users WHERE email = ? AND tenant_id = ?');
        $dup->execute([$email, $tenantId]);
        if ($dup->fetch() !== false) {
            $this->error('Diese E-Mail-Adresse ist in diesem Mandanten bereits vergeben.', 409);
            return;
        }

        $hash = PasswordHasher::hash($password);
        $pdo->prepare('
            INSERT INTO users (tenant_id, email, password_hash, display_name, role, is_active)
            VALUES (?, ?, ?, ?, ?, TRUE)
        ')->execute([$tenantId, $email, $hash, $name, $role]);

        $userId = (int) $pdo->lastInsertId();
        AuditLogger::log('superadmin.user_created', 'users', $userId, ['tenant_id' => $tenantId, 'email' => $email]);

        $this->json(['user_id' => $userId, 'message' => 'Benutzer angelegt.'], 201);
    }

    // ── PUT /api/superadmin/users/{userId} ────────────────────────────────────
    // (update a user in any tenant — role, is_active, is_superadmin, password)

    public function updateUser(array $params): void
    {
        if (!$this->requireSuperAdmin()) return;

        $userId = (int) ($params['userId'] ?? 0);
        $pdo    = Database::getConnection();

        $chk = $pdo->prepare('SELECT id, role FROM users WHERE id = ?');
        $chk->execute([$userId]);
        $existing = $chk->fetch();
        if ($existing === false) {
            $this->error('Benutzer nicht gefunden.', 404);
            return;
        }

        $body   = $this->requestBody();
        $fields = [];
        $binds  = [];

        if (isset($body['display_name'])) {
            $fields[] = 'display_name = ?';
            $binds[]  = trim($body['display_name']);
        }

        if (isset($body['role'])) {
            $validRoles = ['admin', 'isb', 'fachverantwortlich', 'auditor', 'management', 'readonly'];
            if (!in_array($body['role'], $validRoles, true)) {
                $this->error('Ungültige Rolle.', 422);
                return;
            }
            $fields[] = 'role = ?';
            $binds[]  = $body['role'];
        }

        if (array_key_exists('is_active', $body)) {
            $fields[] = 'is_active = ?';
            $binds[]  = (bool) $body['is_active'] ? 1 : 0;
        }

        if (array_key_exists('is_superadmin', $body)) {
            $fields[] = 'is_superadmin = ?';
            $binds[]  = (bool) $body['is_superadmin'] ? 1 : 0;
        }

        if (!empty($body['password'])) {
            if (strlen($body['password']) < 12) {
                $this->error('Passwort muss mindestens 12 Zeichen lang sein.', 422);
                return;
            }
            $fields[] = 'password_hash = ?';
            $binds[]  = PasswordHasher::hash($body['password']);
        }

        if (empty($fields)) {
            $this->error('Keine Änderung angegeben.', 422);
            return;
        }

        $binds[] = $userId;
        $pdo->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')
            ->execute($binds);

        AuditLogger::log('superadmin.user_updated', 'users', $userId);

        $this->json(['message' => 'Benutzer aktualisiert.']);
    }
}
