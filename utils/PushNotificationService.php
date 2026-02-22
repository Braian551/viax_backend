<?php
/**
 * PushNotificationService.php
 * Servicio para enviar notificaciones push a dispositivos registrados (FCM).
 */

class PushNotificationService
{
    private const FCM_ENDPOINT = 'https://fcm.googleapis.com/fcm/send';

    private ?string $serverKey;

    public function __construct()
    {
        $this->serverKey = getenv('FCM_SERVER_KEY') ?: $_ENV['FCM_SERVER_KEY'] ?? null;

        if (empty($this->serverKey)) {
            $this->serverKey = getenv('FIREBASE_SERVER_KEY') ?: $_ENV['FIREBASE_SERVER_KEY'] ?? null;
        }
    }

    public function sendToUser(
        PDO $conn,
        int $usuarioId,
        int $notificationId,
        string $tipo,
        string $titulo,
        string $mensaje,
        array $data = []
    ): bool {
        if (empty($this->serverKey)) {
            error_log('PushNotificationService: FCM_SERVER_KEY/FIREBASE_SERVER_KEY no configurado.');
            return false;
        }

        if (!$this->isPushEnabledForType($conn, $usuarioId, $tipo)) {
            return false;
        }

        $tokens = $this->getActiveTokens($conn, $usuarioId);
        if (empty($tokens)) {
            return false;
        }

        $payloadData = array_merge($data, [
            'notification_id' => (string) $notificationId,
            'tipo' => $tipo,
            'usuario_id' => (string) $usuarioId,
            'title' => $titulo,
            'body' => $mensaje,
            'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
        ]);

        $delivered = false;

        foreach ($tokens as $token) {
            $response = $this->sendToToken($token, $titulo, $mensaje, $payloadData);

            if (($response['success'] ?? false) === true) {
                $delivered = true;
                continue;
            }

            $error = $response['error'] ?? '';
            if (str_contains($error, 'NotRegistered') || str_contains($error, 'InvalidRegistration')) {
                $this->deactivateToken($conn, $usuarioId, $token);
            }
        }

        if ($delivered) {
            $stmt = $conn->prepare(
                'UPDATE notificaciones_usuario
                 SET push_enviada = TRUE, push_enviada_en = NOW()
                 WHERE id = :id'
            );
            $stmt->execute([':id' => $notificationId]);
        }

        return $delivered;
    }

    private function isPushEnabledForType(PDO $conn, int $usuarioId, string $tipo): bool
    {
        $stmt = $conn->prepare(
            'SELECT push_enabled, notif_viajes, notif_pagos, notif_promociones, notif_sistema, notif_chat
             FROM configuracion_notificaciones_usuario
             WHERE usuario_id = :usuario_id'
        );
        $stmt->execute([':usuario_id' => $usuarioId]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$config) {
            return true;
        }

        if (!(bool) $config['push_enabled']) {
            return false;
        }

        $group = $this->resolveTypeGroup($tipo);

        return match ($group) {
            'viajes' => (bool) $config['notif_viajes'],
            'pagos' => (bool) $config['notif_pagos'],
            'promociones' => (bool) $config['notif_promociones'],
            'chat' => (bool) $config['notif_chat'],
            default => (bool) $config['notif_sistema'],
        };
    }

    private function resolveTypeGroup(string $tipo): string
    {
        if (in_array($tipo, ['trip_accepted', 'trip_cancelled', 'trip_completed', 'driver_arrived', 'driver_waiting'], true)) {
            return 'viajes';
        }

        if (in_array($tipo, ['payment_received', 'payment_pending'], true)) {
            return 'pagos';
        }

        if ($tipo === 'promo') {
            return 'promociones';
        }

        if ($tipo === 'chat_message') {
            return 'chat';
        }

        return 'sistema';
    }

    private function getActiveTokens(PDO $conn, int $usuarioId): array
    {
        $stmt = $conn->prepare(
            'SELECT token
             FROM tokens_push_usuario
             WHERE usuario_id = :usuario_id
               AND activo = TRUE'
        );
        $stmt->execute([':usuario_id' => $usuarioId]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn(array $row) => $row['token'] ?? null,
            $rows
        )));
    }

    private function deactivateToken(PDO $conn, int $usuarioId, string $token): void
    {
        $stmt = $conn->prepare(
            'UPDATE tokens_push_usuario
             SET activo = FALSE, updated_at = NOW()
             WHERE usuario_id = :usuario_id
               AND token = :token'
        );
        $stmt->execute([
            ':usuario_id' => $usuarioId,
            ':token' => $token,
        ]);
    }

    private function sendToToken(string $token, string $title, string $body, array $data): array
    {
        $payload = [
            'to' => $token,
            'priority' => 'high',
            'notification' => [
                'title' => $title,
                'body' => $body,
                'sound' => 'default',
            ],
            'data' => $data,
        ];

        $ch = curl_init(self::FCM_ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: key=' . $this->serverKey,
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);

        $rawResponse = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($rawResponse === false) {
            return [
                'success' => false,
                'error' => $curlError,
            ];
        }

        $decoded = json_decode($rawResponse, true);
        $success = (int) ($decoded['success'] ?? 0) > 0;

        if ($success) {
            return ['success' => true];
        }

        $error = '';
        if (isset($decoded['results'][0]['error'])) {
            $error = (string) $decoded['results'][0]['error'];
        }

        return [
            'success' => false,
            'error' => $error,
            'raw' => $decoded,
        ];
    }
}
