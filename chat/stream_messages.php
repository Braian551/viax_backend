<?php
/**
 * API: Stream de mensajes de chat (SSE)
 * Endpoint: GET /chat/stream_messages.php
 *
 * Parámetros:
 * - solicitud_id (required)
 * - usuario_id (required)
 * - desde_id (optional)
 * - wait_seconds (optional, max 25)
 */

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo "event: error\n";
    echo 'data: ' . json_encode(['success' => false, 'message' => 'Metodo no permitido']) . "\n\n";
    exit();
}

@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', 'off');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) {
    @ob_end_flush();
}
ob_implicit_flush(true);

require_once __DIR__ . '/../config/database.php';

function parseWaitSeconds($rawValue): int {
    $value = intval($rawValue ?? 15);
    if ($value < 0) return 0;
    if ($value > 25) return 25;
    return $value;
}

function sendEvent(string $event, array $payload): void {
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
    @flush();
}

function fetchMessages(PDO $db, int $solicitudId, int $usuarioId, int $desdeId, int $limite = 50): array {
    $stmt = $db->prepare(
        "
        SELECT
            m.id,
            m.solicitud_id,
            m.remitente_id,
            m.destinatario_id,
            m.tipo_remitente,
            m.mensaje,
            m.tipo_mensaje,
            m.leido,
            m.leido_en,
            m.fecha_creacion,
            u.nombre as remitente_nombre,
            u.foto_perfil as remitente_foto
        FROM mensajes_chat m
        LEFT JOIN usuarios u ON m.remitente_id = u.id
        WHERE m.solicitud_id = :solicitud_id
          AND m.activo = true
          AND m.id > :desde_id
        ORDER BY m.fecha_creacion ASC
        LIMIT :limite
    "
    );

    $stmt->bindValue(':solicitud_id', $solicitudId, PDO::PARAM_INT);
    $stmt->bindValue(':desde_id', $desdeId, PDO::PARAM_INT);
    $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($rows)) {
        $stmtUpdate = $db->prepare(
            "
            UPDATE mensajes_chat
            SET leido = true, leido_en = CURRENT_TIMESTAMP
            WHERE solicitud_id = :solicitud_id
              AND destinatario_id = :usuario_id
              AND leido = false
        "
        );
        $stmtUpdate->execute([
            ':solicitud_id' => $solicitudId,
            ':usuario_id' => $usuarioId,
        ]);
    }

    $messages = array_map(function ($m) {
        return [
            'id' => (int)$m['id'],
            'solicitud_id' => (int)$m['solicitud_id'],
            'remitente_id' => (int)$m['remitente_id'],
            'destinatario_id' => (int)$m['destinatario_id'],
            'tipo_remitente' => $m['tipo_remitente'],
            'mensaje' => $m['mensaje'],
            'tipo_mensaje' => $m['tipo_mensaje'],
            'leido' => (bool)$m['leido'],
            'leido_en' => $m['leido_en'],
            'fecha_creacion' => $m['fecha_creacion'],
            'remitente' => [
                'nombre' => $m['remitente_nombre'],
                'foto' => $m['remitente_foto'],
            ],
        ];
    }, $rows);

    return $messages;
}

try {
    $solicitudId = isset($_GET['solicitud_id']) ? intval($_GET['solicitud_id']) : 0;
    $usuarioId = isset($_GET['usuario_id']) ? intval($_GET['usuario_id']) : 0;
    $desdeId = isset($_GET['desde_id']) ? intval($_GET['desde_id']) : 0;
    $waitSeconds = parseWaitSeconds($_GET['wait_seconds'] ?? 15);

    if ($solicitudId <= 0 || $usuarioId <= 0) {
        sendEvent('error', [
            'success' => false,
            'message' => 'solicitud_id y usuario_id son requeridos',
        ]);
        exit();
    }

    $database = new Database();
    $db = $database->getConnection();

    $deadline = microtime(true) + $waitSeconds;
    $heartbeatEvery = 10;
    $nextHeartbeat = microtime(true) + $heartbeatEvery;

    sendEvent('connected', [
        'success' => true,
        'server_time' => gmdate('c'),
        'desde_id' => $desdeId,
    ]);

    while (!connection_aborted() && microtime(true) < $deadline) {
        $messages = fetchMessages($db, $solicitudId, $usuarioId, $desdeId, 80);

        if (!empty($messages)) {
            $latestId = end($messages)['id'];
            sendEvent('messages', [
                'success' => true,
                'mensajes' => $messages,
                'latest_id' => $latestId,
                'server_time' => gmdate('c'),
            ]);
            exit();
        }

        if (microtime(true) >= $nextHeartbeat) {
            sendEvent('keepalive', [
                'success' => true,
                'server_time' => gmdate('c'),
            ]);
            $nextHeartbeat = microtime(true) + $heartbeatEvery;
        }

        usleep(300000);
    }

    sendEvent('timeout', [
        'success' => true,
        'server_time' => gmdate('c'),
    ]);
} catch (Throwable $e) {
    error_log('stream_messages.php Error: ' . $e->getMessage());
    sendEvent('error', [
        'success' => false,
        'message' => 'Error interno de stream',
    ]);
}
