<?php

declare(strict_types=1);

namespace GsppManager\Service;

class InMemoryMailTransport implements MailTransportInterface
{
    /** @var array<int, array{to: string, subject: string, body: string}> */
    public array $sent = [];

    public function send(string $to, string $subject, string $body): void
    {
        $this->sent[] = compact('to', 'subject', 'body');
    }
}
