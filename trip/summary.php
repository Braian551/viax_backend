<?php
/**
 * API: Resumen canónico del viaje (métricas congeladas)
 * Endpoint sugerido: GET /trip/{id}/summary
 * Alternativa compatible: GET /trip/summary.php?trip_id=123
 *
 * Este endpoint SIEMPRE retorna métricas finales congeladas en backend.
 * No calcula métricas dinámicas para evitar inconsistencias por latencia.
 */

header('Content-Type: application/json');
// CORS seguro: solo dominios controlados; requests sin Origin (app movil) se permiten.
$allowedOrigins = [
    'https://viaxcol.online',
    'https://www.viaxcol.online',
];
$origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
} elseif ($origin !== '') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CORS blocked']);
    exit();
}
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../conductor/tracking/tracking_schema_helpers.php';

function parseTripIdFromRequest(): int
{
    if (isset($_GET['trip_id'])) {
        return intval($_GET['trip_id']);
    }
    if (isset($_GET['solicitud_id'])) {
        return intval($_GET['solicitud_id']);
    }

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if (is_string($path) && preg_match('#/trip/(\d+)/summary$#', $path, $m)) {
        return intval($m[1]);
    }

    return 0;
}

function positiveFloatOrNull($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    $parsed = floatval($value);
    return $parsed > 0 ? $parsed : null;
}

function positiveIntOrNull($value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    $parsed = intval($value);
    return $parsed > 0 ? $parsed : null;
}

function maxFloatOrZero(array $values): float
{
    $filtered = array_values(array_filter($values, static fn($value) => $value !== null && $value > 0));
    if (empty($filtered)) {
        return 0.0;
    }
    return (float) max($filtered);
}

function maxIntOrZero(array $values): int
{
    $filtered = array_values(array_filter($values, static fn($value) => $value !== null && $value > 0));
    if (empty($filtered)) {
        return 0;
    }
    return (int) max($filtered);
}

function decodeJsonObject($value): ?array
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_array($value)) {
        return $value;
    }

    if (!is_string($value)) {
        return null;
    }

    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : null;
}

function numericOrNull($value)
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_numeric($value)) {
        return null;
    }
    return $value + 0;
}

try {
    $tripId = parseTripIdFromRequest();
    if ($tripId <= 0) {
        throw new Exception('trip_id es requerido');
    }

    $database = new Database();
    $db = $database->getConnection();

    $hasMetricsLocked = trackingColumnExists($db, 'solicitudes_servicio', 'metrics_locked');
    $hasDistanceFinal = trackingColumnExists($db, 'solicitudes_servicio', 'distance_final');
    $hasDurationFinal = trackingColumnExists($db, 'solicitudes_servicio', 'duration_final');
    $hasPriceFinalEn = trackingColumnExists($db, 'solicitudes_servicio', 'price_final');
    $hasCompletedAt = trackingColumnExists($db, 'solicitudes_servicio', 'completed_at');
    $hasFinalizedAt = trackingColumnExists($db, 'solicitudes_servicio', 'finalized_at');

    $metricsLockedExpr = $hasMetricsLocked
        ? 'COALESCE(s.metrics_locked, FALSE) AS metrics_locked,'
        : 'FALSE AS metrics_locked,';

    $distanceFinalExpr = $hasDistanceFinal
        ? 's.distance_final,'
        : 'NULL::numeric AS distance_final,';

    $durationFinalExpr = $hasDurationFinal
        ? 's.duration_final,'
        : 'NULL::integer AS duration_final,';

    $priceFinalEnExpr = $hasPriceFinalEn
        ? 's.price_final AS price_final_en,'
        : 'NULL::numeric AS price_final_en,';

    $completedAtExpr = $hasCompletedAt
        ? 's.completed_at,'
        : 'NULL::timestamp AS completed_at,';

    $finalizedAtExpr = $hasFinalizedAt
        ? 's.finalized_at,'
        : 'NULL::timestamp AS finalized_at,';

    $stmt = $db->prepare(" 
        SELECT
            s.id,
            s.estado,
            $metricsLockedExpr
            s.distancia_recorrida,
            s.tiempo_transcurrido,
            s.precio_final,
            s.precio_en_tracking,
            s.desglose_precio,
            $distanceFinalExpr
            $durationFinalExpr
            $priceFinalEnExpr
            $completedAtExpr
            $finalizedAtExpr
            s.completado_en,
            vrt.distancia_real_km,
            vrt.tiempo_real_minutos,
            vrt.precio_final_aplicado,
            vrt.tarifa_base,
            vrt.precio_distancia,
            vrt.precio_tiempo,
            vrt.recargo_nocturno,
            vrt.recargo_hora_pico,
            vrt.recargo_festivo,
            vrt.recargo_espera,
            vrt.tiempo_espera_min,
            vrt.descuento_distancia_larga,
            vrt.subtotal_sin_recargos,
            vrt.total_recargos,
            vrt.tipo_recargo,
            vrt.aplico_tarifa_minima,
            vrt.comision_plataforma_porcentaje,
            vrt.comision_plataforma_valor,
            vrt.ganancia_conductor,
            vrt.comision_admin_porcentaje,
            vrt.comision_admin_valor,
            vrt.ganancia_empresa,
            vrt.fin_viaje_real,
            vrt.actualizado_en
        FROM solicitudes_servicio s
        LEFT JOIN viaje_resumen_tracking vrt ON vrt.solicitud_id = s.id
        WHERE s.id = :trip_id
        LIMIT 1
    ");
    $stmt->execute([':trip_id' => $tripId]);
    $trip = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$trip) {
        throw new Exception('Viaje no encontrado');
    }

    $terminal = trackingIsTerminalState($trip['estado'] ?? null);
    $locked = !empty($trip['metrics_locked']) && intval($trip['metrics_locked']) === 1;

    if (!$terminal && !$locked) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'El viaje aún no tiene métricas finales congeladas',
            'trip_id' => $tripId,
            'estado' => $trip['estado'] ?? null,
        ]);
        exit();
    }

    // IMPORTANTE:
    // En resumen terminal priorizamos siempre las metricas congeladas canonicas.
    // NO se usa maximo para evitar que valores legacy estimados pisen el final real.
    $distanceKm = numericOrNull($trip['distance_final'] ?? null);
    if (!$locked && ($distanceKm === null || floatval($distanceKm) <= 0)) {
        $distanceKm = maxFloatOrZero([
            positiveFloatOrNull($trip['distancia_real_km'] ?? null),
            positiveFloatOrNull($trip['distancia_recorrida'] ?? null),
        ]);
    } elseif ($distanceKm === null) {
        $distanceKm = 0.0;
    }

    $durationSec = numericOrNull($trip['duration_final'] ?? null);
    if (!$locked && ($durationSec === null || intval($durationSec) <= 0)) {
        $durationSec = maxIntOrZero([
            positiveIntOrNull(isset($trip['tiempo_real_minutos']) ? intval($trip['tiempo_real_minutos']) * 60 : null),
            positiveIntOrNull($trip['tiempo_transcurrido'] ?? null),
        ]);
    } elseif ($durationSec === null) {
        $durationSec = 0;
    } else {
        $durationSec = max(0, intval($durationSec));
    }

    $price = numericOrNull($trip['price_final_en'] ?? null);
    if (!$locked && ($price === null || floatval($price) <= 0)) {
        $price = maxFloatOrZero([
            positiveFloatOrNull($trip['precio_en_tracking'] ?? null),
            positiveFloatOrNull($trip['precio_final_aplicado'] ?? null),
            positiveFloatOrNull($trip['precio_final'] ?? null),
        ]);
    } elseif ($price === null) {
        $price = 0.0;
    } else {
        $price = max(0, floatval($price));
    }

    $completedAt = $trip['finalized_at']
        ?? $trip['completed_at']
        ?? $trip['completado_en']
        ?? $trip['fin_viaje_real']
        ?? $trip['actualizado_en']
        ?? gmdate('c');

    $breakdown = decodeJsonObject($trip['desglose_precio'] ?? null);
    if (!$breakdown) {
        $breakdown = [
            'tarifa_base' => round(floatval($trip['tarifa_base'] ?? 0), 2),
            'precio_distancia' => round(floatval($trip['precio_distancia'] ?? 0), 2),
            'precio_tiempo' => round(floatval($trip['precio_tiempo'] ?? 0), 2),
            'recargo_nocturno' => round(floatval($trip['recargo_nocturno'] ?? 0), 2),
            'recargo_hora_pico' => round(floatval($trip['recargo_hora_pico'] ?? 0), 2),
            'recargo_festivo' => round(floatval($trip['recargo_festivo'] ?? 0), 2),
            'recargo_espera' => round(floatval($trip['recargo_espera'] ?? 0), 2),
            'tiempo_espera_min' => round(floatval($trip['tiempo_espera_min'] ?? 0), 2),
            'descuento_distancia_larga' => round(floatval($trip['descuento_distancia_larga'] ?? 0), 2),
            'subtotal_sin_recargos' => round(floatval($trip['subtotal_sin_recargos'] ?? 0), 2),
            'total_recargos' => round(floatval($trip['total_recargos'] ?? 0), 2),
            'tipo_recargo' => $trip['tipo_recargo'] ?? null,
            'aplico_tarifa_minima' => !empty($trip['aplico_tarifa_minima']),
            'comision_plataforma_porcentaje' => round(floatval($trip['comision_plataforma_porcentaje'] ?? 0), 2),
            'comision_plataforma_valor' => round(floatval($trip['comision_plataforma_valor'] ?? 0), 2),
            'ganancia_conductor' => round(floatval($trip['ganancia_conductor'] ?? 0), 2),
            'comision_admin_porcentaje' => round(floatval($trip['comision_admin_porcentaje'] ?? 0), 2),
            'comision_admin_valor' => round(floatval($trip['comision_admin_valor'] ?? 0), 2),
            'ganancia_empresa' => round(floatval($trip['ganancia_empresa'] ?? 0), 2),
            'precio_final' => round($price, 2),
        ];
    }

    echo json_encode([
        'success' => true,
        'trip_id' => intval($trip['id']),
        'distance_km' => round($distanceKm, 3),
        'duration_sec' => $durationSec,
        'price' => round($price, 2),
        'breakdown' => $breakdown,
        'completed_at' => $completedAt,
        'source' => 'backend_frozen_metrics',
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
