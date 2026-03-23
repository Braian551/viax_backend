<?php
/**
 * Endpoint: Reportar usuario
 * Reglas:
 * - No permite auto-reporte.
 * - Solo permite reportar si existe al menos un viaje compartido.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/BlockHelper.php';

function ensureReportesUsuariosIdDefault(PDO $db): void
{
    // Hardening para entornos legacy donde `id` existe pero sin DEFAULT nextval(...).
    $db->exec(
        "DO $$
        DECLARE
            seq_name text;
            next_value bigint;
        BEGIN
            IF EXISTS (
                SELECT 1
                FROM information_schema.columns
                WHERE table_schema = 'public'
                  AND table_name = 'reportes_usuarios'
                  AND column_name = 'id'
            ) THEN
                seq_name := pg_get_serial_sequence('reportes_usuarios', 'id');

                IF seq_name IS NULL THEN
                    IF NOT EXISTS (
                        SELECT 1
                        FROM pg_class
                        WHERE relkind = 'S'
                          AND relname = 'reportes_usuarios_id_seq'
                    ) THEN
                        CREATE SEQUENCE reportes_usuarios_id_seq;
                    END IF;

                    SELECT COALESCE(MAX(id), 0) + 1
                    INTO next_value
                    FROM reportes_usuarios;

                    PERFORM setval('reportes_usuarios_id_seq', next_value, false);

                    ALTER TABLE reportes_usuarios
                        ALTER COLUMN id SET DEFAULT nextval('reportes_usuarios_id_seq');
                END IF;
            END IF;
        END $$;"
    );
}

function mapLegacyTipoReporte(string $motivo): string
{
    switch ($motivo) {
        case 'comportamiento_inapropiado':
            return 'conducta_inapropiada';
        case 'fraude_o_estafa':
            return 'fraude';
        case 'acoso_o_amenaza':
        case 'incumplimiento_servicio':
        case 'contenido_inapropiado_chat':
            return 'seguridad';
        default:
            return 'otro';
    }
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new Exception('JSON inválido');
    }

    $reporterUserId = isset($input['reporter_user_id']) ? (int)$input['reporter_user_id'] : 0;
    $reportedUserId = isset($input['reported_user_id']) ? (int)$input['reported_user_id'] : 0;
    $solicitudId = isset($input['solicitud_id']) ? (int)$input['solicitud_id'] : null;
    $motivo = trim((string)($input['motivo'] ?? ''));
    $descripcion = trim((string)($input['descripcion'] ?? ''));
    $prioridad = strtolower(trim((string)($input['prioridad'] ?? 'media')));

    if ($reporterUserId <= 0 || $reportedUserId <= 0) {
        throw new Exception('reporter_user_id y reported_user_id son requeridos');
    }
    if ($reporterUserId === $reportedUserId) {
        throw new Exception('No puedes reportarte a ti mismo');
    }
    if ($motivo === '') {
        throw new Exception('motivo es requerido');
    }

    $prioridades = ['baja', 'media', 'alta', 'urgente'];
    if (!in_array($prioridad, $prioridades, true)) {
        $prioridad = 'media';
    }

    $motivosPermitidos = [
        'comportamiento_inapropiado',
        'acoso_o_amenaza',
        'fraude_o_estafa',
        'incumplimiento_servicio',
        'contenido_inapropiado_chat',
        'otro',
    ];
    if (!in_array($motivo, $motivosPermitidos, true)) {
        $motivo = 'otro';
    }

    $legacyTipoReporte = mapLegacyTipoReporte($motivo);

    $database = new Database();
    $db = $database->getConnection();

    ensureReportesUsuariosIdDefault($db);

    $stmtUsers = $db->prepare('SELECT id FROM usuarios WHERE id IN (?, ?) LIMIT 2');
    $stmtUsers->execute([$reporterUserId, $reportedUserId]);
    $users = $stmtUsers->fetchAll(PDO::FETCH_COLUMN);
    if (count($users) < 2) {
        throw new Exception('Usuario no encontrado');
    }

    if (!BlockHelper::hasSharedTrip($db, $reporterUserId, $reportedUserId, $solicitudId)) {
        throw new Exception('Solo puedes reportar usuarios con los que hayas tenido un viaje');
    }

    $stmtDup = $db->prepare(
        'SELECT id
         FROM reportes_usuarios
         WHERE reporter_user_id = :reporter
           AND reported_user_id = :reported
           AND COALESCE(solicitud_id, 0) = :solicitud
           AND estado IN (\'pendiente\', \'en_revision\')
         LIMIT 1'
    );
    $stmtDup->execute([
        ':reporter' => $reporterUserId,
        ':reported' => $reportedUserId,
        ':solicitud' => $solicitudId ?: 0,
    ]);
    if ($stmtDup->fetch(PDO::FETCH_ASSOC)) {
        throw new Exception('Ya tienes un reporte pendiente para este usuario en este viaje');
    }

    $stmtInsert = $db->prepare(
        'INSERT INTO reportes_usuarios
            (
                reporter_user_id,
                reported_user_id,
                solicitud_id,
                motivo,
                descripcion,
                prioridad,
                estado,
                created_at,
                updated_at,
                usuario_reportante_id,
                usuario_reportado_id,
                tipo_reporte,
                fecha_creacion
            )
         VALUES
            (
                :reporter,
                :reported,
                :solicitud,
                :motivo,
                :descripcion,
                :prioridad,
                \'pendiente\',
                NOW(),
                NOW(),
                :reporter,
                :reported,
                :legacy_tipo_reporte,
                NOW()
            )
         RETURNING id, estado, created_at'
    );
    $stmtInsert->execute([
        ':reporter' => $reporterUserId,
        ':reported' => $reportedUserId,
        ':solicitud' => $solicitudId,
        ':motivo' => $motivo,
        ':legacy_tipo_reporte' => $legacyTipoReporte,
        ':descripcion' => $descripcion !== '' ? $descripcion : 'Sin descripcion',
        ':prioridad' => $prioridad,
    ]);

    $created = $stmtInsert->fetch(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'success' => true,
        'message' => 'Reporte enviado correctamente. Nuestro equipo revisará el caso.',
        'data' => [
            'report_id' => isset($created['id']) ? (int)$created['id'] : null,
            'estado' => $created['estado'] ?? 'pendiente',
            'created_at' => $created['created_at'] ?? null,
        ],
    ]);
} catch (Exception $e) {
    http_response_code(400);
    $rawMessage = $e->getMessage();
    $publicMessage = $rawMessage;

    // Evita exponer SQL interno a cliente final.
    if (stripos($rawMessage, 'SQLSTATE') !== false) {
        $publicMessage = 'No pudimos enviar el reporte en este momento. Intenta nuevamente.';
    }

    echo json_encode([
        'success' => false,
        'message' => $publicMessage,
    ]);
}
