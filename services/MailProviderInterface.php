<?php

interface MailProviderInterface {
    public function send(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        ?string $altBody = null,
        array $attachments = []
    ): bool;
}
