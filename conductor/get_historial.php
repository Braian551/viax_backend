<?php
/**
 * get_historial.php
 * Obtiene el historial de viajes del conductor con DESGLOSE COMPLETO de precios
 * 
 * Este endpoint incluye:
 * - Todos los recargos aplicados (festivo, hora pico, nocturno, espera)
 * - Comisión REAL de la empresa (no hardcodeada)
 * - Ganancia real del conductor
 */

header('Content-Type: application/json');
$viaxOrigin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
$viaxAllowedOrigins = ['https://viaxcol.online', 'https://www.viaxcol.online'];
if ($viaxOrigin !== '' && in_array($viaxOrigin, $viaxAllowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $viaxOrigin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type, Accept');

require_once '../config/database.php';

$canonicalGuardPath = __DIR__ . '/../services/canonical_pricing_guard.php';
if (is_file($canonicalGuardPath)) {
    require_once $canonicalGuardPath;
}

if (!function_exists('canonicalPricingTrackingValid')) {
    function canonicalPricingTrackingValid(float $distanceKm, int $durationSeconds): bool
    {
        return $distanceKm > 0.1 && $durationSeconds > 30;
    }
}

if (!function_exists('canonicalPricingResolve')) {
    function canonicalPricingResolve(
        float $dynamicPrice,
        float $estimatedPrice,
        bool $trackingValid,
        array $options = []
    ): array {
        $dynamicPrice = max(0.0, (float)$dynamicPrice);
        $estimatedPrice = max(0.0, (float)$estimatedPrice);
        $normalizeStep = isset($options['normalize_step']) ? (float)$options['normalize_step'] : 0.0;

        $finalPrice = $trackingValid
            ? max($dynamicPrice, $estimatedPrice)
            : $estimatedPrice;

        if ($normalizeStep > 0) {
            $finalPrice = round($finalPrice / $normalizeStep) * $normalizeStep;
        }

        $attemptedViolation = $trackingValid && $dynamicPrice < $estimatedPrice;
        $floorApplied = !$trackingValid || $attemptedViolation;
        $rule = !$trackingValid
            ? 'tracking_invalid_floor'
            : ($attemptedViolation ? 'floor_enforced' : 'dynamic_valid');

        return [
            'final_price' => max(0.0, round($finalPrice, 2)),
            'rule' => $rule,
            'attempted_violation' => $attemptedViolation,
            'floor_applied' => $floorApplied,
        ];
    }
}

date_default_timezone_set('America/Bogota');

function formatDateTimeColombiaAmPm(?string $value): ?string {
    if (empty($value)) {
        return null;
    }

    try {
        $dt = new DateTimeImmutable($value, new DateTimeZone('America/Bogota'));
        $ampm = strtolower($dt->format('a')) === 'am' ? 'a. m.' : 'p. m.';
        return $dt->format('d/m/Y h:i') . ' ' . $ampm;
    } catch (Throwable $e) {
        return (string)$value;
    }
}

if (!function_exists('to_iso8601')) {
    function to_iso8601(?string $value): ?string {
        if (empty($value)) {
            return null;
        }

        try {
            $value = trim((string)$value);
            $hasTimezone = (bool)preg_match('/(Z|[+\-]\d{2}:\d{2})$/', $value);
            $dt = $hasTimezone
                ? new DateTimeImmutable($value)
                : new DateTimeImmutable($value, new DateTimeZone('UTC'));

            return $dt
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z');
        } catch (Throwable $e) {
            return (string)$value;
        }
    }
}

function canonicalVehicleTypeSqlExpr(string $column): string {
    $clean = "LOWER(REPLACE(REPLACE(COALESCE($column, ''), '_', ''), ' ', ''))";
    return "CASE $clean
        WHEN 'auto' THEN 'carro'
        WHEN 'automovil' THEN 'carro'
        WHEN 'car' THEN 'carro'
        WHEN 'motocarro' THEN 'mototaxi'
        WHEN 'motocarga' THEN 'mototaxi'
        ELSE $clean
    END";
}

function hasColumn(PDO $db, string $table, string $column): bool
{
    $sql = "SELECT 1
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = :table
              AND column_name = :column
            LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':table' => $table,
        ':column' => $column,
    ]);
    return (bool) $stmt->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

try {
    $database = new Database();
    $db = $database->getConnection();

    $conductor_id = isset($_GET['conductor_id']) ? intval($_GET['conductor_id']) : 0;
    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
    $offset = ($page - 1) * $limit;

    $hasPrecioFijo = hasColumn($db, 'solicitudes_servicio', 'precio_fijo');
    $hasTrackingValido = hasColumn($db, 'solicitudes_servicio', 'tracking_valido');
    $hasPricingRuleApplied = hasColumn($db, 'solicitudes_servicio', 'pricing_rule_applied');
    $hasPriceFinalCanonical = hasColumn($db, 'solicitudes_servicio', 'price_final_canonical');

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

    if ($conductor_id <= 0) {
        throw new Exception('ID de conductor inválido');
    }

    // Contar total de viajes
    $query_count = "SELECT COUNT(*) as total
                    FROM solicitudes_servicio s
                    INNER JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
                    WHERE ac.conductor_id = :conductor_id
                    AND s.estado IN ('completada', 'entregado')";
    
    $stmt_count = $db->prepare($query_count);
    $stmt_count->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
    $stmt_count->execute();
    $total = $stmt_count->fetch(PDO::FETCH_ASSOC)['total'];

    // Obtener historial con DESGLOSE COMPLETO del tracking
    $query = "SELECT 
                s.id,
                s.cliente_id,
                s.tipo_servicio,
                s.tipo_vehiculo,
                s.estado,
                s.empresa_id,
                -- Distancia REAL
                COALESCE(
                    NULLIF(vrt.distancia_real_km, 0),
                    NULLIF(s.distancia_recorrida, 0)
                ) as distancia_km,
                -- Tiempo en SEGUNDOS
                COALESCE(
                    NULLIF(vrt.tiempo_real_minutos, 0) * 60,
                    CASE 
                        WHEN s.completado_en IS NOT NULL AND s.aceptado_en IS NOT NULL 
                        THEN EXTRACT(EPOCH FROM (s.completado_en - s.aceptado_en))
                        ELSE NULL
                    END
                ) as duracion_segundos,
                s.tiempo_estimado as duracion_estimada,
                s.distancia_estimada,
                s.solicitado_en as fecha_solicitud,
                s.completado_en as fecha_completado,
                s.aceptado_en as fecha_aceptado,
                s.direccion_recogida as origen,
                s.direccion_destino as destino,
                s.precio_estimado,
                $precioFijoExpr
                s.precio_final,
                $trackingValidoExpr
                $pricingRuleExpr
                $priceFinalCanonicalExpr
                s.metodo_pago,
                s.pago_confirmado,
                s.desglose_precio,
                u.nombre as cliente_nombre,
                u.apellido as cliente_apellido,
                u.telefono as cliente_telefono,
                u.email as cliente_email,
                c.calificacion,
                c.comentarios,
                -- DATOS DEL TRACKING CON DESGLOSE COMPLETO
                vrt.distancia_real_km as tracking_distancia,
                vrt.tiempo_real_minutos as tracking_tiempo,
                vrt.precio_final_aplicado as tracking_precio,
                vrt.solicitud_id as tracking_id,
                -- Desglose de precio del tracking
                vrt.tarifa_base,
                vrt.precio_distancia,
                vrt.precio_tiempo,
                vrt.recargo_nocturno,
                vrt.recargo_hora_pico,
                vrt.recargo_festivo,
                vrt.recargo_espera,
                vrt.tiempo_espera_min,
                -- Comisión REAL de la empresa
                vrt.comision_plataforma_porcentaje,
                vrt.comision_plataforma_valor,
                                vrt.ganancia_conductor
              FROM solicitudes_servicio s
              INNER JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
              INNER JOIN usuarios u ON s.cliente_id = u.id
              LEFT JOIN calificaciones c ON s.id = c.solicitud_id AND c.usuario_calificado_id = :conductor_id2
              LEFT JOIN viaje_resumen_tracking vrt ON s.id = vrt.solicitud_id
              WHERE ac.conductor_id = :conductor_id
              AND s.estado IN ('completada', 'entregado')
              ORDER BY s.id DESC
              LIMIT :limit OFFSET :offset";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
    $stmt->bindParam(':conductor_id2', $conductor_id, PDO::PARAM_INT);
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $viajes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $viajes = array_map(function($viaje) use ($hasTrackingValido) {
        $distanciaKm = (float)($viaje['distancia_km'] ?? 0.0);
        $duracionSegundos = isset($viaje['duracion_segundos'])
            ? (int)round((float)$viaje['duracion_segundos'])
            : 0;

        $precioEstimado = max(0.0, (float)($viaje['precio_estimado'] ?? 0.0));
        $precioFijo = isset($viaje['precio_fijo']) ? (float)$viaje['precio_fijo'] : 0.0;
        $precioEstimadoMinimo = $precioFijo > 0 ? $precioFijo : $precioEstimado;

        $trackingValido = ($hasTrackingValido && $viaje['tracking_valido_guard'] !== null)
            ? (bool)$viaje['tracking_valido_guard']
            : canonicalPricingTrackingValid($distanciaKm, $duracionSegundos);

        $precioDinamico = 0.0;
        if (isset($viaje['tracking_precio']) && (float)$viaje['tracking_precio'] > 0) {
            $precioDinamico = (float)$viaje['tracking_precio'];
        } elseif (isset($viaje['precio_final']) && (float)$viaje['precio_final'] > 0) {
            $precioDinamico = (float)$viaje['precio_final'];
        } else {
            $precioDinamico = $precioEstimadoMinimo;
        }

        $guardDecision = canonicalPricingResolve(
            $precioDinamico,
            $precioEstimadoMinimo,
            $trackingValido,
            [
                'trip_id' => (int)($viaje['id'] ?? 0),
                'source' => 'get_historial',
                'emit_audit' => false,
                'emit_alerts' => false,
                'normalize_step' => 0.0,
            ]
        );

        $precioCanonicalPersistido = isset($viaje['price_final_canonical'])
            ? (float)$viaje['price_final_canonical']
            : 0.0;
        $precioReal = $precioCanonicalPersistido > 0
            ? $precioCanonicalPersistido
            : (float)$guardDecision['final_price'];

        $pricingRuleApplied = !empty($viaje['pricing_rule_applied'])
            ? (string)$viaje['pricing_rule_applied']
            : (string)$guardDecision['rule'];

        $trackingGanancia = max(0.0, (float)($viaje['ganancia_conductor'] ?? 0.0));
        $comisionPorcentaje = max(0.0, (float)($viaje['comision_plataforma_porcentaje'] ?? 0.0));
        $comisionValor = 0.0;
        $comisionEsperada = ($comisionPorcentaje > 0 && $precioReal > 0)
            ? ($precioReal * ($comisionPorcentaje / 100.0))
            : 0.0;

        if ($comisionEsperada > 0) {
            $comisionValor = $comisionEsperada;
        } elseif (isset($viaje['comision_plataforma_valor']) && (float)$viaje['comision_plataforma_valor'] > 0) {
            $comisionValor = (float)$viaje['comision_plataforma_valor'];
        } elseif ($trackingGanancia > 0 && $precioReal > 0) {
            $comisionValor = max(0.0, $precioReal - $trackingGanancia);
        }

        $comisionValor = min(max(0.0, $comisionValor), max(0.0, $precioReal));

        $gananciaViaje = max(0.0, $precioReal - $comisionValor);
        if ($gananciaViaje <= 0 && $trackingValido && $trackingGanancia > 0) {
            $gananciaViaje = $trackingGanancia;
        }

        if ($gananciaViaje > $precioReal) {
            $gananciaViaje = max(0.0, $precioReal);
        }

        // Construir desglose de precio
        $desglose = null;

        // Primero intentar obtener del campo desglose_precio (JSON)
        if (!empty($viaje['desglose_precio'])) {
            $desgloseJson = is_string($viaje['desglose_precio'])
                ? json_decode($viaje['desglose_precio'], true)
                : $viaje['desglose_precio'];
            if ($desgloseJson && is_array($desgloseJson)) {
                $desglose = $desgloseJson;
            }
        }

        // Si no hay desglose en JSON, construir desde columnas del tracking
        if (!$desglose && isset($viaje['tarifa_base'])) {
            $desglose = [
                'tarifa_base' => (float)($viaje['tarifa_base'] ?? 0),
                'precio_distancia' => (float)($viaje['precio_distancia'] ?? 0),
                'precio_tiempo' => (float)($viaje['precio_tiempo'] ?? 0),
                'recargo_nocturno' => (float)($viaje['recargo_nocturno'] ?? 0),
                'recargo_hora_pico' => (float)($viaje['recargo_hora_pico'] ?? 0),
                'recargo_festivo' => (float)($viaje['recargo_festivo'] ?? 0),
                'recargo_espera' => (float)($viaje['recargo_espera'] ?? 0),
                'tiempo_espera_minutos' => (float)($viaje['tiempo_espera_min'] ?? 0),
                'subtotal_antes_minimo' => 0,
                'aplico_minimo' => false,
            ];

            // Calcular subtotal
            $desglose['subtotal_antes_minimo'] =
                $desglose['tarifa_base'] +
                $desglose['precio_distancia'] +
                $desglose['precio_tiempo'] +
                $desglose['recargo_nocturno'] +
                $desglose['recargo_hora_pico'] +
                $desglose['recargo_festivo'] +
                $desglose['recargo_espera'];
        }

        if (is_array($desglose)) {
            $desglose['precio_final'] = round($precioReal, 2);
            $desglose['precio_estimado_minimo'] = round($precioEstimadoMinimo, 2);
            $desglose['tracking_valido'] = $trackingValido;
            $desglose['pricing_rule_applied'] = $pricingRuleApplied;
            $desglose['comision_porcentaje'] = round($comisionPorcentaje, 2);
            $desglose['comision_valor'] = round($comisionValor, 2);
            $desglose['ganancia_conductor'] = round($gananciaViaje, 2);
        }

        return [
            'id' => (int)$viaje['id'],
            'cliente_id' => isset($viaje['cliente_id']) ? (int)$viaje['cliente_id'] : null,
            'tipo_servicio' => $viaje['tipo_servicio'],
            'tipo_vehiculo' => $viaje['tipo_vehiculo'],
            'estado' => $viaje['estado'],
            // Distancia real
            'distancia_km' => round($distanciaKm, 2),
            'distancia_estimada' => $viaje['distancia_estimada'] ? (float)$viaje['distancia_estimada'] : null,
            // Duración
            'duracion_segundos' => $duracionSegundos,
            'duracion_minutos' => $duracionSegundos ? (int)ceil($duracionSegundos / 60) : null,
            'duracion_estimada' => $viaje['duracion_estimada'] ? (int)$viaje['duracion_estimada'] : null,
            // Fechas - Convertir a ISO8601 UTC para que el cliente convierta a hora local
            'fecha_solicitud' => to_iso8601($viaje['fecha_solicitud']),
            'fecha_completado' => to_iso8601($viaje['fecha_completado']),
            'fecha_aceptado' => to_iso8601($viaje['fecha_aceptado'] ?? null),
            // Fechas formateadas Colombia (12h a. m./p. m.)
            'fecha_solicitud_colombia' => formatDateTimeColombiaAmPm($viaje['fecha_solicitud'] ?? null),
            'fecha_completado_colombia' => formatDateTimeColombiaAmPm($viaje['fecha_completado'] ?? null),
            'fecha_aceptado_colombia' => formatDateTimeColombiaAmPm($viaje['fecha_aceptado'] ?? null),
            // Ubicaciones
            'origen' => $viaje['origen'],
            'destino' => $viaje['destino'],
            // Cliente
            'cliente_nombre' => $viaje['cliente_nombre'],
            'cliente_apellido' => $viaje['cliente_apellido'],
            'cliente_telefono' => $viaje['cliente_telefono'] ?? null,
            'cliente_email' => $viaje['cliente_email'] ?? null,
            'calificacion' => $viaje['calificacion'] ? (int)$viaje['calificacion'] : null,
            'comentario' => $viaje['comentarios'],
            // Precios
            'precio_estimado' => round($precioEstimado, 0),
            'precio_estimado_minimo' => round($precioEstimadoMinimo, 0),
            'precio_final' => round($precioReal, 0),
            'price_final_canonical' => round($precioReal, 2),
            'pricing_rule_applied' => $pricingRuleApplied,
            'tracking_valido' => $trackingValido,
            'metodo_pago' => $viaje['metodo_pago'] ?? 'efectivo',
            'pago_confirmado' => (bool)$viaje['pago_confirmado'],
            // DESGLOSE COMPLETO DEL PRECIO
            'desglose_precio' => $desglose,
            // Ganancias y comisiones (REALES)
            'comision_porcentaje' => round($comisionPorcentaje, 2),
            'comision_empresa' => round($comisionValor, 0),
            'ganancia_viaje' => round($gananciaViaje, 0)
        ];
    }, $viajes);

    echo json_encode([
        'success' => true,
        'viajes' => $viajes,
        'pagination' => [
            'page' => (int)$page,
            'limit' => (int)$limit,
            'total' => (int)$total,
            'total_pages' => (int)ceil($total / $limit)
        ],
        'message' => 'Historial obtenido exitosamente'
    ], JSON_NUMERIC_CHECK);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'viajes' => []
    ]);
}
?>
