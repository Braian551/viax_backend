<?php

use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/MailProviderInterface.php';

class SmtpMailProvider implements MailProviderInterface {
    public function send(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        ?string $altBody = null,
        array $attachments = []
    ): bool {
        $smtpHost = env_value('SMTP_HOST', 'smtp.gmail.com');
        $smtpPort = (int) env_value('SMTP_PORT', 587);
        $smtpUser = env_value('SMTP_USER');
        $smtpPass = env_value('SMTP_PASS');

        if (empty($smtpUser) || empty($smtpPass)) {
            throw new RuntimeException('SMTP credentials are missing from environment variables.');
        }

        $fromEmail = env_value('SMTP_FROM_EMAIL', $smtpUser);
        $fromName = env_value('SMTP_FROM_NAME', 'Viax');

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUser;
        $mail->Password = $smtpPass;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $smtpPort;
        $mail->CharSet = 'UTF-8';
        $mail->SMTPAutoTLS = true;

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = $altBody ?: strip_tags($htmlBody);

        foreach ($attachments as $attachment) {
            if (!empty($attachment['path']) && file_exists($attachment['path'])) {
                if (!empty($attachment['cid'])) {
                    $mail->addEmbeddedImage(
                        $attachment['path'],
                        $attachment['cid'],
                        $attachment['name'] ?? '',
                        'base64',
                        $attachment['type'] ?? ''
                    );
                } else {
                    $mail->addAttachment($attachment['path'], $attachment['name'] ?? 'attachment');
                }
            }
        }

        return $mail->send();
    }
}
