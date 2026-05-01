<?php
/**
 * Punto de entrada para confirmar pago en efectivo.
 * 
 * POST /rating/confirm_cash_payment.php
 * 
 * Cuerpo (JSON):
 * - solicitud_id: int
 * - conductor_id: int
 * - monto: float (opcional, usa precio_final si no se proporciona)
 */

header('Content-Type: application/json');
$viaxOrigin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
$viaxAllowedOrigins = ['https://viaxcol.online', 'https://www.viaxcol.online'];
if ($viaxOrigin !== '' && in_array($viaxOrigin, $viaxAllowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $viaxOrigin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';
require_once '../core/Cache.php';
require_once '../services/canonical_pricing_guard.php';

function paymentColumnExists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare("SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = :table
          AND column_name = :column
        LIMIT 1");
    $stmt->execute([
        ':table' => $table,
        ':column' => $column,
    ]);
    return (bool)$stmt->fetchColumn();
}

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

    $hasPrecioFijo = paymentColumnExists($db, 'solicitudes_servicio', 'precio_fijo');
    $hasTrackingValido = paymentColumnExists($db, 'solicitudes_servicio', 'tracking_valido');
    $hasPricingRuleApplied = paymentColumnExists($db, 'solicitudes_servicio', 'pricing_rule_applied');
    $hasPriceFinalCanonical = paymentColumnExists($db, 'solicitudes_servicio', 'price_final_canonical');

    $precioFijoExpr = $hasPrecioFijo
        ? 's.precio_fijo,'
        : 'NULL::numeric AS precio_fijo,';
    $trackingValidoExpr = $hasTrackingValido
        ? 's.tracking_valido AS tracking_valido_guard,'
        : 'NULL::boolean AS tracking_valido_guard,';
    $pricingRuleExpr = $hasPricingRuleApplied
        ? 's.pricing_rule_applied,'
        : 'NULL::text AS pricing_rule_applied,';
    $priceFinalCanonicalExpr = $hasPriceFinalCanonical
        ? 's.price_final_canonical,'
        : 'NULL::numeric AS price_final_canonical,';
    
    // Verificar que la solicitud existe y pertenece al conductor
    $stmt = $db->prepare("
        SELECT
            s.id,
            s.estado,
            s.precio_final,
            s.precio_estimado,
            $precioFijoExpr
            $trackingValidoExpr
            $pricingRuleExpr
            $priceFinalCanonicalExpr
            s.distancia_recorrida,
            s.tiempo_transcurrido,
            s.cliente_id,
            ac.conductor_id,
            vrt.precio_final_aplicado AS tracking_precio,
            vrt.distancia_real_km,
            vrt.tiempo_real_minutos,
            vrt.comision_plataforma_porcentaje,
            vrt.comision_plataforma_valor,
            vrt.ganancia_conductor
        FROM solicitudes_servicio s
        INNER JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
        LEFT JOIN viaje_resumen_tracking vrt ON s.id = vrt.solicitud_id
        WHERE s.id = ? AND ac.conductor_id = ?
    ");
    $stmt->execute([$solicitudId, $conductorId]);
    $solicitud = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$solicitud) {
        throw new Exception('Solicitud no encontrada o no autorizada');
    }
    
    // Determinar monto canónico del viaje (nunca por debajo del estimado mínimo).
    $precioEstimado = max(0.0, (float)($solicitud['precio_estimado'] ?? 0));
    $precioFijo = isset($solicitud['precio_fijo']) ? (float)$solicitud['precio_fijo'] : 0.0;
    $precioEstimadoMinimo = $precioFijo > 0 ? $precioFijo : $precioEstimado;

    $distanciaTrackingKm = isset($solicitud['distancia_real_km']) && $solicitud['distancia_real_km'] !== null
        ? (float)$solicitud['distancia_real_km']
        : (float)($solicitud['distancia_recorrida'] ?? 0.0);
    $duracionTrackingSeg = isset($solicitud['tiempo_real_minutos']) && $solicitud['tiempo_real_minutos'] !== null
        ? (int)round(((float)$solicitud['tiempo_real_minutos']) * 60)
        : (int)($solicitud['tiempo_transcurrido'] ?? 0);

    $trackingValido = ($hasTrackingValido && $solicitud['tracking_valido_guard'] !== null)
        ? (bool)$solicitud['tracking_valido_guard']
        : canonicalPricingTrackingValid($distanciaTrackingKm, $duracionTrackingSeg);

    $precioDinamico = $monto !== null ? max(0.0, (float)$monto) : 0.0;
    if ($precioDinamico <= 0 && isset($solicitud['price_final_canonical']) && (float)$solicitud['price_final_canonical'] > 0) {
        $precioDinamico = (float)$solicitud['price_final_canonical'];
    }
    if ($precioDinamico <= 0 && isset($solicitud['tracking_precio']) && (float)$solicitud['tracking_precio'] > 0) {
        $precioDinamico = (float)$solicitud['tracking_precio'];
    }
    if ($precioDinamico <= 0 && isset($solicitud['precio_final']) && (float)$solicitud['precio_final'] > 0) {
        $precioDinamico = (float)$solicitud['precio_final'];
    }
    if ($precioDinamico <= 0) {
        $precioDinamico = $precioEstimadoMinimo;
    }

    $pricingDecision = canonicalPricingResolve(
        $precioDinamico,
        $precioEstimadoMinimo,
        $trackingValido,
        [
            'trip_id' => (int)$solicitudId,
            'source' => 'confirm_cash_payment',
            'redis' => Cache::redis(),
            'emit_audit' => true,
            'emit_alerts' => true,
            'normalize_step' => 100.0,
            'extra' => [
                'monto_override' => $monto !== null,
            ],
        ]
    );

    $montoTotal = (float)$pricingDecision['final_price'];
    $pricingRuleApplied = (string)$pricingDecision['rule'];

    $comisionPorcentaje = max(0.0, (float)($solicitud['comision_plataforma_porcentaje'] ?? 0.0));
    $trackingGanancia = max(0.0, (float)($solicitud['ganancia_conductor'] ?? 0.0));
    $comisionPlataforma = 0.0;

    if (isset($solicitud['comision_plataforma_valor']) && (float)$solicitud['comision_plataforma_valor'] > 0) {
        $comisionPlataforma = (float)$solicitud['comision_plataforma_valor'];
    } elseif ($comisionPorcentaje > 0 && $montoTotal > 0) {
        $comisionPlataforma = $montoTotal * ($comisionPorcentaje / 100.0);
    } elseif ($trackingGanancia > 0 && $montoTotal > $trackingGanancia) {
        $comisionPlataforma = $montoTotal - $trackingGanancia;
    }

    $comisionPlataforma = min(max(0.0, $comisionPlataforma), max(0.0, $montoTotal));

    $montoConductor = ($trackingValido && $trackingGanancia > 0)
        ? $trackingGanancia
        : max(0.0, $montoTotal - $comisionPlataforma);

    if ($montoConductor > $montoTotal) {
        $montoConductor = max(0.0, $montoTotal);
    }
    
    // Actualizar estado de pago en solicitud
    $updateFields = [
        "pago_confirmado = TRUE",
        "pago_confirmado_en = NOW()",
        "metodo_pago = 'efectivo'",
        "precio_final = GREATEST(COALESCE(precio_final, 0), :monto_total)",
        "conductor_confirma_recibo = TRUE",
    ];
    if ($hasPriceFinalCanonical) {
        $updateFields[] = "price_final_canonical = :price_final_canonical";
    }
    if ($hasPricingRuleApplied) {
        $updateFields[] = "pricing_rule_applied = :pricing_rule_applied";
    }
    if ($hasTrackingValido) {
        $updateFields[] = "tracking_valido = :tracking_valido";
    }

    $stmt = $db->prepare("UPDATE solicitudes_servicio SET\n        " . implode(",\n        ", $updateFields) . "\n        WHERE id = :solicitud_id");
    $paramsUpdate = [
        ':monto_total' => $montoTotal,
        ':solicitud_id' => $solicitudId,
    ];
    if ($hasPriceFinalCanonical) {
        $paramsUpdate[':price_final_canonical'] = $montoTotal;
    }
    if ($hasPricingRuleApplied) {
        $paramsUpdate[':pricing_rule_applied'] = $pricingRuleApplied;
    }
    if ($hasTrackingValido) {
        $paramsUpdate[':tracking_valido'] = $trackingValido ? 1 : 0;
    }
    $stmt->execute($paramsUpdate);
    
    // Verificar si ya existe una transacción
    $stmtCheck = $db->prepare("SELECT id FROM transacciones WHERE solicitud_id = ?");
    $stmtCheck->execute([$solicitudId]);
    
    $transaccionExistente = (bool)$stmtCheck->fetch(PDO::FETCH_ASSOC);

    if (!$transaccionExistente) {
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

        // Actualizar ganancias del conductor solo la primera vez.
        $stmt = $db->prepare("
            UPDATE detalles_conductor 
            SET ganancias_totales = COALESCE(ganancias_totales, 0) + ?,
                total_viajes = COALESCE(total_viajes, 0) + 1
            WHERE usuario_id = ?
        ");
        $stmt->execute([$montoConductor, $conductorId]);
    } else {
        // Mantener consistencia canónica en transacciones existentes.
        $stmt = $db->prepare("
            UPDATE transacciones
            SET monto_total = ?,
                monto_conductor = ?,
                comision_plataforma = ?,
                estado = 'completada',
                estado_pago = 'completado',
                metodo_pago = 'efectivo'
            WHERE solicitud_id = ?
        ");
        $stmt->execute([$montoTotal, $montoConductor, $comisionPlataforma, $solicitudId]);
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
    
    canonicalPricingPersistRedisLock(
        Cache::redis(),
        (int)$solicitudId,
        (float)$montoTotal,
        (string)$pricingRuleApplied,
        (bool)$trackingValido,
        86400
    );
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Pago confirmado correctamente',
        'monto_total' => round($montoTotal, 2),
        'monto_conductor' => round($montoConductor, 2),
        'comision_plataforma' => round($comisionPlataforma, 2),
        'pricing_rule_applied' => $pricingRuleApplied,
        'tracking_valido' => $trackingValido,
        'transaccion_creada' => !$transaccionExistente
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
