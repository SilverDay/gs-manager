<?php

declare(strict_types=1);

namespace GsppManager\Controller;

use GsppManager\Config\Database;
use GsppManager\Middleware\AuditLogger;
use GsppManager\Repository\AiCacheRepository;
use GsppManager\Security\FieldEncryptor;
use GsppManager\Service\AiClientInterface;
use GsppManager\Service\ClaudeApiClient;
use GsppManager\Service\GeminiApiClient;
use GsppManager\Service\InMemoryAiClient;

class AiController extends BaseController
{
    // Allow test injection without real API calls
    private static ?AiClientInterface $testClientOverride = null;

    /**
     * Override the AI client used by this controller.
     * Intended for unit/integration tests only.
     */
    public static function setTestClient(?AiClientInterface $client): void
    {
        self::$testClientOverride = $client;
    }

    private const SYSTEM_PROMPT =
    'Du bist ein KMU-Informationssicherheitsberater. Du hilfst Unternehmen mit 50–500 Mitarbeitenden ' .
        'bei der Umsetzung von Grundschutz++. Antworte immer auf Deutsch, praxisnah und verständlich.';

    // ─── Permission guard ───────────────────────────────────────

    private function isForbidden(): bool
    {
        $role = $this->userRole();
        return in_array($role, ['management', 'readonly'], true);
    }

    // ─── Client factory ─────────────────────────────────────────

    private function resolveClient(): AiClientInterface
    {
        if (self::$testClientOverride !== null) {
            return self::$testClientOverride;
        }

        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT settings_json FROM tenants WHERE id = ?');
        $stmt->execute([$this->tenantId()]);
        $row = $stmt->fetch();

        $settings = json_decode($row['settings_json'] ?? '{}', true) ?? [];
        $provider = $settings['ai_provider'] ?? '';
        $keyEnc   = $settings['ai_api_key_enc'] ?? '';

        if (empty($provider) || empty($keyEnc)) {
            return new InMemoryAiClient('Kein KI-Anbieter konfiguriert. Bitte konfigurieren Sie einen KI-Anbieter unter Administration → Einstellungen.');
        }

        $apiKey = (new FieldEncryptor())->decrypt($keyEnc);

        return match ($provider) {
            'gemini' => new GeminiApiClient($apiKey),
            default  => new ClaudeApiClient($apiKey),
        };
    }

    // ─── Input sanitization ──────────────────────────────────────

    private function s(mixed $value, int $max = 500): string
    {
        return mb_substr((string) $value, 0, $max);
    }

    // ─── Shared query handler ────────────────────────────────────

    private function handleQuery(string $promptType, array $body, string $userPrompt): void
    {
        if ($this->isForbidden()) {
            $this->error('Keine Berechtigung.', 403);
            return;
        }

        $tenantId = $this->tenantId();
        $cache    = new AiCacheRepository();
        $cacheKey = AiCacheRepository::buildKey($promptType, $body);

        $cached = $cache->get($cacheKey, $tenantId);
        if ($cached !== null) {
            $this->json(['response' => $cached, 'cached' => true]);
            return;
        }

        $client = $this->resolveClient();

        try {
            $response = $client->complete(self::SYSTEM_PROMPT, $userPrompt);
        } catch (\RuntimeException $e) {
            error_log('[AI query] ' . $e->getMessage());
            $this->error('KI-Dienst nicht erreichbar.', 502);
            return;
        }

        $cache->store(
            $cacheKey,
            $tenantId,
            $response,
            $promptType,
            $client->getProviderName(),
            $client->getModelName(),
            $client->getLastTokenCount()
        );

        AuditLogger::log('ai.query', 'ai_cache', 0, [
            'prompt_type' => $promptType,
            'provider'    => $client->getProviderName(),
            'tokens'      => $client->getLastTokenCount(),
        ]);

        $this->json([
            'response' => $response,
            'cached'   => false,
            'provider' => $client->getProviderName(),
            'model'    => $client->getModelName(),
        ]);
    }

    // ─── Endpoints ───────────────────────────────────────────────

    /** POST /api/ai/explain */
    public function explain(array $params): void
    {
        $body = $this->requestBody();
        $err  = $this->validateRequired($body, ['control_id', 'control_title', 'description']);
        if ($err !== null) {
            $this->error($err, 422);
            return;
        }

        $prompt = "Erkläre die folgende Grundschutz++ Anforderung in einfacher, verständlicher Sprache für ein KMU ohne OSCAL-Erfahrung.\n\n" .
            "Anforderungs-ID: {$this->s($body['control_id'], 100)}\n" .
            "Titel: {$this->s($body['control_title'], 200)}\n" .
            "Beschreibung: {$this->s($body['description'])}\n\n" .
            "Gib eine kurze Erklärung (3–5 Sätze) und ein konkretes Praxisbeispiel für ein KMU.";

        $this->handleQuery('explain', $body, $prompt);
    }

    /** POST /api/ai/suggest-implementation */
    public function suggestImplementation(array $params): void
    {
        $body = $this->requestBody();
        $err  = $this->validateRequired($body, ['control_id', 'control_title', 'description']);
        if ($err !== null) {
            $this->error($err, 422);
            return;
        }

        $context = isset($body['industry']) ? "Branche: {$this->s($body['industry'], 100)}\n" : '';
        $context .= isset($body['org_size']) ? "Unternehmensgröße: {$this->s($body['org_size'], 20)} Mitarbeitende\n" : '';

        $prompt = "Erstelle einen konkreten Umsetzungsvorschlag für die folgende Grundschutz++ Anforderung.\n\n" .
            "Anforderungs-ID: {$this->s($body['control_id'], 100)}\n" .
            "Titel: {$this->s($body['control_title'], 200)}\n" .
            "Beschreibung: {$this->s($body['description'])}\n" .
            $context .
            "\nGib 3–5 konkrete, sofort umsetzbare Maßnahmen an. Berücksichtige typische KMU-Ressourcen (begrenzte IT-Abteilung, kein CISO).";

        $this->handleQuery('suggest', $body, $prompt);
    }

    /** POST /api/ai/risk-analysis */
    public function riskAnalysis(array $params): void
    {
        $body = $this->requestBody();
        $err  = $this->validateRequired($body, ['control_id', 'control_title', 'description']);
        if ($err !== null) {
            $this->error($err, 422);
            return;
        }

        $prompt = "Analysiere die Risiken, die entstehen, wenn die folgende Grundschutz++ Anforderung NICHT umgesetzt wird.\n\n" .
            "Anforderungs-ID: {$this->s($body['control_id'], 100)}\n" .
            "Titel: {$this->s($body['control_title'], 200)}\n" .
            "Beschreibung: {$this->s($body['description'])}\n\n" .
            "Nenne 3–5 konkrete Schadenszenarien (z.B. Datenverlust, Betriebsunterbrechung, Bußgelder) mit realistischer Einschätzung für ein KMU.";

        $this->handleQuery('risk', $body, $prompt);
    }

    /** POST /api/ai/audit-finding */
    public function auditFinding(array $params): void
    {
        $body = $this->requestBody();
        $err  = $this->validateRequired($body, ['control_id', 'implementation_status', 'implementation_description']);
        if ($err !== null) {
            $this->error($err, 422);
            return;
        }

        $prompt = "Erstelle einen Audit-Befundvorschlag basierend auf dem folgenden Umsetzungsstand.\n\n" .
            "Anforderungs-ID: {$this->s($body['control_id'], 100)}\n" .
            "Umsetzungsstatus: {$this->s($body['implementation_status'], 50)}\n" .
            "Umsetzungsbeschreibung: {$this->s($body['implementation_description'])}\n\n" .
            "Formuliere einen professionellen Prüfbefund (Befundtext + Empfehlung) in BSI-Grundschutz-Sprache.";

        $this->handleQuery('audit', $body, $prompt);
    }

    /** POST /api/ai/remediation-plan */
    public function remediationPlan(array $params): void
    {
        $body = $this->requestBody();
        $err  = $this->validateRequired($body, ['title', 'description']);
        if ($err !== null) {
            $this->error($err, 422);
            return;
        }

        $deadline = isset($body['deadline']) ? "Deadline: {$this->s($body['deadline'], 30)}\n" : '';

        $prompt = "Erstelle einen konkreten Sanierungsplan (Maßnahmenplan) für die folgende Feststellung.\n\n" .
            "Titel: {$this->s($body['title'], 200)}\n" .
            "Beschreibung: {$this->s($body['description'])}\n" .
            $deadline .
            "\nGib einen strukturierten Plan mit 3–5 Meilensteinen an (Meilenstein, Verantwortlicher-Rolle, Frist-Empfehlung). Praxisnah für ein KMU.";

        $this->handleQuery('remediation', $body, $prompt);
    }

    /** POST /api/ai/maturity-analysis */
    public function maturityAnalysis(array $params): void
    {
        $body = $this->requestBody();
        $err  = $this->validateRequired($body, ['control_id', 'control_title']);
        if ($err !== null) {
            $this->error($err, 422);
            return;
        }

        $prompt = "Generiere Prüfungshandlungen (Audit Questions) für die folgende Grundschutz++ Anforderung auf den Reifegradstufen 0–5.\n\n" .
            "Anforderungs-ID: {$this->s($body['control_id'], 100)}\n" .
            "Titel: {$this->s($body['control_title'], 200)}\n\n" .
            "Strukturiere die Ausgabe als Liste: Stufe 0 (nicht vorhanden), Stufe 1 (initiiert), Stufe 2 (geplant), Stufe 3 (definiert), Stufe 4 (gesteuert), Stufe 5 (optimiert). Je Stufe 2–3 konkrete Prüffragen.";

        $this->handleQuery('maturity', $body, $prompt);
    }

    /** POST /api/ai/map-edition-2023 */
    public function mapEdition2023(array $params): void
    {
        $body = $this->requestBody();
        $err  = $this->validateRequired($body, ['control_id', 'control_title']);
        if ($err !== null) {
            $this->error($err, 422);
            return;
        }

        $prompt = "Erkläre die Zuordnung der folgenden Grundschutz++ Anforderung zum klassischen BSI IT-Grundschutz (Edition 2023 / Kompendium).\n\n" .
            "Anforderungs-ID: {$this->s($body['control_id'], 100)}\n" .
            "Titel: {$this->s($body['control_title'], 200)}\n\n" .
            "Erkläre: Welchen Bausteinen aus dem BSI IT-Grundschutz-Kompendium 2023 entspricht diese Anforderung? Was sind die wesentlichen Unterschiede? Was bleibt gleich?";

        $this->handleQuery('map2023', $body, $prompt);
    }
}
