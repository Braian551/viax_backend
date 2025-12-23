<?php
/**
 * Endpoint para verificar si un usuario tiene disputa activa.
 * 
 * GET /payment/check_dispute_status.php?usuario_id=123
 * 
 * Retorna información de la disputa si existe.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

try {
    $usuarioId = $_GET['usuario_id'] ?? null;
    
    if (!$usuarioId) {
        throw new Exception('Se requiere usuario_id');
    }
    
    $database = new Database();
    $db = $database->getConnection();
    
    // Verificar si el usuario tiene disputa activa
    $stmt = $db->prepare("
        SELECT 
            u.tiene_disputa_activa,
            u.disputa_activa_id,
            d.solicitud_id,
            d.cliente_id,
            d.conductor_id,
            d.cliente_confirma_pago,
            d.conductor_confirma_recibo,
            d.estado,
            d.creado_en,
            s.direccion_recogida,
            s.direccion_destino,
            s.distancia_estimada,
            uc.nombre as cliente_nombre,
            ucon.nombre as conductor_nombre
        FROM usuarios u
        LEFT JOIN disputas_pago d ON u.disputa_activa_id = d.id
        LEFT JOIN solicitudes_servicio s ON d.solicitud_id = s.id
        LEFT JOIN usuarios uc ON d.cliente_id = uc.id
        LEFT JOIN usuarios ucon ON d.conductor_id = ucon.id
        WHERE u.id = ?
    ");
    $stmt->execute([$usuarioId]);
    $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$resultado) {
        echo json_encode([
            'success' => true,
            'tiene_disputa' => false,
            'disputa' => null
        ]);
        exit();
    }
    
    $tieneDisputa = (bool) $resultado['tiene_disputa_activa'];
    
    if (!$tieneDisputa) {
        echo json_encode([
            'success' => true,
            'tiene_disputa' => false,
            'disputa' => null
        ]);
        exit();
    }
    
    // Determinar rol del usuario en la disputa
    $esCliente = $resultado['cliente_id'] == $usuarioId;
    $esConductor = $resultado['conductor_id'] == $usuarioId;
    
    // Calcular precio del viaje
    $distancia = floatval($resultado['distancia_estimada'] ?? 0);
    $precio = 4500 + ($distancia * 1200);
    
    echo json_encode([
        'success' => true,
        'tiene_disputa' => true,
        'disputa' => [
            'id' => $resultado['disputa_activa_id'],
            'solicitud_id' => $resultado['solicitud_id'],
            'estado' => $resultado['estado'],
            'creado_en' => $resultado['creado_en'],
            'mi_rol' => $esCliente ? 'cliente' : 'conductor',
            'cliente' => [
                'id' => $resultado['cliente_id'],
                'nombre' => $resultado['cliente_nombre'],
                'confirma_pago' => (bool) $resultado['cliente_confirma_pago']
            ],
            'conductor' => [
                'id' => $resultado['conductor_id'],
                'nombre' => $resultado['conductor_nombre'],
                'confirma_recibo' => (bool) $resultado['conductor_confirma_recibo']
            ],
            'viaje' => [
                'origen' => $resultado['direccion_recogida'],
                'destino' => $resultado['direccion_destino'],
                'precio' => $precio
            ],
            'mensaje' => $esCliente 
                ? 'El conductor reporta que no recibió el pago. Tu cuenta está suspendida hasta resolver esta disputa.'
                : 'El cliente afirma que pagó. Tu cuenta está suspendida hasta resolver esta disputa.'
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
