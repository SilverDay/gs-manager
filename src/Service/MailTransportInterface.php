<?php

declare(strict_types=1);

namespace GsppManager\Service;

interface MailTransportInterface
{
    public function send(string $to, string $subject, string $body): void;
}
