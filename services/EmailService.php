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

    public function sendAccountDeletionVerificationEmail(string $email, string $token): bool {
        $safeName = $this->extractNameFromEmail($email);
        $subject = "Confirma eliminación de cuenta Viax: {$token}";

        $htmlBody = $this->wrapEmailTemplate(
            "<p>Hola {$safeName},</p>\n             <p>Recibimos una solicitud para eliminar tu cuenta en Viax.</p>\n             <p>Usa este código para confirmar la solicitud:</p>\n             <h2 style='letter-spacing:4px'>{$token}</h2>\n             <p>Este código vence en 10 minutos.</p>\n             <p>Si no hiciste esta solicitud, ignora este mensaje y cambia tu contraseña.</p>"
        );

        return $this->sendWithLimit('account_deletion', $email, $safeName, $subject, $htmlBody, "Código de eliminación de cuenta Viax: {$token}");
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

    /**
     * Envía un resumen de viaje completado al cliente.
     * Reutiliza el layout base del servicio para mantener diseño consistente.
     */
    public function sendTripCompletedSummaryEmail(string $email, string $name, array $summary): bool {
        $safeName = trim($name) !== '' ? $name : $this->extractNameFromEmail($email);
        $safeNameHtml = htmlspecialchars($safeName, ENT_QUOTES, 'UTF-8');

        $tripIdRaw = (string)($summary['trip_id'] ?? '-');
        $origenRaw = (string)($summary['origen'] ?? 'No disponible');
        $destinoRaw = (string)($summary['destino'] ?? 'No disponible');
        $fechaRaw = (string)($summary['fecha'] ?? '');
        $fechaBogotaRaw = $this->formatDateToBogota($fechaRaw);
        $metodoPagoRaw = (string)($summary['metodo_pago'] ?? 'No especificado');

        $tripId = htmlspecialchars($tripIdRaw, ENT_QUOTES, 'UTF-8');
        $origen = htmlspecialchars($origenRaw, ENT_QUOTES, 'UTF-8');
        $destino = htmlspecialchars($destinoRaw, ENT_QUOTES, 'UTF-8');
        $fecha = htmlspecialchars($fechaBogotaRaw, ENT_QUOTES, 'UTF-8');
        $metodoPago = htmlspecialchars($metodoPagoRaw, ENT_QUOTES, 'UTF-8');

        $distanciaKm = number_format((float)($summary['distancia_km'] ?? 0), 2, ',', '.');
        $duracionMin = (int)($summary['duracion_min'] ?? 0);
        $totalCop = number_format((float)($summary['total_cop'] ?? 0), 0, ',', '.');
        $supportUrl = htmlspecialchars((string)env_value('EMAIL_SUPPORT_URL', 'https://viaxcol.online'), ENT_QUOTES, 'UTF-8');

        $subject = 'Resumen de tu viaje en Viax';

        $htmlBody = $this->wrapEmailTemplate(
            "<p style='margin:0 0 12px 0; font-size:16px; color:#0f172a;'>Hola {$safeNameHtml},</p>\n" .
            "<p style='margin:0 0 16px 0; color:#334155;'>Tu viaje finalizó correctamente. Aquí tienes un comprobante claro con todos los detalles.</p>\n" .
            "<div style='border:1px solid #dbeafe; border-radius:16px; overflow:hidden; margin:0 0 16px 0;'>\n" .
            "  <div style='padding:14px 16px; background:#2196F3; color:#ffffff;'>\n" .
            "    <div style='font-size:12px; opacity:0.9; letter-spacing:0.4px; text-transform:uppercase;'>Comprobante de viaje</div>\n" .
            "    <div style='font-size:20px; font-weight:700; margin-top:4px;'>#{$tripId}</div>\n" .
            "  </div>\n" .
            "  <div style='padding:16px; background:#ffffff;'>\n" .
            "    <table role='presentation' width='100%' cellpadding='0' cellspacing='0' style='border-collapse:collapse;'>\n" .
            "      <tr>\n" .
            "        <td style='font-size:13px; color:#64748b; padding-bottom:6px;'>Origen</td>\n" .
            "        <td style='font-size:14px; color:#0f172a; text-align:right; padding-bottom:6px; font-weight:600;'>{$origen}</td>\n" .
            "      </tr>\n" .
            "      <tr>\n" .
            "        <td style='font-size:13px; color:#64748b; padding:6px 0;'>Destino</td>\n" .
            "        <td style='font-size:14px; color:#0f172a; text-align:right; padding:6px 0; font-weight:600;'>{$destino}</td>\n" .
            "      </tr>\n" .
            "      <tr>\n" .
            "        <td style='font-size:13px; color:#64748b; padding:6px 0;'>Fecha</td>\n" .
            "        <td style='font-size:14px; color:#0f172a; text-align:right; padding:6px 0; font-weight:600;'>{$fecha}</td>\n" .
            "      </tr>\n" .
            "      <tr>\n" .
            "        <td style='font-size:13px; color:#64748b; padding:6px 0;'>Distancia</td>\n" .
            "        <td style='font-size:14px; color:#0f172a; text-align:right; padding:6px 0; font-weight:600;'>{$distanciaKm} km</td>\n" .
            "      </tr>\n" .
            "      <tr>\n" .
            "        <td style='font-size:13px; color:#64748b; padding:6px 0;'>Duración</td>\n" .
            "        <td style='font-size:14px; color:#0f172a; text-align:right; padding:6px 0; font-weight:600;'>{$duracionMin} min</td>\n" .
            "      </tr>\n" .
            "      <tr>\n" .
            "        <td style='font-size:13px; color:#64748b; padding-top:6px;'>Método de pago</td>\n" .
            "        <td style='font-size:14px; color:#0f172a; text-align:right; padding-top:6px; font-weight:600;'>{$metodoPago}</td>\n" .
            "      </tr>\n" .
            "    </table>\n" .
            "  </div>\n" .
            "</div>\n" .
            "<div style='margin:0 0 18px 0; padding:16px; border-radius:14px; background:#E3F2FD; border:1px solid #BBDEFB;'>\n" .
            "  <div style='font-size:12px; text-transform:uppercase; letter-spacing:0.5px; color:#1976D2; margin-bottom:6px;'>Total pagado</div>\n" .
            "  <div style='font-size:30px; line-height:1; font-weight:800; color:#0f172a;'>{$totalCop} <span style='font-size:14px; font-weight:700; color:#1976D2;'>COP</span></div>\n" .
            "</div>\n" .
            "<div style='text-align:center; margin:0 0 6px 0;'>\n" .
            "  <a href='{$supportUrl}' style='display:inline-block; background:#2196F3; color:#ffffff; text-decoration:none; padding:12px 20px; border-radius:999px; font-weight:600; font-size:14px;'>Ver ayuda y soporte</a>\n" .
            "</div>\n" .
            "<p style='margin:0; color:#475569; font-size:13px; text-align:center;'>Gracias por viajar con Viax.</p>"
        );

        $altBody =
            "Resumen de viaje Viax\n" .
            "Viaje #{$tripIdRaw}\n" .
            "Origen: {$origenRaw}\n" .
            "Destino: {$destinoRaw}\n" .
            "Fecha: {$fechaBogotaRaw}\n" .
            "Distancia: {$distanciaKm} km\n" .
            "Duración: {$duracionMin} min\n" .
            "Método de pago: {$metodoPagoRaw}\n" .
            "Total pagado: {$totalCop} COP\n" .
            "Soporte: {$supportUrl}";

        return $this->sendWithLimit(
            'trip_completed_summary',
            $email,
            $safeName,
            $subject,
            $htmlBody,
            $altBody
        );
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

    private function formatDateToBogota(string $rawDate): string {
        $tzBogota = new DateTimeZone('America/Bogota');
        $clean = trim($rawDate);

        if ($clean === '') {
            $now = new DateTimeImmutable('now', $tzBogota);
            return str_replace([' am', ' pm'], [' a. m.', ' p. m.'], $now->format('d/m/Y h:i a'));
        }

        try {
            // Si viene con offset/zona horaria, DateTime la respeta; si no, se asume UTC.
            if (preg_match('/(Z|[+-]\d{2}:\d{2})$/i', $clean) === 1) {
                $date = new DateTimeImmutable($clean);
            } else {
                $date = new DateTimeImmutable($clean, new DateTimeZone('UTC'));
            }

            $dateBogota = $date->setTimezone($tzBogota);
            return str_replace([' am', ' pm'], [' a. m.', ' p. m.'], $dateBogota->format('d/m/Y h:i a'));
        } catch (Throwable $e) {
            return $clean;
        }
    }

    private function wrapEmailTemplate(string $content): string {
        $year = (new DateTimeImmutable('now', new DateTimeZone('America/Bogota')))->format('Y');
        $logoUrl = trim((string)env_value('EMAIL_LOGO_URL', ''));
        if ($logoUrl === '') {
            $logoUrl = 'https://viaxcol.online/logo.png?v=20260315';
        }
        $safeLogoUrl = htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8');

        $logoBlock = "
            <table role='presentation' cellpadding='0' cellspacing='0' border='0' align='center' style='margin:0 auto 14px auto;'>
                <tr>
                    <td align='center' valign='middle' style='width:78px; height:78px; border-radius:20px; background:#ffffff; box-shadow:0 6px 18px rgba(33,150,243,0.25); border:1px solid #BBDEFB;'>
                        <img src='{$safeLogoUrl}' alt='Viax' style='display:block; width:58px; height:58px; margin:0 auto; object-fit:contain;' />
                    </td>
                </tr>
            </table>
        ";

        return "
            <div style='font-family: Segoe UI, -apple-system, BlinkMacSystemFont, Roboto, Helvetica, Arial, sans-serif; max-width: 640px; margin: 0 auto; color: #0f172a; background:#ffffff; border:1px solid #dbe8f6; border-radius: 22px; overflow: hidden;'>
                <div style='padding: 28px 24px 22px 24px; text-align: center; background:#2196F3;'>
                    {$logoBlock}
                    <h1 style='font-size: 28px; letter-spacing: 0.2px; margin: 0; color: #ffffff; font-weight: 800;'>Viax</h1>
                    <p style='margin: 8px 0 0 0; color: rgba(255,255,255,0.95); font-size: 14px;'>Movilidad inteligente, segura y confiable</p>
                </div>
                <div style='padding: 24px; background: #f8fbff; border-top-left-radius: 28px; border-top-right-radius: 28px;'>
                    <div style='background:#ffffff; border:1px solid #e2e8f0; border-radius:18px; padding:22px;'>
                    {$content}
                    </div>
                    <p style='margin:18px 8px 0 8px; color:#475569; font-size:12px; text-align:center;'>Este es un correo automático del sistema Viax.</p>
                    <p style='margin:10px 8px 0 8px; color:#94a3b8; font-size:12px; text-align:center;'>&copy; {$year} Viax Technology S.A.S. | viaxcol.online | NIT 902040253-1</p>
                </div>
            </div>
        ";
    }
}
