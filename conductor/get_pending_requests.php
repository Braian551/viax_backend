<?php
/**
 * Endpoint: Obtener Solicitudes Pendientes para Conductor
 * 
 * Filtra solicitudes por:
 * - Tipo de vehículo del conductor
 * - Empresa del conductor  
 * - Distancia al punto de recogida
 * - Estado pendiente y tiempo de solicitud
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
    exit();
}

require_once '../config/database.php';
require_once '../core/Cache.php';
require_once '../services/driver_service.php';
require_once '../services/upfront_pricing_service.php';
require_once __DIR__ . '/driver_auth.php';
require_once __DIR__ . '/request_viewing_cache.php';

function conductorPendingLegacyColumnExists(PDO $db, string $tableName, string $columnName): bool
{
    static $cache = [];
    $key = strtolower(trim($tableName)) . '.' . strtolower(trim($columnName));
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $stmt = $db->prepare(" 
        SELECT EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = :table_name
              AND column_name = :column_name
        )
    ");
    $stmt->execute([
        ':table_name' => strtolower(trim($tableName)),
        ':column_name' => strtolower(trim($columnName)),
    ]);

    $cache[$key] = (bool)$stmt->fetchColumn();
    return $cache[$key];
}

function conductorPendingLegacyTableExists(PDO $db, string $tableName): bool
{
    static $cache = [];
    $key = strtolower(trim($tableName));
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $stmt = $db->prepare(" 
        SELECT EXISTS (
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = 'public'
              AND table_name = :table_name
        )
    ");
    $stmt->execute([':table_name' => $key]);
    $cache[$key] = (bool)$stmt->fetchColumn();
    return $cache[$key];
}

try {
    // Obtener el ID del conductor
    $conductorId = $_GET['conductor_id'] ?? null;
    
    if (!$conductorId) {
        throw new Exception('ID del conductor requerido');
    }

    // Validación de sesión en lectura de ofertas (modo compatible).
    $sessionToken = driverSessionTokenFromRequest($_GET);
    $session = validateDriverSession((int)$conductorId, $sessionToken, false);
    if (!$session['ok']) {
        throw new Exception($session['message']);
    }
    DriverGeoService::touchDriverHeartbeat((int)$conductorId, 20);
    
    $database = new Database();
    $db = $database->getConnection();
    $hasPrecioFijoColumn = conductorPendingLegacyColumnExists($db, 'solicitudes_servicio', 'precio_fijo');
    $hasRechazosTable = conductorPendingLegacyTableExists($db, 'rechazos_conductor');
    $precioFijoSelectExpr = $hasPrecioFijoColumn
        ? 'COALESCE(s.precio_fijo, 0) AS precio_fijo,'
        : '0::numeric AS precio_fijo,';
    $precioReferenciaExpr = $hasPrecioFijoColumn
        ? 'COALESCE(s.precio_fijo, s.precio_estimado, 0)'
        : 'COALESCE(s.precio_estimado, 0)';
    
    // Verificar que sea un conductor válido y disponible
    // Incluye empresa_id y tipo de vehículo para filtrar solicitudes
    $stmt = $db->prepare(" 
        SELECT u.id, u.nombre, u.apellido, u.empresa_id, dc.disponible, dc.latitud_actual, dc.longitud_actual, dc.vehiculo_tipo
        FROM usuarios u
        INNER JOIN detalles_conductor dc ON u.id = dc.usuario_id
        WHERE u.id = ? 
        AND u.tipo_usuario = 'conductor'
        AND dc.estado_verificacion = 'aprobado'
    ");
    $stmt->execute([$conductorId]);
    $conductor = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$conductor) {
        throw new Exception('Conductor no encontrado o no verificado');
    }
    
    if (!$conductor['disponible']) {
        echo json_encode([
            'success' => true,
            'message' => 'Conductor no disponible',
            'solicitudes' => []
        ]);
        exit;
    }
    
    // Datos del conductor para filtrar
    $conductorVehiculoTipo = $conductor['vehiculo_tipo'];
    $conductorEmpresaId = $conductor['empresa_id'];
    $radioKm = 5.0; // Radio de búsqueda
    
    // Buscar solicitudes cercanas al conductor.
    // Además de `pendiente`, incluir solicitudes recientes en estados sin
    // conductor para permitir recuperación cuando el conductor se conecta tarde.
    // Filtrar por tipo de vehículo y empresa del conductor
    $querySql = "
        SELECT 
            s.id,
            s.cliente_id as usuario_id,
            s.latitud_recogida as latitud_origen,
            s.longitud_recogida as longitud_origen,
            s.direccion_recogida as direccion_origen,
            s.latitud_destino,
            s.longitud_destino,
            s.direccion_destino,
            s.tipo_servicio,
            s.tipo_vehiculo,
            s.empresa_id as solicitud_empresa_id,
            s.precio_estimado,
            $precioFijoSelectExpr
            $precioReferenciaExpr AS precio_referencia,
            s.distancia_estimada as distancia_km,
            s.tiempo_estimado as duracion_minutos,
            s.estado,
            COALESCE(s.solicitado_en, s.fecha_creacion) as fecha_solicitud,
            u.nombre as nombre_usuario,
            u.telefono as telefono_usuario,
            u.foto_perfil as foto_usuario,
            (6371 * acos(
                cos(radians(?)) * cos(radians(s.latitud_recogida)) *
                cos(radians(s.longitud_recogida) - radians(?)) +
                sin(radians(?)) * sin(radians(s.latitud_recogida))
            )) AS distancia_conductor_origen
        FROM solicitudes_servicio s
        INNER JOIN usuarios u ON s.cliente_id = u.id
        WHERE (
            LOWER(TRIM(COALESCE(s.estado, ''))) = 'pendiente'
            OR (
                LOWER(TRIM(COALESCE(s.estado, ''))) IN ('sin_conductores', 'timeout', 'exhausted')
                AND COALESCE(s.solicitado_en, s.fecha_creacion) >= NOW() - INTERVAL '5 minutes'
                AND NOT EXISTS (
                    SELECT 1
                    FROM asignaciones_conductor ac_pend
                    WHERE ac_pend.solicitud_id = s.id
                )
            )
        )
        AND COALESCE(s.solicitado_en, s.fecha_creacion) >= NOW() - INTERVAL '10 minutes'
        AND (6371 * acos(
            cos(radians(?)) * cos(radians(s.latitud_recogida)) *
            cos(radians(s.longitud_recogida) - radians(?)) +
            sin(radians(?)) * sin(radians(s.latitud_recogida))
        )) <= ?
    ";

    if ($hasRechazosTable) {
        $querySql .= "
        AND NOT EXISTS (
            SELECT 1
            FROM rechazos_conductor rc
            WHERE rc.solicitud_id = s.id
              AND rc.conductor_id = ?
        )
        ";
    }

    $querySql .= "
        AND (s.tipo_vehiculo IS NULL OR s.tipo_vehiculo = ?)
        AND (s.empresa_id IS NULL OR s.empresa_id = ?)
        ORDER BY COALESCE(s.solicitado_en, s.fecha_creacion) DESC
        LIMIT 10
    ";

    $stmt = $db->prepare($querySql);
    
    $paramsQuery = [
        $conductor['latitud_actual'],
        $conductor['longitud_actual'],
        $conductor['latitud_actual'],
        $conductor['latitud_actual'],
        $conductor['longitud_actual'],
        $conductor['latitud_actual'],
        $radioKm,
    ];

    if ($hasRechazosTable) {
        $paramsQuery[] = $conductorId;
    }

    $paramsQuery[] = $conductorVehiculoTipo;
    $paramsQuery[] = $conductorEmpresaId;

    $stmt->execute($paramsQuery);
    
    $solicitudes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Filtro extra en Redis para mantener bloqueo por ride tras reconexión.
    try {
        $redis = Cache::redis();
        if ($redis && !empty($solicitudes)) {
            $solicitudes = array_values(array_filter($solicitudes, static function (array $solicitud) use ($redis, $conductorId): bool {
                $rideId = isset($solicitud['id']) ? (int)$solicitud['id'] : 0;
                if ($rideId <= 0) {
                    return false;
                }

                $rejectedDrivers = $redis->sMembers('ride:' . $rideId . ':rejected_drivers');
                if (!is_array($rejectedDrivers)) {
                    $rejectedDrivers = [];
                }

                $rejectedAsStrings = array_map(static fn($value) => (string)$value, $rejectedDrivers);
                $isRejected = in_array((string)$conductorId, $rejectedAsStrings, true);

                error_log('REJECTED_DRIVERS ride ' . $rideId . ': ' . json_encode(array_values($rejectedAsStrings), JSON_UNESCAPED_UNICODE));
                error_log('DRIVER ' . $conductorId . ' filtrado: ' . ($isRejected ? 'SI' : 'NO'));

                return !$isRejected;
            }));
        }
    } catch (Throwable $e) {
        // Filtro secundario; no interrumpe el flujo principal.
    }
    
    // Formatear respuesta con datos adicionales
    $solicitudesFormateadas = array_map(function($s) {
        $precioReferencia = isset($s['precio_referencia']) ? (float)$s['precio_referencia'] : 0.0;
        if ($precioReferencia <= 0) {
            $precioReferencia = 5000 + (((float)$s['distancia_km']) * 2000);
        }
        $precioCanonico = UpfrontPricingService::normalizeCopAmount($precioReferencia, 100);

        return [
            'id' => (int)$s['id'],
            'usuario_id' => (int)$s['usuario_id'],
            'latitud_origen' => (float)$s['latitud_origen'],
            'longitud_origen' => (float)$s['longitud_origen'],
            'direccion_origen' => $s['direccion_origen'],
            'latitud_destino' => (float)$s['latitud_destino'],
            'longitud_destino' => (float)$s['longitud_destino'],
            'direccion_destino' => $s['direccion_destino'],
            'tipo_servicio' => $s['tipo_servicio'],
            'tipo_vehiculo' => $s['tipo_vehiculo'] ?? 'moto',
            'empresa_id' => isset($s['solicitud_empresa_id']) ? (int)$s['solicitud_empresa_id'] : null,
            'distancia_km' => (float)$s['distancia_km'],
            'duracion_minutos' => (int)$s['duracion_minutos'],
            'precio_estimado' => $precioCanonico,
            'precio_fijo' => $precioCanonico,
            'precio_canonico' => $precioCanonico,
            'estado' => $s['estado'],
            'fecha_solicitud' => $s['fecha_solicitud'],
            'nombre_usuario' => $s['nombre_usuario'],
            'telefono_usuario' => $s['telefono_usuario'],
            'foto_usuario' => $s['foto_usuario'],
            'distancia_conductor_origen' => round((float)$s['distancia_conductor_origen'], 2),
        ];
    }, $solicitudes);

    try {
        if (!empty($solicitudesFormateadas)) {
            foreach ($solicitudesFormateadas as $solicitud) {
                publishLegacyDriverViewingState(
                    (int)($solicitud['id'] ?? 0),
                    $conductor,
                    isset($solicitud['distancia_conductor_origen'])
                        ? (float)$solicitud['distancia_conductor_origen']
                        : null
                );
            }
        }
    } catch (Throwable $e) {
        error_log('[get_pending_requests][viewing_cache] ' . $e->getMessage());
    }
    
    echo json_encode([
        'success' => true,
        'total' => count($solicitudesFormateadas),
        'solicitudes' => $solicitudesFormateadas
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
