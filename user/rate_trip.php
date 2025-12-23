<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

// Cargar servicio de confianza si existe
$confianzaServicePath = __DIR__ . '/../confianza/ConfianzaService.php';
$useConfianza = file_exists($confianzaServicePath);
if ($useConfianza) {
    require_once $confianzaServicePath;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $solicitudId = $data['solicitud_id'] ?? null;
    $usuarioId = $data['usuario_id'] ?? null;
    $calificacion = $data['calificacion'] ?? null;
    $comentario = $data['comentario'] ?? '';
    
    if (!$solicitudId || !$usuarioId || !$calificacion) {
        throw new Exception('solicitud_id, usuario_id y calificacion son requeridos');
    }
    
    if ($calificacion < 1 || $calificacion > 5) {
        throw new Exception('La calificación debe estar entre 1 y 5');
    }
    
    $database = new Database();
    $db = $database->getConnection();
    
    // Obtener información de la solicitud y conductor
    $stmt = $db->prepare("
        SELECT s.cliente_id, ac.conductor_id
        FROM solicitudes_servicio s
        INNER JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
        WHERE s.id = ? AND s.estado = 'completada'
    ");
    $stmt->execute([$solicitudId]);
    $trip = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$trip) {
        throw new Exception('Solicitud no encontrada o no completada');
    }
    
    // Verificar que el usuario es el cliente de esta solicitud
    if ($trip['cliente_id'] != $usuarioId) {
        throw new Exception('No tienes permiso para calificar esta solicitud');
    }
    
    // Verificar si ya existe una calificación
    $stmt = $db->prepare("
        SELECT id FROM calificaciones 
        WHERE solicitud_id = ? AND usuario_calificador_id = ?
    ");
    $stmt->execute([$solicitudId, $usuarioId]);
    
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
            comentario,
            fecha_creacion
        ) VALUES (?, ?, ?, ?, ?, NOW())
    ");
    
    $stmt->execute([
        $solicitudId,
        $usuarioId,
        $trip['conductor_id'],
        $calificacion,
        $comentario
    ]);
    
    // Actualizar calificación promedio del conductor
    $stmt = $db->prepare("
        UPDATE detalles_conductor dc
        SET calificacion_promedio = (
            SELECT AVG(c.calificacion)
            FROM calificaciones c
            WHERE c.usuario_calificado_id = ?
        )
        WHERE usuario_id = ?
    ");
    $stmt->execute([$trip['conductor_id'], $trip['conductor_id']]);
    
    // NUEVO: Actualizar historial de confianza si el servicio existe
    if ($useConfianza) {
        try {
            $confianzaService = new ConfianzaService();
            $confianzaService->actualizarHistorialDespuesDeCalificacion(
                $solicitudId,
                $usuarioId,
                $trip['conductor_id'],
                $calificacion
            );
        } catch (Exception $e) {
            // Fallar silenciosamente - la calificación ya se guardó
            error_log("Error actualizando historial de confianza: " . $e->getMessage());
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Calificación registrada exitosamente'
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
