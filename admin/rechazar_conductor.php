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
    $motivo = isset($input['motivo']) ? trim($input['motivo']) : '';

    // Validar parámetros
    if ($admin_id <= 0) {
        throw new Exception('ID de administrador inválido');
    }

    if ($conductor_id <= 0) {
        throw new Exception('ID de conductor inválido');
    }

    if (empty($motivo)) {
        throw new Exception('Debes proporcionar el motivo del rechazo');
    }

    // Verificar que es admin
    $stmt = $conn->prepare("SELECT tipo_usuario FROM usuarios WHERE id = ? AND tipo_usuario = 'administrador'");
    $stmt->execute([$admin_id]);
    
    if ($stmt->rowCount() === 0) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Acceso denegado. Solo administradores pueden rechazar conductores.'
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
            SET estado_verificacion = 'rechazado',
                estado_aprobacion = 'rechazado',
                aprobado = 0,
                razon_rechazo = ?,
                fecha_ultima_verificacion = CURRENT_TIMESTAMP,
                actualizado_en = CURRENT_TIMESTAMP
            WHERE usuario_id = ?
        ");
        $stmt->execute([$motivo, $conductor_id]);

        // Registrar en logs de auditoría (opcional)
        try {
            $accion = 'rechazar_conductor';
            $descripcion = "Conductor ID $conductor_id rechazado por administrador ID $admin_id - Motivo: $motivo";
            
            $stmt = $conn->prepare("
                INSERT INTO logs_auditoria (usuario_id, accion, entidad, entidad_id, descripcion, fecha_creacion)
                VALUES (?, ?, 'detalles_conductor', ?, ?, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([$admin_id, $accion, $conductor_id, $descripcion]);
        } catch (Exception $log_error) {
            error_log("Error al registrar log de auditoría: " . $log_error->getMessage());
        }

        // Confirmar transacción
        $conn->commit();

        // Enviar correo de rechazo
        try {
            $stmt = $conn->prepare("SELECT email, nombre, apellido FROM usuarios WHERE id = ?");
            $stmt->execute([$conductor_id]);
            $conductorData = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($conductorData) {
                $nombreCompleto = trim($conductorData['nombre'] . ' ' . $conductorData['apellido']);
                require_once __DIR__ . '/../utils/Mailer.php';
                
                Mailer::sendConductorRejectedEmail($conductorData['email'], $nombreCompleto, [], $motivo);
            }
        } catch (Exception $mailError) {
            error_log("Error enviando email de rechazo a conductor $conductor_id: " . $mailError->getMessage());
        }

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Conductor rechazado exitosamente',
            'data' => [
                'conductor_id' => $conductor_id,
                'estado_verificacion' => 'rechazado',
                'motivo' => $motivo
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
