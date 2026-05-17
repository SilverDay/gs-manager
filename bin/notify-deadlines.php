#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * GS++ Manager — POA&M Deadline Reminder CLI Script
 *
 * Sends email notifications for POA&M items whose deadline is within the next
 * $lookAheadDays days and whose assigned user has not yet been notified today.
 *
 * Usage (run from project root or via cron):
 *   php bin/notify-deadlines.php [--dry-run]
 *
 * Recommended cron entry (daily at 07:00):
 *   0 7 * * * /usr/bin/php /srv/vhosts/gsm.silverday.de/bin/notify-deadlines.php >> /var/log/gsm-notify.log 2>&1
 */

$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';

use GsppManager\Config\AppConfig;
use GsppManager\Config\Database;
use GsppManager\Repository\AiCacheRepository;
use GsppManager\Service\MailService;
use GsppManager\Service\NotificationService;

AppConfig::load($projectRoot);

$isDryRun     = in_array('--dry-run', $argv ?? [], true);
$lookAheadDays = (int) AppConfig::get('DEADLINE_LOOKAHEAD_DAYS', '7');
$today        = date('Y-m-d');
$horizon      = date('Y-m-d', strtotime("+{$lookAheadDays} days"));

echo "[" . date('Y-m-d H:i:s') . "] notify-deadlines starting (dry-run=" . ($isDryRun ? 'yes' : 'no') . ")\n";

$pdo = Database::getConnection();

// Load all tenants with SMTP configured
$tenantStmt = $pdo->prepare("
    SELECT id, name, settings_json
    FROM tenants
    WHERE is_active = 1
");
$tenantStmt->execute();
$tenants = $tenantStmt->fetchAll(\PDO::FETCH_ASSOC);

$totalNotified = 0;

foreach ($tenants as $tenant) {
    $tenantId = (int) $tenant['id'];
    $settings = json_decode($tenant['settings_json'] ?? '{}', true) ?? [];

    if (empty($settings['smtp_host'])) {
        continue; // No SMTP — skip tenant
    }

    // Find overdue + upcoming POA&M items with an assigned user
    $poamStmt = $pdo->prepare("
        SELECT pi.id, pi.title, pi.deadline, pi.status, pi.domain_id,
               u.id AS user_id, u.email AS user_email, u.display_name AS user_name,
               d.name AS domain_name
        FROM poam_items pi
        JOIN information_domains d ON d.id = pi.domain_id AND d.tenant_id = ?
        JOIN users u ON u.id = pi.assigned_to AND u.is_active = 1 AND u.tenant_id = ?
        WHERE pi.status NOT IN ('completed', 'verified', 'accepted')
          AND pi.deadline IS NOT NULL
          AND pi.deadline <= ?
          AND pi.deadline >= ?
        ORDER BY pi.deadline ASC
    ");
    $poamStmt->execute([$tenantId, $tenantId, $horizon, $today]);
    $items = $poamStmt->fetchAll(\PDO::FETCH_ASSOC);

    if (empty($items)) {
        continue;
    }

    // Group by user
    $byUser = [];
    foreach ($items as $item) {
        $byUser[$item['user_id']][] = $item;
    }

    foreach ($byUser as $userId => $userItems) {
        $firstItem   = $userItems[0];
        $userEmail   = $firstItem['user_email'];
        $userName    = $firstItem['user_name'];
        $itemCount   = count($userItems);
        $overdueCount = 0;

        $lines = [];
        foreach ($userItems as $item) {
            $daysLeft = (int) round((strtotime($item['deadline']) - time()) / 86400);
            $tag      = $daysLeft < 0 ? ' [ÜBERFÄLLIG]' : " (in {$daysLeft} Tag(en))";
            if ($daysLeft < 0) {
                $overdueCount++;
            }
            $lines[] = "  - [{$item['domain_name']}] {$item['title']} — Frist: {$item['deadline']}{$tag}";
        }

        $subject = $overdueCount > 0
            ? "[GS++ Manager] {$overdueCount} überfällige POA&M-Maßnahme(n)"
            : "[GS++ Manager] Erinnerung: {$itemCount} POA&M-Frist(en) bald fällig";

        $body = "Hallo {$userName},\n\n"
            . "folgende POA&M-Maßnahmen erfordern Ihre Aufmerksamkeit:\n\n"
            . implode("\n", $lines) . "\n\n"
            . "Bitte melden Sie sich im GS++ Manager an, um den Status zu aktualisieren.\n\n"
            . "GS++ KMU Compliance Manager\n"
            . AppConfig::get('APP_URL', '');

        if ($isDryRun) {
            echo "  [DRY-RUN] würde senden an: {$userEmail} — {$subject}\n";
            $totalNotified++;
            continue;
        }

        try {
            MailService::send($settings, $userEmail, $subject, $body);
            $totalNotified++;
            echo "  Benachrichtigung gesendet an: {$userEmail} ({$itemCount} Einträge)\n";
        } catch (\RuntimeException $e) {
            echo "  FEHLER beim Senden an {$userEmail}: Benachrichtigung konnte nicht zugestellt werden.\n";
        }
    }
}

// Prune stale AI cache entries while we're running
try {
    $pruned = (new AiCacheRepository())->prune();
    if ($pruned > 0) {
        echo "  KI-Cache bereinigt: {$pruned} abgelaufene Einträge entfernt.\n";
    }
} catch (\Throwable $e) {
    echo "  KI-Cache-Bereinigung fehlgeschlagen.\n";
}

echo "[" . date('Y-m-d H:i:s') . "] notify-deadlines abgeschlossen. Benachrichtigungen: {$totalNotified}\n";
