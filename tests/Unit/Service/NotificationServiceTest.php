<?php

declare(strict_types=1);

namespace GsppManager\Tests\Unit\Service;

use GsppManager\Service\InMemoryMailTransport;
use GsppManager\Service\NotificationService;
use GsppManager\Tests\Unit\UnitTestCase;

class NotificationServiceTest extends UnitTestCase
{
    private InMemoryMailTransport $transport;
    private NotificationService   $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = new InMemoryMailTransport();
        $this->service   = new NotificationService($this->transport);
    }

    public function test_sends_to_correct_recipient(): void
    {
        $this->service->sendDeadlineReminder(
            'verantwortlicher@example.de',
            'Max Mustermann',
            'Passwortrichtlinie einführen',
            '2026-05-10'
        );

        $this->assertCount(1, $this->transport->sent);
        $this->assertSame('verantwortlicher@example.de', $this->transport->sent[0]['to']);
    }

    public function test_subject_contains_item_title(): void
    {
        $this->service->sendDeadlineReminder(
            'test@example.de',
            'Erika Muster',
            'Backup-Strategie dokumentieren',
            '2026-04-01'
        );

        $this->assertStringContainsString(
            'Backup-Strategie dokumentieren',
            $this->transport->sent[0]['subject']
        );
    }

    public function test_body_contains_deadline(): void
    {
        $this->service->sendDeadlineReminder(
            'test@example.de',
            'Hans Wurst',
            'Netzwerksegmentierung prüfen',
            '2026-03-31'
        );

        $this->assertStringContainsString('2026-03-31', $this->transport->sent[0]['body']);
    }
}
