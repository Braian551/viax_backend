<?php
/**
 * Endpoint para resolver una disputa (cuando conductor confirma que sí recibió).
 * 
 * POST /payment/resolve_dispute.php
 * 
 * Body:
 * {
 *   "disputa_id": 123,
 *   "conductor_id": 456,
 *   "confirma_recibo": true
 * }
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
    $input = json_decode(file_get_contents('php://input'), true);
    
    $disputaId = $input['disputa_id'] ?? null;
    $conductorId = $input['conductor_id'] ?? null;
    $confirmaRecibo = $input['confirma_recibo'] ?? null;
    
    if (!$disputaId || !$conductorId || $confirmaRecibo === null) {
        throw new Exception('Datos incompletos');
    }
    
    if (!$confirmaRecibo) {
        throw new Exception('Debes confirmar que recibiste el pago para resolver la disputa');
    }
    
    $database = new Database();
    $db = $database->getConnection();
    $db->beginTransaction();
    
    // Verificar que la disputa existe y el conductor es correcto
    $stmt = $db->prepare("
        SELECT d.*, s.id as solicitud_id
        FROM disputas_pago d
        JOIN solicitudes_servicio s ON d.solicitud_id = s.id
        WHERE d.id = ? AND d.conductor_id = ? AND d.estado IN ('activa', 'pendiente')
    ");
    $stmt->execute([$disputaId, $conductorId]);
    $disputa = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$disputa) {
        throw new Exception('Disputa no encontrada o ya resuelta');
    }
    
    // Actualizar disputa como resuelta
    $stmt = $db->prepare("
        UPDATE disputas_pago 
        SET estado = 'resuelta_conductor',
            conductor_confirma_recibo = TRUE,
            resuelto_en = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$disputaId]);
    
    // Actualizar solicitud
    $stmt = $db->prepare("
        UPDATE solicitudes_servicio 
        SET conductor_confirma_recibo = TRUE,
            tiene_disputa = FALSE
        WHERE id = ?
    ");
    $stmt->execute([$disputa['solicitud_id']]);
    
    // Quitar penalización de ambos usuarios
    $stmt = $db->prepare("
        UPDATE usuarios 
        SET tiene_disputa_activa = FALSE,
            disputa_activa_id = NULL
        WHERE disputa_activa_id = ?
    ");
    $stmt->execute([$disputaId]);
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => '¡Disputa resuelta! Ambas cuentas han sido desbloqueadas.',
        'disputa_id' => $disputaId
    ]);
    
} catch (Exception $e) {
    if (isset($db)) {
        $db->rollBack();
    }
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
