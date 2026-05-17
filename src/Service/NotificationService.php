<?php

declare(strict_types=1);

namespace GsppManager\Service;

class NotificationService
{
    public function __construct(private MailTransportInterface $transport) {}

    public function sendDeadlineReminder(
        string $toEmail,
        string $displayName,
        string $itemTitle,
        string $deadline
    ): void {
        $subject = "Maßnahme überfällig: {$itemTitle}";
        $body    = "Liebe(r) {$displayName},\n\n"
                 . "Die Maßnahme \"{$itemTitle}\" hatte die Deadline: {$deadline}.\n\n"
                 . "Bitte bearbeiten Sie diese Maßnahme zeitnah.\n\n"
                 . "GS++ KMU Compliance Manager";

        $this->transport->send($toEmail, $subject, $body);
    }
}
