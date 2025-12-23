<?php
/**
 * Endpoint para confirmar pago en efectivo.
 * 
 * POST /rating/confirm_cash_payment.php
 * 
 * Body:
 * - solicitud_id: int
 * - conductor_id: int
 * - monto: float (opcional, usa precio_final si no se proporciona)
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
    
    $solicitudId = $data['solicitud_id'] ?? null;
    $conductorId = $data['conductor_id'] ?? null;
    $monto = $data['monto'] ?? null;
    
    if (!$solicitudId || !$conductorId) {
        throw new Exception('Se requiere solicitud_id y conductor_id');
    }
    
    $database = new Database();
    $db = $database->getConnection();
    $db->beginTransaction();
    
    // Verificar que la solicitud existe y pertenece al conductor
    $stmt = $db->prepare("
        SELECT s.id, s.estado, s.precio_final, s.precio_estimado, s.cliente_id, ac.conductor_id
        FROM solicitudes_servicio s
        INNER JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
        WHERE s.id = ? AND ac.conductor_id = ?
    ");
    $stmt->execute([$solicitudId, $conductorId]);
    $solicitud = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$solicitud) {
        throw new Exception('Solicitud no encontrada o no autorizada');
    }
    
    // Determinar el monto del viaje
    $montoTotal = $monto ?? $solicitud['precio_final'] ?? $solicitud['precio_estimado'] ?? 0;
    $montoConductor = $montoTotal * 0.90; // 90% para el conductor
    $comisionPlataforma = $montoTotal * 0.10; // 10% comisión
    
    // Actualizar estado de pago en solicitud
    $stmt = $db->prepare("
        UPDATE solicitudes_servicio 
        SET pago_confirmado = TRUE,
            pago_confirmado_en = NOW(),
            metodo_pago = 'efectivo',
            precio_final = CASE WHEN precio_final IS NULL OR precio_final = 0 THEN ? ELSE precio_final END,
            conductor_confirma_recibo = TRUE
        WHERE id = ?
    ");
    $stmt->execute([$montoTotal, $solicitudId]);
    
    // Verificar si ya existe una transacción
    $stmtCheck = $db->prepare("SELECT id FROM transacciones WHERE solicitud_id = ?");
    $stmtCheck->execute([$solicitudId]);
    
    if (!$stmtCheck->fetch()) {
        // Crear transacción
        $stmt = $db->prepare("
            INSERT INTO transacciones (
                solicitud_id, cliente_id, conductor_id, 
                monto_total, monto_conductor, comision_plataforma,
                metodo_pago, estado, estado_pago,
                fecha_transaccion, completado_en
            ) VALUES (?, ?, ?, ?, ?, ?, 'efectivo', 'completada', 'completado', NOW(), NOW())
        ");
        $stmt->execute([
            $solicitudId, 
            $solicitud['cliente_id'], 
            $conductorId,
            $montoTotal,
            $montoConductor,
            $comisionPlataforma
        ]);
    }
    
    // Registrar en pagos_viaje
    $stmt = $db->prepare("
        INSERT INTO pagos_viaje (solicitud_id, conductor_id, cliente_id, monto, metodo_pago, estado, confirmado_en)
        VALUES (?, ?, ?, ?, 'efectivo', 'confirmado', NOW())
        ON CONFLICT (solicitud_id) DO UPDATE SET 
            estado = 'confirmado', 
            confirmado_en = NOW(),
            monto = EXCLUDED.monto
    ");
    $stmt->execute([$solicitudId, $conductorId, $solicitud['cliente_id'], $montoTotal]);
    
    // Actualizar ganancias del conductor
    $stmt = $db->prepare("
        UPDATE detalles_conductor 
        SET ganancias_totales = COALESCE(ganancias_totales, 0) + ?,
            total_viajes = COALESCE(total_viajes, 0) + 1
        WHERE usuario_id = ?
    ");
    $stmt->execute([$montoConductor, $conductorId]);
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Pago confirmado correctamente',
        'monto_total' => $montoTotal,
        'monto_conductor' => $montoConductor,
        'comision_plataforma' => $comisionPlataforma
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
