<?php
/**
 * get_trip_status.php — Estado del viaje con soporte de long-polling.
 *
 * Endpoint consumido por la app Flutter para obtener el estado actual de un
 * viaje/solicitud: ubicación del conductor, ETA, datos de tracking y precios.
 * Soporta long-polling basado en firma SHA-1 para reducir tráfico innecesario.
 *
 * ── Parámetros de entrada (GET) ──────────────────────────────────────
 *   solicitud_id    (obligatorio)  int    — ID del viaje
 *   wait_seconds    (opcional)     int    — timeout de long-poll 0–25 (default 0)
 *   since_signature (opcional)     string — firma SHA-1 de la respuesta anterior
 *
 * ── Respuesta JSON ───────────────────────────────────────────────────
 *   { success: true, meta: { signature, generated_at, wait_seconds },
 *     trip: { id, uuid, estado, conductor, origen, destino, ... } }
 *
 * ── Notas de rendimiento ─────────────────────────────────────────────
 *   • Columnas explícitas (sin SELECT *) — carga ~24 cols en vez de 48+.
 *   • El loop de long-poll usa una query ligera de 4 tablas que solo trae
 *     los 7 campos que afectan la firma. La query completa de 6 JOINs se
 *     ejecuta máximo 2 veces: al entrar y cuando se detecta un cambio.
 *   • Los prepared statements se crean una sola vez y se re-ejecutan.
 *   • Se verifica connection_aborted() en cada iteración para liberar el
 *     worker de PHP-FPM inmediatamente si el cliente se desconecta.
 *   • Arquitectura lista para Redis: cuando esté disponible, la ubicación
 *     del conductor se leerá de cache en vez de la BD en el loop.
 *
 * ── Compatibilidad con Flutter ───────────────────────────────────────
 *   La estructura JSON de respuesta es idéntica a la versión anterior.
 *   No se han eliminado ni renombrado campos.
 */

header('Content-Type: application/json');
$viaxOrigin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
$viaxAllowedOrigins = ['https://viaxcol.online', 'https://www.viaxcol.online'];
if ($viaxOrigin !== '' && in_array($viaxOrigin, $viaxAllowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $viaxOrigin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../conductor/tracking/tracking_schema_helpers.php';

const UI_ROTATION_SEC = 5;

/* ═══════════════════════════════════════════════════════════════════════
 * 1. VALIDACIÓN DE ENTRADA
 *    Todas las entradas $_GET se validan con filter_var antes de usarse.
 *    Los valores inválidos producen 400 Bad Request inmediato.
 * ═══════════════════════════════════════════════════════════════════════ */

/**
 * Valida y limita wait_seconds al rango seguro [0, 25].
 * Valores fuera de rango o no-numéricos se degradan a 0 (sin long-poll).
 */
function parseWaitSeconds($raw): int
{
    $v = filter_var($raw, FILTER_VALIDATE_INT, [
        'options' => ['default' => 0, 'min_range' => 0, 'max_range' => 25],
    ]);
    return ($v !== false) ? $v : 0;
}

/**
 * Sanitiza la firma SHA-1 del cliente.
 * Solo permite caracteres hexadecimales [a-f0-9], máximo 64 chars.
 * Previene inyección de caracteres especiales en comparaciones.
 */
function sanitizeSignature($raw): string
{
    if (!is_string($raw)) {
        return '';
    }
    return substr(preg_replace('/[^a-f0-9]/i', '', $raw), 0, 64);
}

/**
 * Valida solicitud_id: debe ser entero positivo.
 * Responde con 400 y termina ejecución si es inválido.
 */
function validateSolicitudId($raw): int
{
    if ($raw === null || $raw === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'solicitud_id es requerido']);
        exit;
    }
    $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'solicitud_id inválido']);
        exit;
    }
    return $id;
}

/* ═══════════════════════════════════════════════════════════════════════
 * 2. UTILIDADES GEOESPACIALES Y CÁLCULO DE ETA
 *    Funciones puras sin efectos secundarios ni acceso a BD.
 * ═══════════════════════════════════════════════════════════════════════ */

/**
 * Restringe un float al rango [min, max].
 * Usado para limitar velocidades y ETAs a rangos realistas.
 */
function clampFloat(float $v, float $min, float $max): float
{
    return max($min, min($max, $v));
}

/**
 * Distancia Haversine entre dos puntos GPS en kilómetros.
 * Radio terrestre: 6371 km. Precisión suficiente para distancias urbanas.
 *
 * @param float $lat1 Latitud punto A (grados decimales)
 * @param float $lon1 Longitud punto A (grados decimales)
 * @param float $lat2 Latitud punto B (grados decimales)
 * @param float $lon2 Longitud punto B (grados decimales)
 * @return float Distancia en kilómetros
 */
function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $lat1 = deg2rad($lat1);
    $lon1 = deg2rad($lon1);
    $lat2 = deg2rad($lat2);
    $lon2 = deg2rad($lon2);

    $dlat = $lat2 - $lat1;
    $dlon = $lon2 - $lon1;
    $a    = sin($dlat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dlon / 2) ** 2;

    return 6371.0 * 2.0 * atan2(sqrt($a), sqrt(1.0 - $a));
}

/**
 * ETA adaptativo según distancia y velocidad GPS en tiempo real.
 *
 * Usa una velocidad base por banda de distancia, mezclada 65/35 con la
 * velocidad instantánea del GPS. Esto estabiliza el ETA mostrado al
 * usuario evitando saltos bruscos en semáforos o trancones.
 *
 * Bandas de velocidad base:
 *   > 8 km  → 35 km/h (vía rápida)
 *   > 3 km  → 30 km/h (tráfico mixto)
 *   ≥ 0.8km → 28 km/h (zona urbana)
 *   < 0.8km → 18 km/h (última milla, calles angostas)
 *
 * @param float $distanciaKm   Distancia entre conductor y punto de recogida
 * @param float|null $velocidadKmh  Velocidad GPS instantánea (null si no hay dato)
 * @param string $estado       Estado actual del viaje
 * @return int ETA en minutos, mínimo 1, máximo 90
 */
function calcularEtaConduccion(float $distanciaKm, ?float $velocidadKmh, string $estado): int
{
    if ($distanciaKm <= 0) {
        return 0;
    }
    // Si el conductor ya llegó al punto de recogida, ETA mínimo.
    if ($estado === 'conductor_llego') {
        return 1;
    }

    // Velocidad base según banda de distancia.
    if ($distanciaKm > 8) {
        $baseSpeed = 35.0;
    } elseif ($distanciaKm > 3) {
        $baseSpeed = 30.0;
    } elseif ($distanciaKm >= 0.8) {
        $baseSpeed = 28.0;
    } else {
        $baseSpeed = 18.0;
    }

    // Mezcla ponderada: 65% velocidad real + 35% base (si hay GPS válido).
    if ($velocidadKmh !== null && $velocidadKmh > 2.0) {
        $speed = clampFloat(($velocidadKmh * 0.65) + ($baseSpeed * 0.35), 12.0, 55.0);
    } else {
        $speed = $baseSpeed;
    }

    return max(1, min(90, (int) ceil(($distanciaKm / $speed) * 60.0)));
}

/* ═══════════════════════════════════════════════════════════════════════
 * 3. CAPA DE CACHÉ (REDIS)
 *    Intenta conectar a Redis para leer ubicación del conductor desde
 *    cache en vez de la BD durante el loop de long-poll.
 *    Si Redis no está disponible, el sistema sigue operando con BD.
 * ═══════════════════════════════════════════════════════════════════════ */

/**
 * Intenta obtener una conexión Redis. Devuelve null si no está disponible.
 * La conexión se cachea en variable estática para reutilizarse en el request.
 * Timeout de conexión: 150ms para no bloquear si Redis está caído.
 */
function getRedisConnection(): ?object
{
    return Cache::redis();
}

/**
 * Lee la ubicación del conductor desde Redis (si disponible).
 * Clave: driver_location:{conductor_id}
 * Formato almacenado: JSON { lat, lng, speed, ts }
 * TTL esperado: 30 segundos (el conductor envía GPS cada ~5s).
 *
 * @return array|null  ['latitud' => float, 'longitud' => float, 'velocidad' => float|null] o null
 */
function getDriverLocationFromCache(int $conductorId): ?array
{
    $redis = getRedisConnection();
    if (!$redis) {
        return null;
    }

    try {
        $data = $redis->get("driver_location:{$conductorId}");
        if (!$data) {
            return null;
        }
        $parsed = json_decode($data, true);
        if (!is_array($parsed) || !isset($parsed['lat'], $parsed['lng'])) {
            return null;
        }
        return [
            'latitud'   => (float) $parsed['lat'],
            'longitud'  => (float) $parsed['lng'],
            'velocidad' => isset($parsed['speed']) ? (float) $parsed['speed'] : null,
        ];
    } catch (Exception $e) {
        return null;
    }
}

/* ═══════════════════════════════════════════════════════════════════════
 * 4. CAPA DE ACCESO A DATOS (REPOSITORIO)
 *    Queries SQL con columnas explícitas y prepared statements.
 *    Los statements se construyen una vez y se re-ejecutan en el loop.
 * ═══════════════════════════════════════════════════════════════════════ */

/**
 * Query completo del viaje — columnas explícitas (sin SELECT *).
 *
 * Trae solo las 24 columnas de solicitudes_servicio que realmente se usan
 * en la respuesta, más datos del conductor y tracking.
 *
 * La velocidad se obtiene con un subquery correlacionado que aprovecha el
 * índice idx_tracking_rt_solicitud_servidor (solicitud_id, timestamp_servidor DESC).
 *
 * Tablas involucradas (6):
 *   solicitudes_servicio → asignaciones_conductor → usuarios → detalles_conductor
 *   → viaje_resumen_tracking → viaje_tracking_snapshot
 *   + subquery: viaje_tracking_realtime
 */
function buildFullTripStmt(PDO $db): PDOStatement
{
    $hasMetricsLocked = trackingColumnExists($db, 'solicitudes_servicio', 'metrics_locked');
    $hasDistanceFinal = trackingColumnExists($db, 'solicitudes_servicio', 'distance_final');
    $hasDurationFinal = trackingColumnExists($db, 'solicitudes_servicio', 'duration_final');
    $hasFinalizedAt = trackingColumnExists($db, 'solicitudes_servicio', 'finalized_at');
    $hasPriceFinalEn = trackingColumnExists($db, 'solicitudes_servicio', 'price_final');
    $hasPrecioFijo = trackingColumnExists($db, 'solicitudes_servicio', 'precio_fijo');
    $hasPrecioCongelado = trackingColumnExists($db, 'solicitudes_servicio', 'precio_congelado');
    $hasPrecioCalculadoReal = trackingColumnExists($db, 'solicitudes_servicio', 'precio_calculado_real');
    $hasDesviacionPorcentaje = trackingColumnExists($db, 'solicitudes_servicio', 'desviacion_porcentaje');

    $metricsLockedExpr = $hasMetricsLocked
        ? 'COALESCE(s.metrics_locked, FALSE) AS metrics_locked,'
        : 'FALSE AS metrics_locked,';
    $distanceFinalExpr = $hasDistanceFinal
        ? 's.distance_final,'
        : 'NULL::numeric AS distance_final,';
    $durationFinalExpr = $hasDurationFinal
        ? 's.duration_final,'
        : 'NULL::integer AS duration_final,';
    $finalizedAtExpr = $hasFinalizedAt
        ? 's.finalized_at,'
        : 'NULL::timestamp AS finalized_at,';
    $priceFinalEnExpr = $hasPriceFinalEn
        ? 's.price_final AS price_final_en,'
        : 'NULL::numeric AS price_final_en,';
    $precioFijoExpr = $hasPrecioFijo
        ? 's.precio_fijo,'
        : 'NULL::numeric AS precio_fijo,';
    $precioCongeladoExpr = $hasPrecioCongelado
        ? 'COALESCE(s.precio_congelado, FALSE) AS precio_congelado,'
        : 'FALSE AS precio_congelado,';
    $precioCalculadoRealExpr = $hasPrecioCalculadoReal
        ? 's.precio_calculado_real,'
        : 'NULL::numeric AS precio_calculado_real,';
    $desviacionPorcentajeExpr = $hasDesviacionPorcentaje
        ? 's.desviacion_porcentaje,'
        : 'NULL::numeric AS desviacion_porcentaje,';

    return $db->prepare("
        SELECT
            -- Datos del viaje (solicitudes_servicio) --
            s.id,
            s.uuid_solicitud,
            s.estado,
            $metricsLockedExpr
            $distanceFinalExpr
            $durationFinalExpr
            $finalizedAtExpr
            s.tipo_servicio,
            s.empresa_id,
            s.latitud_recogida,
            s.longitud_recogida,
            s.direccion_recogida,
            s.latitud_destino,
            s.longitud_destino,
            s.direccion_destino,
            s.distancia_estimada,
            s.tiempo_estimado,
            s.fecha_creacion,
            s.aceptado_en,
            s.completado_en,
            s.distancia_recorrida,
            s.tiempo_transcurrido,
            s.precio_estimado,
            $precioFijoExpr
            $precioCongeladoExpr
            s.precio_final,
            $priceFinalEnExpr
            $precioCalculadoRealExpr
            $desviacionPorcentajeExpr
            s.precio_en_tracking,
            s.precio_ajustado_por_tracking,
            s.desglose_precio,

            -- Asignación --
            ac.conductor_id,
            ac.estado          AS estado_asignacion,
            ac.asignado_en     AS fecha_asignacion,

            -- Datos personales del conductor --
            u.nombre           AS conductor_nombre,
            u.apellido         AS conductor_apellido,
            u.telefono         AS conductor_telefono,
            u.foto_perfil      AS conductor_foto,

            -- Vehículo y ubicación del conductor --
            dc.vehiculo_tipo,
            dc.vehiculo_marca,
            dc.vehiculo_modelo,
            dc.vehiculo_placa,
            dc.vehiculo_color,
            dc.calificacion_promedio AS conductor_calificacion,
            dc.latitud_actual        AS conductor_latitud,
            dc.longitud_actual       AS conductor_longitud,

            -- Resumen de tracking --
            vrt.distancia_real_km     AS tracking_distancia,
            vrt.tiempo_real_minutos   AS tracking_tiempo,
            vrt.precio_final_aplicado AS tracking_precio,

            -- Snapshot de tracking --
            vts.actualizado_en        AS tracking_actualizado_en,

            -- Velocidad instantánea (último punto GPS del conductor) --
            (SELECT vtr.velocidad
               FROM viaje_tracking_realtime vtr
              WHERE vtr.solicitud_id = s.id
              ORDER BY vtr.timestamp_servidor DESC
              LIMIT 1
            ) AS tracking_velocidad_kmh,

            -- Tiempo calculado desde timestamps (fallback) --
            EXTRACT(EPOCH FROM (s.completado_en - s.aceptado_en)) / 60
                AS tiempo_calculado_min

        FROM solicitudes_servicio s
        LEFT JOIN asignaciones_conductor ac
            ON  ac.solicitud_id = s.id
            AND ac.estado IN ('asignado','llegado','en_curso','completado')
        LEFT JOIN usuarios u
            ON u.id = ac.conductor_id
        LEFT JOIN detalles_conductor dc
            ON dc.usuario_id = ac.conductor_id
        LEFT JOIN viaje_resumen_tracking vrt
            ON vrt.solicitud_id = s.id
        LEFT JOIN viaje_tracking_snapshot vts
            ON vts.solicitud_id = s.id
        WHERE s.id = ?
    ");
}

function parseDesglosePrecio($raw): ?array
{
    if (is_array($raw)) {
        return $raw;
    }

    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    $parsed = json_decode($raw, true);
    return is_array($parsed) ? $parsed : null;
}

function resolveSearchMode(array $trip): string
{
    $empresaId = isset($trip['empresa_id']) && $trip['empresa_id'] !== null && $trip['empresa_id'] !== ''
        ? (int)$trip['empresa_id']
        : 0;
    return $empresaId > 0 ? 'empresa' : 'azar';
}

function fetchDriverCheckingSummary(PDO $db, int $driverId): ?array
{
    static $cache = [];
    if ($driverId <= 0) {
        return null;
    }
    if (array_key_exists($driverId, $cache)) {
        return $cache[$driverId];
    }

    $stmt = $db->prepare("
        SELECT
            u.id,
            u.nombre,
            u.apellido,
            u.empresa_id,
            et.nombre AS empresa_nombre
        FROM usuarios u
        LEFT JOIN empresas_transporte et ON et.id = u.empresa_id
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmt->execute([$driverId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $cache[$driverId] = null;
        return null;
    }

    $nombre = trim((string)($row['nombre'] ?? '') . ' ' . (string)($row['apellido'] ?? ''));
    if ($nombre === '') {
        $nombre = 'Conductor';
    }

    $empresaNombre = trim((string)($row['empresa_nombre'] ?? ''));
    if ($empresaNombre === '') {
        $empresaNombre = 'Libre competencia';
    }

    $cache[$driverId] = [
        'id' => (int)$row['id'],
        'nombre' => $nombre,
        'empresa_id' => isset($row['empresa_id']) && $row['empresa_id'] !== null ? (int)$row['empresa_id'] : null,
        'empresa' => $empresaNombre,
    ];

    return $cache[$driverId];
}

function resolveDriverChecking(PDO $db, array $trip): ?array
{
    $estado = strtolower(trim((string)($trip['estado'] ?? '')));
    if (!in_array($estado, ['pendiente', 'requested'], true)) {
        return null;
    }

    $requestId = (int)($trip['id'] ?? 0);
    if ($requestId <= 0) {
        return null;
    }

    $redis = getRedisConnection();
    if (!$redis) {
        return null;
    }

    $driverId = 0;
    $source = 'current_driver';
    $status = 'pending';
    $shownAt = function_exists('now_colombia')
        ? now_colombia()->format('c')
        : (new DateTime('now', new DateTimeZone('America/Bogota')))->format('c');

    try {
        $currentDriverRaw = $redis->get('ride:' . $requestId . ':current_driver');
        if (is_string($currentDriverRaw) && trim($currentDriverRaw) !== '') {
            $driverId = (int)$currentDriverRaw;
        }

        if ($driverId > 0) {
            $statusRaw = $redis->get('ride:' . $requestId . ':driver:' . $driverId . ':status');
            if (is_string($statusRaw) && trim($statusRaw) !== '') {
                $status = strtolower(trim($statusRaw));
            }
        }
    } catch (Throwable $e) {
        return null;
    }

    if ($driverId <= 0) {
        return null;
    }

    if (!in_array($status, ['pending', 'offered', 'checking'], true)) {
        return null;
    }

    $summary = fetchDriverCheckingSummary($db, $driverId);
    if (!$summary) {
        return null;
    }

    $summary['source'] = $source;
    $summary['shown_at'] = $shownAt;
    $summary['status'] = $status;
    return $summary;
}

function resolveMatchingStatus(array $trip): ?string
{
    $estado = strtolower(trim((string)($trip['estado'] ?? '')));
    if (!in_array($estado, ['pendiente', 'requested'], true)) {
        return null;
    }

    $requestId = (int)($trip['id'] ?? 0);
    if ($requestId <= 0) {
        return 'searching';
    }

    $redis = getRedisConnection();
    if (!$redis) {
        return 'searching';
    }

    try {
        $raw = $redis->get('ride:' . $requestId . ':matching_status');
        if (!is_string($raw) || trim($raw) === '') {
            return 'searching';
        }

        $normalized = strtolower(trim($raw));
        $allowed = [
            'searching',
            'expanding_search',
            'search_expanded',
            'matched',
            'timeout',
            'exhausted',
            'sin_conductores',
        ];

        return in_array($normalized, $allowed, true) ? $normalized : 'searching';
    } catch (Throwable $e) {
        return 'searching';
    }
}

/**
 * Query ligero de detección de cambios para el loop de long-poll.
 *
 * Solo trae los 7 campos que alimentan la firma de cambios en tiempo real.
 * Toca 4 tablas (omite usuarios y snapshot) — aprox. 3× más rápido que
 * el query completo de 6 JOINs.
 *
 * Se ejecuta cada 350ms durante el long-poll. Si la firma no cambia,
 * no se ejecuta el query completo.
 */
function buildChangeDetectStmt(PDO $db): PDOStatement
{
    return $db->prepare("
        SELECT
            s.estado,
            s.tiempo_transcurrido,
            s.precio_en_tracking,
            dc.latitud_actual   AS conductor_latitud,
            dc.longitud_actual  AS conductor_longitud,
            vrt.distancia_real_km AS tracking_distancia,
            (SELECT vtr.velocidad
               FROM viaje_tracking_realtime vtr
              WHERE vtr.solicitud_id = s.id
              ORDER BY vtr.timestamp_servidor DESC
              LIMIT 1
            ) AS tracking_velocidad_kmh
        FROM solicitudes_servicio s
        LEFT JOIN asignaciones_conductor ac
            ON  ac.solicitud_id = s.id
            AND ac.estado IN ('asignado','llegado','en_curso','completado')
        LEFT JOIN detalles_conductor dc
            ON dc.usuario_id = ac.conductor_id
        LEFT JOIN viaje_resumen_tracking vrt
            ON vrt.solicitud_id = s.id
        WHERE s.id = ?
    ");
}

/**
 * Ejecuta un prepared statement con el ID del viaje y retorna la fila.
 * Reutilizable para ambos statements (completo y ligero).
 */
function fetchRow(PDOStatement $stmt, int $solicitudId): ?array
{
    $stmt->execute([$solicitudId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/* ═══════════════════════════════════════════════════════════════════════
 * 5. LÓGICA DE NEGOCIO
 *    Cálculos derivados: distancia conductor-recogida, ETA, firma,
 *    resolución de valores de tracking con cadena de fallbacks.
 * ═══════════════════════════════════════════════════════════════════════ */

/**
 * Calcula distancia conductor → punto de recogida (km) y ETA (minutos).
 *
 * Primero intenta obtener la ubicación del conductor desde Redis (más
 * fresco que la BD, que solo se actualiza periódicamente). Si Redis no
 * está disponible, usa los datos de detalles_conductor en la BD.
 *
 * @param array $trip  Fila del viaje (con conductor_latitud/longitud de BD)
 * @return array ['distancia_conductor_km' => float|null, 'eta_minutos' => int|null]
 */
function calcularDistanciaYEta(array $trip): array
{
    if (empty($trip['conductor_id'])) {
        return ['distancia_conductor_km' => null, 'eta_minutos' => null];
    }

    // Intentar ubicación fresca desde Redis (si disponible).
    $cached = getDriverLocationFromCache((int) $trip['conductor_id']);

    $conductorLat = $cached['latitud']  ?? ($trip['conductor_latitud']  ?? null);
    $conductorLon = $cached['longitud'] ?? ($trip['conductor_longitud'] ?? null);
    $velocidad    = $cached['velocidad'] ?? (isset($trip['tracking_velocidad_kmh']) ? (float) $trip['tracking_velocidad_kmh'] : null);

    if (empty($conductorLat) || empty($conductorLon)) {
        return ['distancia_conductor_km' => null, 'eta_minutos' => null];
    }

    $distKm = haversineKm(
        (float) $trip['latitud_recogida'],
        (float) $trip['longitud_recogida'],
        (float) $conductorLat,
        (float) $conductorLon
    );

    $eta = calcularEtaConduccion(
        $distKm,
        $velocidad,
        (string) ($trip['estado'] ?? '')
    );

    return ['distancia_conductor_km' => $distKm, 'eta_minutos' => $eta];
}

/**
 * Genera firma SHA-1 de los campos que cambian en tiempo real.
 *
 * Los clientes Flutter envían esta firma como since_signature para que
 * el servidor detecte si hay cambios sin descargar la respuesta completa.
 *
 * Campos incluidos en la firma:
 *   - estado del viaje
 *   - ubicación del conductor (redondeada a 5 decimales ≈ 1.1m)
 *   - velocidad GPS (1 decimal)
 *   - distancia conductor-recogida
 *   - ETA calculado
 *   - distancia real recorrida
 *   - tiempo transcurrido
 *   - precio en tracking
 *
 * @param array $trip         Datos del viaje
 * @param float|null $distConductorKm  Distancia conductor-recogida
 * @param int|null $eta       ETA en minutos
 * @return string Firma SHA-1 de 40 caracteres hexadecimales
 */
function buildRealtimeSignature(array $trip, ?float $distConductorKm, ?int $eta): string
{
    $data = [
        'id'                  => (int) $trip['id'],
        'estado'              => (string) ($trip['estado'] ?? ''),
        'lat'                 => isset($trip['conductor_latitud'])      ? round((float) $trip['conductor_latitud'], 5)      : null,
        'lng'                 => isset($trip['conductor_longitud'])     ? round((float) $trip['conductor_longitud'], 5)     : null,
        'speed'               => isset($trip['tracking_velocidad_kmh']) ? round((float) $trip['tracking_velocidad_kmh'], 1) : null,
        'distancia_conductor' => $distConductorKm !== null ? round($distConductorKm, 2) : null,
        'eta'                 => $eta,
        'distancia_real'      => isset($trip['tracking_distancia'])  ? round((float) $trip['tracking_distancia'], 2)  : 0,
        'tiempo_seg'          => isset($trip['tiempo_transcurrido']) ? (int) $trip['tiempo_transcurrido']              : 0,
        'precio_tracking'     => isset($trip['precio_en_tracking'])  ? round((float) $trip['precio_en_tracking'], 2)  : 0,
        'search_tick'         => null,
    ];

    $estado = strtolower(trim((string)($trip['estado'] ?? '')));
    if (in_array($estado, ['pendiente', 'requested'], true)) {
        $nowTs = function_exists('now_colombia')
            ? now_colombia()->getTimestamp()
            : (new DateTime('now', new DateTimeZone('America/Bogota')))->getTimestamp();
        $createdTsRaw = strtotime((string)($trip['fecha_creacion'] ?? ''));
        $createdTs = $createdTsRaw !== false ? (int)$createdTsRaw : $nowTs;
        $data['search_tick'] = (int) floor(max(0, $nowTs - $createdTs) / UI_ROTATION_SEC);
    }

    return sha1(json_encode($data));
}

/**
 * Resuelve los mejores valores de tracking disponibles con cadena de fallbacks.
 *
 * Para cada métrica (distancia, tiempo, precio) prioriza:
 *   1. viaje_resumen_tracking (datos calculados del sistema de tracking)
 *   2. solicitudes_servicio (datos manuales / de BD)
 *   3. null si no hay dato disponible
 *
 * @param array $trip  Datos del viaje
 * @return array ['distancia' => float|null, 'tiempo_minutos' => int|null, 'precio' => float|null]
 */
function resolveTrackingValues(array $trip): array
{
    // Regla canónica: en terminal + metrics_locked, usar únicamente métricas finales.
    $estado = strtolower(trim((string)($trip['estado'] ?? '')));
    $isTerminal = in_array($estado, [
        'completada', 'completado', 'entregado', 'finalizada', 'finalizado',
        'cancelada', 'cancelado', 'rechazada', 'rechazado', 'rejected',
    ], true);
    $metricsLocked = !empty($trip['metrics_locked']);

    if ($isTerminal) {
        $distanciaFinal = $trip['distance_final'];
        if ($distanciaFinal === null || $distanciaFinal === '') {
            $distanciaFinal = $trip['tracking_distancia'] ?? $trip['distancia_recorrida'] ?? 0;
        }

        $duracionFinalSeg = $trip['duration_final'];
        if ($duracionFinalSeg === null || $duracionFinalSeg === '') {
            if (isset($trip['tracking_tiempo']) && $trip['tracking_tiempo'] !== null && $trip['tracking_tiempo'] !== '') {
                $duracionFinalSeg = (int)$trip['tracking_tiempo'] * 60;
            } elseif (isset($trip['tiempo_transcurrido']) && $trip['tiempo_transcurrido'] !== null && $trip['tiempo_transcurrido'] !== '') {
                $duracionFinalSeg = (int)$trip['tiempo_transcurrido'];
            } else {
                $duracionFinalSeg = 0;
            }
        }

        $precioFinal = $trip['price_final_en'];
        if ($precioFinal === null || $precioFinal === '') {
            $precioFinal = $trip['tracking_precio'] ?? $trip['precio_final'] ?? 0;
        }

        error_log('[CanonicalMetricsUsed] trip_id=' . (int)$trip['id']
            . ' distance_final=' . floatval($distanciaFinal)
            . ' duration_final=' . intval($duracionFinalSeg)
            . ' price_final=' . floatval($precioFinal));

        return [
            'distancia' => (float) $distanciaFinal,
            'tiempo_minutos' => (int) ceil(((int) $duracionFinalSeg) / 60),
            'precio' => (float) $precioFinal,
            'duracion_segundos' => (int) $duracionFinalSeg,
            'tracking_valido' => ((float) $distanciaFinal > 0.1 && (int) $duracionFinalSeg > 30),
            'metrics_locked' => $metricsLocked,
            'terminal' => true,
        ];
    }

    // Modo no terminal: usar cadena legacy/tiempo real para UI en vivo.
    $estimatedKm = isset($trip['distancia_estimada']) ? (float)$trip['distancia_estimada'] : 0.0;

    $tiempoSegBase = 0;
    if (isset($trip['tiempo_transcurrido']) && $trip['tiempo_transcurrido'] !== null && $trip['tiempo_transcurrido'] !== '') {
        $rawTiempo = (float)$trip['tiempo_transcurrido'];
        // En muchos registros legacy este campo viene en minutos; normalizar a segundos.
        $tiempoSegBase = $rawTiempo > 0 ? (int)round($rawTiempo * 60) : 0;
    }

    if (isset($trip['tracking_tiempo']) && $trip['tracking_tiempo'] !== null && $trip['tracking_tiempo'] !== '') {
        $trackingTiempoSeg = (int)round((float)$trip['tracking_tiempo'] * 60);
        if ($trackingTiempoSeg > $tiempoSegBase) {
            $tiempoSegBase = $trackingTiempoSeg;
        }
    }

    $coherenceCapByTime = $tiempoSegBase > 0
        ? (($tiempoSegBase / 3600.0) * 140.0) + 0.2
        : 0.0;
    $coherenceCapByRoute = $estimatedKm > 0
        ? ($estimatedKm * 1.6) + 1.0
        : 120.0;

    $distancia = null;
    if (isset($trip['tracking_distancia']) && $trip['tracking_distancia'] > 0) {
        $candidate = (float) $trip['tracking_distancia'];
        if ($tiempoSegBase > 0) {
            $candidate = min($candidate, $coherenceCapByTime, $coherenceCapByRoute);
            $distancia = max(0.0, $candidate);
        }
    } elseif (isset($trip['distancia_recorrida']) && $trip['distancia_recorrida'] > 0) {
        $candidate = (float) $trip['distancia_recorrida'];
        if ($tiempoSegBase > 0) {
            $candidate = min($candidate, $coherenceCapByTime, $coherenceCapByRoute);
            $distancia = max(0.0, $candidate);
        }
    }

    $tiempo = null;
    if (isset($trip['tracking_tiempo']) && $trip['tracking_tiempo'] > 0) {
        $tiempo = (int) $trip['tracking_tiempo'];
    } elseif (isset($trip['tiempo_transcurrido']) && $trip['tiempo_transcurrido'] > 0) {
        $tiempo = (int) ceil((float)$trip['tiempo_transcurrido']);
    } elseif (isset($trip['tiempo_calculado_min']) && $trip['tiempo_calculado_min'] > 0) {
        $tiempo = (int) ceil($trip['tiempo_calculado_min']);
    }

    $precio = null;
    if (isset($trip['tracking_precio']) && $trip['tracking_precio'] > 0) {
        $precio = (float) $trip['tracking_precio'];
    } elseif (isset($trip['precio_final']) && $trip['precio_final'] > 0) {
        $precio = (float) $trip['precio_final'];
    }

    $duracionSegundos = $tiempo !== null ? ($tiempo * 60) : 0;
    $trackingValido = ($distancia !== null && $distancia > 0.1 && $duracionSegundos > 30);

    return [
        'distancia' => $distancia,
        'tiempo_minutos' => $tiempo,
        'precio' => $precio,
        'duracion_segundos' => $duracionSegundos,
        'tracking_valido' => $trackingValido,
        'metrics_locked' => $metricsLocked,
        'terminal' => $isTerminal,
    ];
}

/* ═══════════════════════════════════════════════════════════════════════
 * 6. CONSTRUCTOR DE RESPUESTA
 *    Ensambla el JSON de respuesta manteniendo la estructura exacta
 *    que espera la app Flutter. NO se deben eliminar ni renombrar campos.
 * ═══════════════════════════════════════════════════════════════════════ */

/**
 * Construye la respuesta JSON completa para la app Flutter.
 *
 * Estructura de respuesta:
 *   {
 *     success: true,
 *     meta: { signature, generated_at, wait_seconds },
 *     trip: {
 *       id, uuid, estado, tipo_servicio,
 *       origen: { latitud, longitud, direccion },
 *       destino: { latitud, longitud, direccion },
 *       distancia_estimada, tiempo_estimado_min, distancia_km, duracion_minutos,
 *       duracion_segundos, fecha_creacion, fecha_aceptado, fecha_completado,
 *       distancia_recorrida, tiempo_transcurrido, tiempo_transcurrido_seg,
 *       precio_estimado, precio_final, precio_en_tracking,
 *       precio_ajustado_por_tracking,
 *       conductor: { id, nombre, telefono, foto, calificacion,
 *                     vehiculo: { tipo, marca, modelo, placa, color },
 *                     ubicacion: { latitud, longitud },
 *                     distancia_km, eta_minutos } | null
 *     }
 *   }
 */
function buildResponse(PDO $db, array $trip, string $signature, int $waitSeconds, ?float $distConductorKm, ?int $eta): array
{
    $tv = resolveTrackingValues($trip);
    $desglosePrecio = parseDesglosePrecio($trip['desglose_precio'] ?? null);
    $searchMode = resolveSearchMode($trip);
    $driverChecking = resolveDriverChecking($db, $trip);
    $matchingStatus = resolveMatchingStatus($trip);

    // Si viene duración canónica explícita, usarla tal cual para evitar recomputar.
    $tiempoSeg = (int) ($tv['duracion_segundos'] ?? 0);
    if ($tiempoSeg <= 0 && isset($trip['tiempo_transcurrido']) && $trip['tiempo_transcurrido'] > 0) {
        $tiempoSeg = (int) $trip['tiempo_transcurrido'];
    } elseif ($tiempoSeg <= 0 && $tv['tiempo_minutos'] !== null) {
        $tiempoSeg = $tv['tiempo_minutos'] * 60;
    }

    // Datos del conductor (null si el viaje no tiene conductor asignado).
    $conductor = null;
    if (!empty($trip['conductor_id'])) {
        $conductor = [
            'id'           => (int) $trip['conductor_id'],
            'nombre'       => trim($trip['conductor_nombre'] . ' ' . $trip['conductor_apellido']),
            'telefono'     => $trip['conductor_telefono'],
            'foto'         => $trip['conductor_foto'],
            'calificacion' => (float) ($trip['conductor_calificacion'] ?? 0),
            'vehiculo'     => [
                'tipo'   => $trip['vehiculo_tipo'],
                'marca'  => $trip['vehiculo_marca'],
                'modelo' => $trip['vehiculo_modelo'],
                'placa'  => $trip['vehiculo_placa'],
                'color'  => $trip['vehiculo_color'],
            ],
            'ubicacion' => [
                'latitud'  => (float) $trip['conductor_latitud'],
                'longitud' => (float) $trip['conductor_longitud'],
            ],
            'distancia_km' => $distConductorKm !== null ? round($distConductorKm, 2) : null,
            'eta_minutos'  => $eta,
        ];
    }

    $surgeMultiplier = 1.0;
    try {
        $redis = Cache::redis();
        if ($redis) {
            $lat = (float)($trip['latitud_recogida'] ?? 0.0);
            $lng = (float)($trip['longitud_recogida'] ?? 0.0);
            $latCell = (int)floor($lat * 200);
            $lngCell = (int)floor($lng * 200);
            $gridId = $latCell . ':' . $lngCell;
            $surgeRaw = $redis->get('surge:grid:' . $gridId);
            $surgePayload = is_string($surgeRaw) ? json_decode($surgeRaw, true) : null;
            if (is_array($surgePayload) && isset($surgePayload['multiplier'])) {
                $surgeMultiplier = max(1.0, (float)$surgePayload['multiplier']);
            }
        }
    } catch (Throwable $e) {}

    return [
        'success' => true,
        'meta' => [
            'signature'    => $signature,
            'generated_at' => function_exists('now_colombia')
                ? now_colombia()->format('c')
                : (new DateTime('now', new DateTimeZone('America/Bogota')))->format('c'),
            'wait_seconds' => $waitSeconds,
            'metrics_locked' => (bool) ($tv['metrics_locked'] ?? false),
            'tracking_valido' => (bool) ($tv['tracking_valido'] ?? false),
            'search_mode' => $searchMode,
            'matching_status' => $matchingStatus,
        ],
        'trip' => [
            'id'                           => (int) $trip['id'],
            'uuid'                         => $trip['uuid_solicitud'],
            'estado'                       => $trip['estado'],
            'tipo_servicio'                => $trip['tipo_servicio'],
            'empresa_id'                   => isset($trip['empresa_id']) && $trip['empresa_id'] !== null ? (int)$trip['empresa_id'] : null,
            'search_mode'                  => $searchMode,
            'matching_status'              => $matchingStatus,
            'driver_checking'              => $driverChecking,
            'origen' => [
                'latitud'   => (float) $trip['latitud_recogida'],
                'longitud'  => (float) $trip['longitud_recogida'],
                'direccion' => $trip['direccion_recogida'],
            ],
            'destino' => [
                'latitud'   => (float) $trip['latitud_destino'],
                'longitud'  => (float) $trip['longitud_destino'],
                'direccion' => $trip['direccion_destino'],
            ],
            'distancia_estimada'           => (float) ($trip['distancia_estimada'] ?? 0),
            'tiempo_estimado_min'          => (int) ($trip['tiempo_estimado'] ?? 0),
            'distancia_km'                 => $tv['distancia'] ?? (float) ($trip['distancia_estimada'] ?? 0),
            'duracion_minutos'             => $tv['tiempo_minutos'] ?? (int) ($trip['tiempo_estimado'] ?? 0),
            'duracion_segundos'            => $tiempoSeg,
            'distance_final'               => isset($trip['distance_final']) && $trip['distance_final'] !== null ? (float) $trip['distance_final'] : ($tv['distancia'] ?? null),
            'duration_final'               => isset($trip['duration_final']) && $trip['duration_final'] !== null ? (int) $trip['duration_final'] : $tiempoSeg,
            'price_final_canonical'        => isset($trip['price_final_en']) && $trip['price_final_en'] !== null ? (float) $trip['price_final_en'] : ($tv['precio'] ?? null),
            'metrics_locked'               => (bool) ($tv['metrics_locked'] ?? false),
            'finalized_at'                 => to_iso8601($trip['finalized_at'] ?? null),
            'fecha_creacion'               => to_iso8601($trip['fecha_creacion']),
            'fecha_aceptado'               => to_iso8601($trip['aceptado_en'] ?? null),
            'fecha_completado'             => to_iso8601($trip['completado_en'] ?? null),
            // Compatibilidad legacy: estos campos se rellenan con canónicos cuando aplica lock.
            'distancia_recorrida'          => $tv['distancia'],
            'tiempo_transcurrido'          => $tv['tiempo_minutos'],
            'tiempo_transcurrido_seg'      => $tiempoSeg,
            'precio_estimado'              => (float) ($trip['precio_estimado'] ?? 0),
            'precio_fijo'                  => isset($trip['precio_fijo']) && $trip['precio_fijo'] !== null ? (float) $trip['precio_fijo'] : (float) ($trip['precio_estimado'] ?? 0),
            'precio_congelado'             => isset($trip['precio_congelado']) ? (bool) $trip['precio_congelado'] : false,
            'precio_final'                 => $tv['precio'] ?? (float) ($trip['precio_estimado'] ?? 0),
            'precio_calculado_real'        => isset($trip['precio_calculado_real']) && $trip['precio_calculado_real'] !== null ? (float) $trip['precio_calculado_real'] : null,
            'desviacion_porcentaje'        => isset($trip['desviacion_porcentaje']) && $trip['desviacion_porcentaje'] !== null ? (float) $trip['desviacion_porcentaje'] : null,
            'precio_en_tracking'           => isset($trip['precio_en_tracking']) ? (float) $trip['precio_en_tracking'] : null,
            'precio_ajustado_por_tracking' => isset($trip['precio_ajustado_por_tracking']) ? (bool) $trip['precio_ajustado_por_tracking'] : false,
            'tracking_valido'              => (bool) ($tv['tracking_valido'] ?? false),
            'desglose_precio'              => $desglosePrecio,
            'conductor'                    => $conductor,
            'pickup_eta_minutes'           => $eta,
            'surge_multiplier'             => round($surgeMultiplier, 2),
            'driver_distance'              => $distConductorKm !== null ? round($distConductorKm, 2) : null,
        ],
    ];
}

/* ═══════════════════════════════════════════════════════════════════════
 * 7. LONG-POLLING EN TIEMPO REAL
 *    Mantiene la conexión abierta hasta que la firma del viaje cambie
 *    o se agote el timeout. Optimizado para mínimo uso de CPU y BD.
 * ═══════════════════════════════════════════════════════════════════════ */

/**
 * Espera hasta que la firma en tiempo real del viaje cambie o se agote
 * el deadline, lo que ocurra primero.
 *
 * Estrategia de rendimiento:
 *   1. Usa query ligero de 4 tablas (no la query completa de 6 JOINs).
 *   2. La query completa solo se ejecuta cuando se detecta un cambio real.
 *   3. Aborta inmediatamente si el cliente se desconecta (connection_aborted).
 *   4. Duerme 350ms entre iteraciones para balancear latencia vs. CPU.
 *   5. Cuando Redis esté disponible, la ubicación del conductor se leerá
 *      de cache en vez de la BD, reduciendo la carga del loop a ~1 query
 *      ligero cada 350ms.
 *
 * Cálculo de carga a escala:
 *   Con 1000 viajes activos en long-poll de 20s:
 *   - Sin Redis: ~57 queries ligeros/viaje × 1000 = 57,000 queries/20s
 *   - Con Redis: ~57 queries ligeros pero 3 tablas en vez de 4 (sin dc)
 *
 * @param PDOStatement $fullStmt     Query completo preparado
 * @param PDOStatement $changeStmt   Query ligero preparado
 * @param array $trip                Datos actuales del viaje
 * @param string $sinceSignature     Firma del cliente
 * @param int $waitSeconds           Timeout máximo
 * @param int $solicitudId           ID del viaje
 * @return array Datos actualizados del viaje (o los originales si no cambió)
 */
function waitForChange(
    PDOStatement $fullStmt,
    PDOStatement $changeStmt,
    array $trip,
    string $sinceSignature,
    int $waitSeconds,
    int $solicitudId
): array {
    if ($waitSeconds <= 0 || $sinceSignature === '') {
        return $trip;
    }

    $deadline = microtime(true) + $waitSeconds;

    // Permitir que PHP detecte desconexiones del cliente.
    ignore_user_abort(false);

    while (microtime(true) < $deadline) {
        // Liberar worker si el cliente se fue.
        if (connection_aborted()) {
            break;
        }

        usleep(350000); // 350 ms — balance entre latencia y uso de CPU.

        // Verificación ligera: solo campos que afectan la firma.
        $delta = fetchRow($changeStmt, $solicitudId);
        if (!$delta) {
            break; // Viaje eliminado de BD.
        }

        // Mezclar datos ligeros con los estáticos para calcular firma.
        $merged  = array_merge($trip, $delta);
        $distEta = calcularDistanciaYEta($merged);
        $sig     = buildRealtimeSignature($merged, $distEta['distancia_conductor_km'], $distEta['eta_minutos']);

        if ($sig !== $sinceSignature) {
            // Cambio detectado — hacer un fetch completo y retornar.
            $updated = fetchRow($fullStmt, $solicitudId);
            return $updated ?? $trip;
        }
    }

    return $trip;
}

/* ═══════════════════════════════════════════════════════════════════════
 * 8. EJECUCIÓN PRINCIPAL
 *    Flujo: Validar → Conectar BD → Fetch → Long-poll → Responder
 * ═══════════════════════════════════════════════════════════════════════ */

try {
    // ── Validar entrada ─────────────────────────────────────────────
    $solicitudId    = validateSolicitudId($_GET['solicitud_id'] ?? null);
    $waitSeconds    = parseWaitSeconds($_GET['wait_seconds'] ?? 0);
    $sinceSignature = sanitizeSignature($_GET['since_signature'] ?? '');

    // ── Conexión a base de datos ────────────────────────────────────
    $database = new Database();
    $db       = $database->getConnection();

    // ── Preparar statements (se reutilizan en el loop) ──────────────
    $fullStmt   = buildFullTripStmt($db);
    $changeStmt = buildChangeDetectStmt($db);

    // ── Fetch inicial del viaje ─────────────────────────────────────
    $trip = fetchRow($fullStmt, $solicitudId);
    if (!$trip) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Solicitud no encontrada']);
        exit;
    }

    // ── Long-polling (no-op si wait_seconds == 0) ───────────────────
    $trip = waitForChange($fullStmt, $changeStmt, $trip, $sinceSignature, $waitSeconds, $solicitudId);

    // ── Calcular valores derivados ──────────────────────────────────
    $distEta         = calcularDistanciaYEta($trip);
    $distConductorKm = $distEta['distancia_conductor_km'];
    $etaMinutos      = $distEta['eta_minutos'];
    $signature       = buildRealtimeSignature($trip, $distConductorKm, $etaMinutos);

    // ── Enviar respuesta al cliente Flutter ──────────────────────────
    echo json_encode(buildResponse($db, $trip, $signature, $waitSeconds, $distConductorKm, $etaMinutos));

} catch (PDOException $e) {
    // Error de base de datos — loguear internamente, no exponer al cliente.
    error_log('get_trip_status.php PDO Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error de base de datos']);
} catch (Exception $e) {
    // Error genérico — nunca exponer stack traces al cliente.
    error_log('get_trip_status.php Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error interno del servidor']);
}
