<?php
// Suprimir warnings y notices
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/database.php';

try {
    // Crear conexión PDO
    $database = new Database();
    $conn = $database->getConnection();

    // Validar método
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método no permitido');
    }

    // Obtener datos del body
    $input = json_decode(file_get_contents('php://input'), true);
    
    $admin_id = isset($input['admin_id']) ? intval($input['admin_id']) : 0;
    $conductor_id = isset($input['conductor_id']) ? intval($input['conductor_id']) : 0;
    $notas = isset($input['notas']) ? trim($input['notas']) : null;

    // Validar parámetros
    if ($admin_id <= 0) {
        throw new Exception('ID de administrador inválido');
    }

    if ($conductor_id <= 0) {
        throw new Exception('ID de conductor inválido');
    }

    // Verificar que es admin
    $stmt = $conn->prepare("SELECT tipo_usuario FROM usuarios WHERE id = ? AND tipo_usuario = 'administrador'");
    $stmt->execute([$admin_id]);

    if ($stmt->rowCount() === 0) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Acceso denegado. Solo administradores pueden aprobar conductores.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Verificar que el conductor existe
    $stmt = $conn->prepare("SELECT id FROM detalles_conductor WHERE usuario_id = ?");
    $stmt->execute([$conductor_id]);

    if ($stmt->rowCount() === 0) {
        throw new Exception('Conductor no encontrado');
    }

    // Iniciar transacción
    $conn->beginTransaction();

    try {
        // Actualizar estado del conductor
        $stmt = $conn->prepare("
            UPDATE detalles_conductor 
            SET estado_verificacion = 'aprobado',
                estado_aprobacion = 'aprobado',
                aprobado = 1,
                fecha_ultima_verificacion = CURRENT_TIMESTAMP,
                actualizado_en = CURRENT_TIMESTAMP
            WHERE usuario_id = ?
        ");
        $stmt->execute([$conductor_id]);

        // Actualizar usuario como verificado
        $stmt = $conn->prepare("UPDATE usuarios SET es_verificado = 1 WHERE id = ?");
        $stmt->execute([$conductor_id]);

        // Registrar en logs de auditoría (opcional, no debe bloquear la operación)
        try {
            $accion = 'aprobar_conductor';
            $descripcion = "Conductor ID $conductor_id aprobado por administrador ID $admin_id";
            if ($notas) {
                $descripcion .= " - Notas: $notas";
            }
            
            $stmt = $conn->prepare("
                INSERT INTO logs_auditoria (usuario_id, accion, entidad, entidad_id, descripcion, fecha_creacion)
                VALUES (?, ?, 'detalles_conductor', ?, ?, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([$admin_id, $accion, $conductor_id, $descripcion]);
        } catch (Exception $log_error) {
            // No lanzar error si falla el log, solo registrar
            error_log("Error al registrar log de auditoría: " . $log_error->getMessage());
        }

        // Confirmar transacción
        $conn->commit();

        // Enviar correo de aprobación
        try {
            $stmt = $conn->prepare("
                SELECT u.email, u.nombre, u.apellido, dc.licencia_conduccion, v.placa
                FROM usuarios u
                JOIN detalles_conductor dc ON u.id = dc.usuario_id
                LEFT JOIN vehiculos v ON dc.vehiculo_id = v.id
                WHERE u.id = ?
            ");
            $stmt->execute([$conductor_id]);
            $conductorData = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($conductorData) {
                $nombreCompleto = trim($conductorData['nombre'] . ' ' . $conductorData['apellido']);
                require_once __DIR__ . '/../utils/Mailer.php';
                
                $mailData = [
                    'licencia' => $conductorData['licencia_conduccion'] ?? 'N/A',
                    'placa' => $conductorData['placa'] ?? 'N/A'
                ];
                
                Mailer::sendConductorApprovedEmail($conductorData['email'], $nombreCompleto, $mailData);
            }
        } catch (Exception $mailError) {
            error_log("Error enviando email de aprobación a conductor $conductor_id: " . $mailError->getMessage());
        }

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Conductor aprobado exitosamente',
            'data' => [
                'conductor_id' => $conductor_id,
                'estado_verificacion' => 'aprobado'
            ]
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
