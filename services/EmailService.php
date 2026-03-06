<?php

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/MailProviderInterface.php';
require_once __DIR__ . '/SmtpMailProvider.php';
require_once __DIR__ . '/EmailRateLimiter.php';
require_once __DIR__ . '/SecurityLogger.php';

class EmailService {
    private MailProviderInterface $provider;
    private EmailRateLimiter $rateLimiter;
    private SecurityLogger $logger;

    public function __construct(?PDO $db = null, ?MailProviderInterface $provider = null, ?SecurityLogger $logger = null) {
        $this->logger = $logger ?: new SecurityLogger();

        $connection = $db;
        if (!$connection) {
            $database = new Database();
            $connection = $database->getConnection();
        }

        $this->rateLimiter = new EmailRateLimiter($connection, $this->logger);
        $this->provider = $provider ?: $this->buildProvider();
    }

    public function sendVerificationEmail(string $email, string $token): bool {
        $safeName = $this->extractNameFromEmail($email);
        $subject = "Tu código de verificación Viax: {$token}";

        $htmlBody = $this->wrapEmailTemplate(
            "<p>Hola {$safeName},</p>\n             <p>Usa este código para verificar tu cuenta en Viax:</p>\n             <h2 style='letter-spacing:4px'>{$token}</h2>\n             <p>Este código vence en 10 minutos.</p>"
        );

        return $this->sendWithLimit('resend_verification', $email, $safeName, $subject, $htmlBody, "Código de verificación Viax: {$token}");
    }

    public function sendPasswordResetEmail(string $email, string $token): bool {
        $safeName = $this->extractNameFromEmail($email);
        $subject = "Recuperación de contraseña Viax: {$token}";

        $htmlBody = $this->wrapEmailTemplate(
            "<p>Hola {$safeName},</p>\n             <p>Recibimos una solicitud para restablecer tu contraseña.</p>\n             <h2 style='letter-spacing:4px'>{$token}</h2>\n             <p>Este código vence en 10 minutos.</p>\n             <p>Si no hiciste esta solicitud, ignora este mensaje.</p>"
        );

        return $this->sendWithLimit('password_reset', $email, $safeName, $subject, $htmlBody, "Código de recuperación Viax: {$token}");
    }

    public function sendWelcomeEmail(string $email): bool {
        $safeName = $this->extractNameFromEmail($email);
        $subject = '¡Bienvenido a Viax!';

        $htmlBody = $this->wrapEmailTemplate(
            "<p>Hola {$safeName},</p>\n             <p>Tu cuenta fue creada exitosamente en Viax.</p>\n             <p>Ya puedes empezar a usar la plataforma.</p>"
        );

        return $this->sendWithLimit('registration', $email, $safeName, $subject, $htmlBody, 'Bienvenido a Viax.');
    }

    public function sendCustomEmail(
        string $email,
        string $name,
        string $subject,
        string $htmlBody,
        ?string $altBody = null
    ): bool {
        try {
            $sent = $this->provider->send($email, $name, $subject, $htmlBody, $altBody);
            $this->logger->info('Custom email sent', [
                'email_hash' => hash('sha256', strtolower($email)),
            ]);
            return $sent;
        } catch (Throwable $exception) {
            $this->logger->error('Custom email failure', [
                'email_hash' => hash('sha256', strtolower($email)),
                'error' => $exception->getMessage(),
            ]);
            return false;
        }
    }

    private function sendWithLimit(
        string $action,
        string $email,
        string $name,
        string $subject,
        string $htmlBody,
        string $altBody
    ): bool {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $this->rateLimiter->assertAllowed($action, $ip, $email);
            $sent = $this->provider->send($email, $name, $subject, $htmlBody, $altBody);

            $this->logger->info('Email sent', [
                'action' => $action,
                'email_hash' => hash('sha256', strtolower($email)),
            ]);

            return $sent;
        } catch (Throwable $exception) {
            $this->logger->error('Email sending failure', [
                'action' => $action,
                'email_hash' => hash('sha256', strtolower($email)),
                'ip_hash' => hash('sha256', $ip),
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function buildProvider(): MailProviderInterface {
        $provider = strtolower((string) env_value('MAIL_PROVIDER', 'smtp'));

        switch ($provider) {
            case 'smtp':
                return new SmtpMailProvider();
            case 'sendgrid':
            case 'resend':
            case 'ses':
                throw new RuntimeException("Mail provider '{$provider}' no está implementado aún.");
            default:
                throw new RuntimeException('MAIL_PROVIDER inválido. Usa smtp, sendgrid, resend o ses.');
        }
    }

    private function extractNameFromEmail(string $email): string {
        $name = explode('@', $email)[0] ?? 'Usuario';
        $name = preg_replace('/[^a-zA-Z0-9._-]/', '', $name);
        return $name !== '' ? $name : 'Usuario';
    }

    private function wrapEmailTemplate(string $content): string {
        return "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #222;'>
                <div style='padding: 16px 20px; background: #0ea5e9; color: #fff; border-radius: 8px 8px 0 0;'>
                    <h1 style='font-size: 20px; margin: 0;'>Viax</h1>
                </div>
                <div style='padding: 24px; border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 8px 8px;'>
                    {$content}
                    <p style='margin-top:24px; color:#6b7280;'>Este es un correo automático del sistema Viax.</p>
                </div>
            </div>
        ";
    }
}
