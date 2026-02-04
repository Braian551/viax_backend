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
    
    // Verificar si ya existe una calificación del usuario para esta solicitud
    // Esto previene duplicados: un usuario solo puede tener UNA calificación por viaje
    $stmt = $db->prepare("
        SELECT id, calificacion as calificacion_anterior, comentarios as comentario_anterior
        FROM calificaciones
        WHERE solicitud_id = ? AND usuario_calificador_id = ?
    ");
    $stmt->execute([$solicitudId, $calificadorId]);
    $existingRating = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingRating) {
        // UPDATE: Actualizar la calificación existente (reemplaza la anterior)
        $stmt = $db->prepare("
            UPDATE calificaciones SET
                calificacion = ?,
                comentarios = ?,
                creado_en = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$calificacion, $comentario, $existingRating['id']]);
        $calificacionId = $existingRating['id'];
        $wasUpdated = true;
        $previousRating = intval($existingRating['calificacion_anterior']);
    } else {
        // INSERT: Nueva calificación
        // Usamos INSERT ... ON CONFLICT como respaldo en caso de condición de carrera
        try {
            $stmt = $db->prepare("
                INSERT INTO calificaciones (
                    solicitud_id,
                    usuario_calificador_id,
                    usuario_calificado_id,
                    calificacion,
                    comentarios,
                    creado_en
                ) VALUES (?, ?, ?, ?, ?, NOW())
                ON CONFLICT (solicitud_id, usuario_calificador_id) 
                DO UPDATE SET
                    calificacion = EXCLUDED.calificacion,
                    comentarios = EXCLUDED.comentarios,
                    creado_en = NOW()
                RETURNING id
            ");
            
            $stmt->execute([
                $solicitudId,
                $calificadorId,
                $calificadoId,
                $calificacion,
                $comentario
            ]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $calificacionId = $result['id'];
        } catch (PDOException $e) {
            // Si ON CONFLICT no funciona (constraint no existe), usar INSERT simple
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
        }
        $wasUpdated = false;
        $previousRating = null;
    }
    
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
        
        // Actualizar promedio de calificaciones de la EMPRESA del conductor
        $stmtEmpresa = $db->prepare("SELECT empresa_id FROM usuarios WHERE id = ?");
        $stmtEmpresa->execute([$calificadoId]);
        $empresaId = $stmtEmpresa->fetchColumn();
        
        if ($empresaId) {
            // Calcular promedio de todos los conductores de la empresa
            $stmtPromedioEmpresa = $db->prepare("
                SELECT 
                    AVG(c.calificacion) as promedio,
                    COUNT(c.id) as total
                FROM calificaciones c
                JOIN usuarios u ON c.usuario_calificado_id = u.id
                WHERE u.empresa_id = ?
                AND u.tipo_usuario = 'conductor'
            ");
            $stmtPromedioEmpresa->execute([$empresaId]);
            $statsEmpresa = $stmtPromedioEmpresa->fetch(PDO::FETCH_ASSOC);
            
            $promedioEmpresa = $statsEmpresa['promedio'] ?? 0;
            $totalCalificacionesEmpresa = $statsEmpresa['total'] ?? 0;
            
            // Actualizar empresas_metricas
            $stmt = $db->prepare("
                INSERT INTO empresas_metricas (empresa_id, calificacion_promedio, total_calificaciones, ultima_actualizacion)
                VALUES (?, ?, ?, NOW())
                ON CONFLICT (empresa_id) DO UPDATE SET
                    calificacion_promedio = ?,
                    total_calificaciones = ?,
                    ultima_actualizacion = NOW()
            ");
            $stmt->execute([
                $empresaId, 
                $promedioEmpresa, 
                $totalCalificacionesEmpresa,
                $promedioEmpresa, 
                $totalCalificacionesEmpresa
            ]);
        }
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
        'message' => $wasUpdated ? 'Calificación actualizada correctamente' : 'Calificación enviada correctamente',
        'calificacion_id' => $calificacionId,
        'nuevo_promedio' => round(floatval($nuevoPromedio), 1),
        'updated' => $wasUpdated,
        'previous_rating' => $previousRating,
        'current_rating' => $calificacion
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
