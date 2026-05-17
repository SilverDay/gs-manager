<?php

declare(strict_types=1);

namespace GsppManager\Service;

class SmtpMailTransport implements MailTransportInterface
{
    public function __construct(private array $smtpConfig) {}

    public function send(string $to, string $subject, string $body): void
    {
        MailService::send($this->smtpConfig, $to, $subject, $body);
    }
}
