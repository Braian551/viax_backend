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

// Crear conexión mysqli
$conn = new mysqli('localhost', 'root', 'root', 'viax');
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error de conexión a la base de datos: ' . $conn->connect_error
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
$conn->set_charset("utf8");

try {
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
    $stmt->bind_param("i", $admin_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Acceso denegado. Solo administradores pueden rechazar conductores.'
        ]);
        exit;
    }

    // Verificar que el conductor existe
    $stmt = $conn->prepare("SELECT id FROM detalles_conductor WHERE usuario_id = ?");
    $stmt->bind_param("i", $conductor_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        throw new Exception('Conductor no encontrado');
    }

    // Iniciar transacción
    $conn->begin_transaction();

    try {
        // Crear entrada en tabla de reportes o agregar campo motivo_rechazo si existe
        // Por ahora, vamos a actualizar el estado y guardar el motivo en logs
        
        // Actualizar estado del conductor
        $stmt = $conn->prepare("
            UPDATE detalles_conductor 
            SET estado_verificacion = 'rechazado',
                estado_aprobacion = 'rechazado',
                aprobado = 0,
                fecha_ultima_verificacion = CURRENT_TIMESTAMP,
                actualizado_en = CURRENT_TIMESTAMP
            WHERE usuario_id = ?
        ");
        $stmt->bind_param("i", $conductor_id);
        $stmt->execute();

        // Registrar en logs de auditoría (opcional)
        try {
            $accion = 'rechazar_conductor';
            $descripcion = "Conductor ID $conductor_id rechazado por administrador ID $admin_id - Motivo: $motivo";
            
            $stmt = $conn->prepare("
                INSERT INTO logs_auditoria (usuario_id, accion, tabla_afectada, registro_id, descripcion, fecha_creacion)
                VALUES (?, ?, 'detalles_conductor', ?, ?, CURRENT_TIMESTAMP)
            ");
            $stmt->bind_param("isis", $admin_id, $accion, $conductor_id, $descripcion);
            $stmt->execute();
        } catch (Exception $log_error) {
            error_log("Error al registrar log de auditoría: " . $log_error->getMessage());
        }

        // Confirmar transacción
        $conn->commit();

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
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
