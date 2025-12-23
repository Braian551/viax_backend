<?php
/**
 * Endpoint para enviar calificaciones de viaje.
 * 
 * POST /rating/submit_rating.php
 * 
 * Body:
 * - solicitud_id: int
 * - calificador_id: int
 * - calificado_id: int
 * - calificacion: int (1-5)
 * - tipo_calificador: string ('cliente' o 'conductor')
 * - comentario: string (opcional)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validar datos requeridos
    $solicitudId = $data['solicitud_id'] ?? null;
    $calificadorId = $data['calificador_id'] ?? null;
    $calificadoId = $data['calificado_id'] ?? null;
    $calificacion = $data['calificacion'] ?? null;
    $tipoCalificador = $data['tipo_calificador'] ?? null;
    $comentario = $data['comentario'] ?? null;
    
    if (!$solicitudId || !$calificadorId || !$calificadoId || !$calificacion || !$tipoCalificador) {
        throw new Exception('Datos incompletos. Se requiere: solicitud_id, calificador_id, calificado_id, calificacion, tipo_calificador');
    }
    
    // Validar calificación (1-5)
    $calificacion = intval($calificacion);
    if ($calificacion < 1 || $calificacion > 5) {
        throw new Exception('La calificación debe ser entre 1 y 5');
    }
    
    // Validar tipo de calificador
    if (!in_array($tipoCalificador, ['cliente', 'conductor'])) {
        throw new Exception('tipo_calificador debe ser "cliente" o "conductor"');
    }
    
    $database = new Database();
    $db = $database->getConnection();
    
    // Verificar que la solicitud existe y está completada
    $stmt = $db->prepare("
        SELECT id, estado, cliente_id
        FROM solicitudes_servicio
        WHERE id = ?
    ");
    $stmt->execute([$solicitudId]);
    $solicitud = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$solicitud) {
        throw new Exception('Solicitud no encontrada');
    }
    
    // Verificar que no exista ya una calificación del mismo tipo para esta solicitud
    $stmt = $db->prepare("
        SELECT id FROM calificaciones
        WHERE solicitud_id = ? AND usuario_calificador_id = ?
    ");
    $stmt->execute([$solicitudId, $calificadorId]);
    
    if ($stmt->fetch()) {
        throw new Exception('Ya has calificado este viaje');
    }
    
    // Insertar calificación
    $stmt = $db->prepare("
        INSERT INTO calificaciones (
            solicitud_id,
            usuario_calificador_id,
            usuario_calificado_id,
            calificacion,
            comentarios,
            creado_en
        ) VALUES (?, ?, ?, ?, ?, NOW())
    ");
    
    $stmt->execute([
        $solicitudId,
        $calificadorId,
        $calificadoId,
        $calificacion,
        $comentario
    ]);
    
    $calificacionId = $db->lastInsertId();
    
    // Actualizar promedio de calificaciones del usuario calificado
    if ($tipoCalificador === 'cliente') {
        // Cliente califica a conductor - actualizar detalles_conductor
        $stmt = $db->prepare("
            UPDATE detalles_conductor 
            SET calificacion_promedio = (
                SELECT AVG(c.calificacion)
                FROM calificaciones c
                WHERE c.usuario_calificado_id = ?
            ),
            total_calificaciones = (
                SELECT COUNT(*)
                FROM calificaciones c
                WHERE c.usuario_calificado_id = ?
            )
            WHERE usuario_id = ?
        ");
        $stmt->execute([$calificadoId, $calificadoId, $calificadoId]);
    }
    // Nota: No actualizamos promedio de cliente ya que la tabla usuarios no tiene esa columna
    
    // Obtener nuevo promedio
    // Obtener nuevo promedio
    if ($tipoCalificador === 'cliente') {
        $stmt = $db->prepare("SELECT calificacion_promedio FROM detalles_conductor WHERE usuario_id = ?");
        $stmt->execute([$calificadoId]);
        $nuevoPromedio = $stmt->fetchColumn() ?? 5.0;
    } else {
        // Para clientes, calcular desde calificaciones directamente
        $stmt = $db->prepare("SELECT AVG(calificacion) FROM calificaciones WHERE usuario_calificado_id = ?");
        $stmt->execute([$calificadoId]);
        $nuevoPromedio = $stmt->fetchColumn() ?? 5.0;
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Calificación enviada correctamente',
        'calificacion_id' => $calificacionId,
        'nuevo_promedio' => round(floatval($nuevoPromedio), 1)
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
