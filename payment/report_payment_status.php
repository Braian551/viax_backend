<?php
/**
 * Endpoint para reportar estado de pago.
 * 
 * POST /payment/report_payment_status.php
 * 
 * Body:
 * {
 *   "solicitud_id": 123,
 *   "usuario_id": 456,
 *   "tipo_usuario": "cliente" | "conductor",
 *   "confirma_pago": true | false
 * }
 * 
 * Si hay desacuerdo (cliente dice que pagó, conductor dice que no):
 * - Se crea una disputa
 * - Se penalizan ambos usuarios (no pueden usar la app)
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
    
    $solicitudId = $input['solicitud_id'] ?? null;
    $usuarioId = $input['usuario_id'] ?? null;
    $tipoUsuario = $input['tipo_usuario'] ?? null; // 'cliente' o 'conductor'
    $confirmaPago = $input['confirma_pago'] ?? null;
    
    if (!$solicitudId || !$usuarioId || !$tipoUsuario || $confirmaPago === null) {
        throw new Exception('Datos incompletos');
    }
    
    if (!in_array($tipoUsuario, ['cliente', 'conductor'])) {
        throw new Exception('Tipo de usuario inválido');
    }
    
    $database = new Database();
    $db = $database->getConnection();
    $db->beginTransaction();
    
    // Obtener datos del viaje
    $stmt = $db->prepare("
        SELECT 
            s.id,
            s.cliente_id,
            s.cliente_confirma_pago,
            s.conductor_confirma_recibo,
            s.tiene_disputa,
            s.disputa_id,
            ac.conductor_id
        FROM solicitudes_servicio s
        LEFT JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
        WHERE s.id = ?
    ");
    $stmt->execute([$solicitudId]);
    $viaje = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$viaje) {
        throw new Exception('Viaje no encontrado');
    }
    
    // Verificar que el usuario corresponde al viaje
    if ($tipoUsuario === 'cliente' && $viaje['cliente_id'] != $usuarioId) {
        throw new Exception('Usuario no autorizado');
    }
    if ($tipoUsuario === 'conductor' && $viaje['conductor_id'] != $usuarioId) {
        throw new Exception('Usuario no autorizado');
    }
    
    // Actualizar confirmación según tipo de usuario
    $campo = $tipoUsuario === 'cliente' ? 'cliente_confirma_pago' : 'conductor_confirma_recibo';
    
    $stmt = $db->prepare("
        UPDATE solicitudes_servicio 
        SET $campo = ?
        WHERE id = ?
    ");
    $stmt->execute([$confirmaPago ? 1 : 0, $solicitudId]);
    
    // Obtener estados actualizados
    $stmt = $db->prepare("
        SELECT cliente_confirma_pago, conductor_confirma_recibo 
        FROM solicitudes_servicio 
        WHERE id = ?
    ");
    $stmt->execute([$solicitudId]);
    $estados = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $clienteConfirma = (bool) $estados['cliente_confirma_pago'];
    $conductorConfirma = (bool) $estados['conductor_confirma_recibo'];
    
    $resultado = [
        'success' => true,
        'cliente_confirma' => $clienteConfirma,
        'conductor_confirma' => $conductorConfirma,
        'hay_disputa' => false,
        'disputa_id' => null,
        'mensaje' => ''
    ];
    
    // Verificar si hay disputa (ambos han respondido y hay desacuerdo)
    // Disputa: Cliente dice que SÍ pagó, Conductor dice que NO recibió
    if ($clienteConfirma === true && $conductorConfirma === false) {
        // Verificar que ambos ya confirmaron (no es solo uno)
        // Para saber si el conductor ya respondió, verificamos si hay un registro previo
        // En este caso, si el conductor puso false, ya respondió
        
        // Crear disputa si no existe
        if (!$viaje['tiene_disputa']) {
            $stmt = $db->prepare("
                INSERT INTO disputas_pago (
                    solicitud_id,
                    cliente_id,
                    conductor_id,
                    cliente_confirma_pago,
                    conductor_confirma_recibo,
                    estado,
                    creado_en
                ) VALUES (?, ?, ?, TRUE, FALSE, 'activa', NOW())
                RETURNING id
            ");
            $stmt->execute([$solicitudId, $viaje['cliente_id'], $viaje['conductor_id']]);
            $disputaId = $stmt->fetchColumn();
            
            // Actualizar solicitud con disputa
            $stmt = $db->prepare("
                UPDATE solicitudes_servicio 
                SET tiene_disputa = TRUE,
                    disputa_id = ?
                WHERE id = ?
            ");
            $stmt->execute([$disputaId, $solicitudId]);
            
            // Penalizar ambos usuarios
            $stmt = $db->prepare("
                UPDATE usuarios 
                SET tiene_disputa_activa = TRUE,
                    disputa_activa_id = ?
                WHERE id IN (?, ?)
            ");
            $stmt->execute([$disputaId, $viaje['cliente_id'], $viaje['conductor_id']]);
            
            $resultado['hay_disputa'] = true;
            $resultado['disputa_id'] = $disputaId;
            $resultado['mensaje'] = 'Se ha creado una disputa de pago. Ambos usuarios están penalizados hasta resolver.';
        } else {
            $resultado['hay_disputa'] = true;
            $resultado['disputa_id'] = $viaje['disputa_id'];
            $resultado['mensaje'] = 'Ya existe una disputa activa para este viaje.';
        }
    }
    // Caso: ambos confirman que SÍ hubo pago
    else if ($clienteConfirma === true && $conductorConfirma === true) {
        $resultado['mensaje'] = 'Pago confirmado por ambas partes.';
        
        // Obtener precio del viaje para crear la transacción
        $stmtPrecio = $db->prepare("SELECT precio_final, precio_estimado FROM solicitudes_servicio WHERE id = ?");
        $stmtPrecio->execute([$solicitudId]);
        $precioData = $stmtPrecio->fetch(PDO::FETCH_ASSOC);
        $montoTotal = $precioData['precio_final'] > 0 ? $precioData['precio_final'] : $precioData['precio_estimado'];
        
        // Crear transacción si no existe
        $stmtCheckTx = $db->prepare("SELECT id FROM transacciones WHERE solicitud_id = ?");
        $stmtCheckTx->execute([$solicitudId]);
        if (!$stmtCheckTx->fetch()) {
            $montoConductor = $montoTotal * 0.90; // 90% para el conductor
            $comisionPlataforma = $montoTotal * 0.10; // 10% comisión
            
            $stmtTx = $db->prepare("
                INSERT INTO transacciones (
                    solicitud_id, cliente_id, conductor_id, 
                    monto_total, monto_conductor, comision_plataforma,
                    metodo_pago, estado, estado_pago,
                    fecha_transaccion, completado_en
                ) VALUES (?, ?, ?, ?, ?, ?, 'efectivo', 'completada', 'completado', NOW(), NOW())
            ");
            $stmtTx->execute([
                $solicitudId, 
                $viaje['cliente_id'], 
                $viaje['conductor_id'],
                $montoTotal,
                $montoConductor,
                $comisionPlataforma
            ]);
            
            // Actualizar ganancias del conductor en detalles_conductor
            $stmtGanancias = $db->prepare("
                UPDATE detalles_conductor 
                SET ganancias_totales = COALESCE(ganancias_totales, 0) + ?,
                    total_viajes = COALESCE(total_viajes, 0) + 1
                WHERE usuario_id = ?
            ");
            $stmtGanancias->execute([$montoConductor, $viaje['conductor_id']]);
            
            // Registrar en pagos_viaje
            $stmtPago = $db->prepare("
                INSERT INTO pagos_viaje (solicitud_id, conductor_id, cliente_id, monto, metodo_pago, estado, confirmado_en)
                VALUES (?, ?, ?, ?, 'efectivo', 'confirmado', NOW())
                ON CONFLICT (solicitud_id) DO UPDATE SET estado = 'confirmado', confirmado_en = NOW()
            ");
            $stmtPago->execute([$solicitudId, $viaje['conductor_id'], $viaje['cliente_id'], $montoTotal]);
            
            // Marcar pago como confirmado en solicitud
            $stmtConfirmar = $db->prepare("
                UPDATE solicitudes_servicio 
                SET pago_confirmado = TRUE, pago_confirmado_en = NOW()
                WHERE id = ?
            ");
            $stmtConfirmar->execute([$solicitudId]);
            
            $resultado['transaccion_creada'] = true;
            $resultado['monto_conductor'] = $montoConductor;
        }
        
        // Si había disputa, resolverla
        if ($viaje['tiene_disputa']) {
            $stmt = $db->prepare("
                UPDATE disputas_pago 
                SET estado = 'resuelta_ambos',
                    resuelto_en = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$viaje['disputa_id']]);
            
            // Quitar penalización
            $stmt = $db->prepare("
                UPDATE usuarios 
                SET tiene_disputa_activa = FALSE,
                    disputa_activa_id = NULL
                WHERE disputa_activa_id = ?
            ");
            $stmt->execute([$viaje['disputa_id']]);
            
            $stmt = $db->prepare("
                UPDATE solicitudes_servicio 
                SET tiene_disputa = FALSE
                WHERE id = ?
            ");
            $stmt->execute([$solicitudId]);
        }
    }
    // Caso: esperando respuesta del otro
    else {
        $esperando = $tipoUsuario === 'cliente' ? 'conductor' : 'cliente';
        $resultado['mensaje'] = "Esperando confirmación del $esperando.";
    }
    
    $db->commit();
    echo json_encode($resultado);
    
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
