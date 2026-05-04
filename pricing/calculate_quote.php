<?php
/**
 * API: Calcular cotización de viaje
 * Endpoint: pricing/calculate_quote.php
 * 
 * Calcula el precio estimado de un viaje basado en:
 * - Distancia en kilómetros
 * - Duración en minutos
 * - Tipo de vehículo
 * - Horario actual (para recargos)
 */

header('Content-Type: application/json');
$viaxOrigin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
$viaxAllowedOrigins = ['https://viaxcol.online', 'https://www.viaxcol.online'];
if ($viaxOrigin !== '' && in_array($viaxOrigin, $viaxAllowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $viaxOrigin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';
require_once '../services/pricing_service.php';

/**
 * Resuelve el surge dinámico para la zona del origen usando la misma clave canónica
 * que consume DynamicPricingService internamente: surge_zone:{zoneKey}.
 *
 * @return array{multiplier: float, level: string, message: string, zone_key: ?string}
 */
function resolveDynamicSurgeForQuote($lat, $lng): array
{
    if ($lat === null || $lng === null) {
        return [
            'multiplier' => 1.0,
            'level' => 'normal',
            'message' => '',
            'zone_key' => null,
        ];
    }

    try {
        $zoneKey = DynamicPricingService::zoneKey((float)$lat, (float)$lng);
        $cached = Cache::get('surge_zone:' . $zoneKey);
        $multiplier = 1.0;

        if (is_string($cached) && is_numeric($cached)) {
            $multiplier = min(2.0, max(1.0, round((float)$cached, 2)));
        }

        return [
            'multiplier' => $multiplier,
            'level' => DynamicPricingService::demandLevel($multiplier),
            'message' => DynamicPricingService::demandMessage($multiplier),
            'zone_key' => $zoneKey,
        ];
    } catch (Throwable $surgeError) {
        error_log('[calculate_quote] surge warning: ' . $surgeError->getMessage());

        return [
            'multiplier' => 1.0,
            'level' => 'normal',
            'message' => '',
            'zone_key' => null,
        ];
    }
}

/**
 * Genera un request_id numérico compatible con el worker Node.
 */
function generateNumericPricingRequestId(): int
{
    $milliseconds = (int)floor(microtime(true) * 1000);
    return ($milliseconds * 1000) + random_int(1, 999);
}

/**
 * Intenta resolver la cotización vía pricing-service Node.
 * Si el servicio no responde dentro de 2 segundos, devuelve null para usar fallback PHP.
 */
function tryResolveQuoteFromPricingNode(array $body): ?array
{
    try {
        $redis = Cache::redis();
        if (!$redis) {
            return null;
        }

        $requestId = generateNumericPricingRequestId();
        $payload = json_encode([
            'request_id' => $requestId,
            'trip_id' => 0,
            'user_id' => (int)($body['user_id'] ?? 0),
            'distancia_km' => (float)($body['distancia_km'] ?? 0),
            'duracion_minutos' => (int)($body['duracion_minutos'] ?? 0),
            'tipo_vehiculo' => (string)($body['tipo_vehiculo'] ?? 'moto'),
            'lat_origen' => isset($body['lat']) ? (float)$body['lat'] : (float)($body['lat_origen'] ?? 0),
            'lng_origen' => isset($body['lng']) ? (float)$body['lng'] : (float)($body['lng_origen'] ?? 0),
            'source' => 'calculate_quote_php',
        ], JSON_UNESCAPED_UNICODE);

        if (!is_string($payload) || $payload === '') {
            return null;
        }

        $start = microtime(true);
        $redis->publish('pricing:quote_queue', $payload);

        $resultKey = 'pricing:quote_result:' . $requestId;
        for ($i = 0; $i < 20; $i++) {
            usleep(100000);
            $raw = $redis->get($resultKey);
            if (!is_string($raw) || trim($raw) === '') {
                continue;
            }

            $decoded = json_decode($raw, true);
            $redis->del($resultKey);

            if (is_array($decoded) && isset($decoded['data'])) {
                $latencyMs = (int)round((microtime(true) - $start) * 1000);
                if (!isset($decoded['data']['latency_ms'])) {
                    $decoded['data']['latency_ms'] = $latencyMs;
                }
                if (!isset($decoded['data']['source'])) {
                    $decoded['data']['source'] = 'pricing_service';
                }
                if (!isset($decoded['source'])) {
                    $decoded['source'] = 'pricing_service';
                }
                $decoded['fallback'] = false;

                return $decoded;
            }

            break;
        }

        error_log('[calculate_quote] node timeout, usando fallback PHP');
    } catch (Throwable $nodeError) {
        error_log('[calculate_quote] node delegation warning: ' . $nodeError->getMessage());
    }

    return null;
}

// Solo permitir POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Método no permitido. Use POST'
    ]);
    exit;
}

try {
    // Obtener datos del request
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validar datos requeridos
    $required_fields = ['distancia_km', 'duracion_minutos', 'tipo_vehiculo'];
    foreach ($required_fields as $field) {
        if (!isset($data[$field])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => "Campo requerido faltante: $field"
            ]);
            exit;
        }
    }
    
    $distancia_km = floatval($data['distancia_km']);
    $duracion_minutos = intval($data['duracion_minutos']);
    $tipo_vehiculo = $data['tipo_vehiculo'];
    $latitud_origen = isset($data['lat'])
        ? floatval($data['lat'])
        : (isset($data['lat_origen']) ? floatval($data['lat_origen']) : null);
    $longitud_origen = isset($data['lng'])
        ? floatval($data['lng'])
        : (isset($data['lng_origen']) ? floatval($data['lng_origen']) : null);
    
    // Validaciones
    if ($distancia_km <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'La distancia debe ser mayor a 0'
        ]);
        exit;
    }
    
    if ($duracion_minutos <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'La duración debe ser mayor a 0'
        ]);
        exit;
    }

    $nodeResult = tryResolveQuoteFromPricingNode($data);
    if ($nodeResult !== null) {
        http_response_code(200);
        echo json_encode($nodeResult, JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Conectar a la base de datos
    $database = new Database();
    $conn = $database->getConnection();
    
    // Obtener configuración activa
    $query = "SELECT * FROM configuracion_precios 
              WHERE tipo_vehiculo = :tipo_vehiculo AND activo = 1 
              LIMIT 1";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':tipo_vehiculo', $tipo_vehiculo);
    $stmt->execute();
    
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$config) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'No se encontró configuración de precios para este vehículo'
        ]);
        exit;
    }
    
    // Verificar límites de distancia
    if ($distancia_km < floatval($config['distancia_minima'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => "La distancia mínima es {$config['distancia_minima']} km"
        ]);
        exit;
    }
    
    if ($distancia_km > floatval($config['distancia_maxima'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => "La distancia máxima es {$config['distancia_maxima']} km"
        ]);
        exit;
    }
    
    // ===================================================
    // CALCULAR PRECIO BASE
    // ===================================================
    
    $tarifa_base = floatval($config['tarifa_base']);
    $precio_distancia = $distancia_km * floatval($config['costo_por_km']);
    $precio_tiempo = $duracion_minutos * floatval($config['costo_por_minuto']);
    
    $subtotal = $tarifa_base + $precio_distancia + $precio_tiempo;
    
    // ===================================================
    // APLICAR DESCUENTO POR DISTANCIA LARGA
    // ===================================================
    
    $descuento_distancia = 0.00;
    if ($distancia_km >= floatval($config['umbral_km_descuento'])) {
        $porcentaje_descuento = floatval($config['descuento_distancia_larga']);
        $descuento_distancia = $subtotal * ($porcentaje_descuento / 100);
    }
    
    $subtotal_con_descuento = $subtotal - $descuento_distancia;
    
    // ===================================================
    // APLICAR RECARGOS POR HORARIO
    // ===================================================
    
    $hora_actual = date('H:i:s');
    $periodo_actual = 'normal';
    $recargo_porcentaje = 0.00;
    $recargo_precio = 0.00;
    
    // Verificar hora pico mañana
    if ($hora_actual >= $config['hora_pico_inicio_manana'] && 
        $hora_actual <= $config['hora_pico_fin_manana']) {
        $periodo_actual = 'hora_pico_manana';
        $recargo_porcentaje = floatval($config['recargo_hora_pico']);
    }
    // Verificar hora pico tarde
    elseif ($hora_actual >= $config['hora_pico_inicio_tarde'] && 
            $hora_actual <= $config['hora_pico_fin_tarde']) {
        $periodo_actual = 'hora_pico_tarde';
        $recargo_porcentaje = floatval($config['recargo_hora_pico']);
    }
    // Verificar horario nocturno
    elseif ($hora_actual >= $config['hora_nocturna_inicio'] || 
            $hora_actual <= $config['hora_nocturna_fin']) {
        $periodo_actual = 'nocturno';
        $recargo_porcentaje = floatval($config['recargo_nocturno']);
    }
    
    // Verificar si es festivo (simplificado - mejorar con tabla de festivos)
    $es_festivo = false; // TODO: Implementar lógica real de festivos
    if ($es_festivo) {
        $periodo_actual = 'festivo';
        $recargo_porcentaje = floatval($config['recargo_festivo']);
    }
    
    if ($recargo_porcentaje > 0) {
        $recargo_precio = $subtotal_con_descuento * ($recargo_porcentaje / 100);
    }
    
    $surgeInfo = resolveDynamicSurgeForQuote($latitud_origen, $longitud_origen);
    $surge_multiplier = floatval($surgeInfo['multiplier']);
    $surge_precio = 0.00;
    if ($surge_multiplier > 1.0) {
        $surge_precio = $subtotal_con_descuento * ($surge_multiplier - 1.0);
    }

    $total = $subtotal_con_descuento + $recargo_precio + $surge_precio;
    
    // ===================================================
    // APLICAR TARIFA MÍNIMA
    // ===================================================
    
    $tarifa_minima = floatval($config['tarifa_minima']);
    if ($total < $tarifa_minima) {
        $total = $tarifa_minima;
    }
    
    // Aplicar tarifa máxima si existe
    if ($config['tarifa_maxima'] !== null) {
        $tarifa_maxima = floatval($config['tarifa_maxima']);
        if ($total > $tarifa_maxima) {
            $total = $tarifa_maxima;
        }
    }
    
    // ===================================================
    // CALCULAR COMISIÓN DE LA PLATAFORMA
    // ===================================================
    
    $comision_plataforma_porcentaje = floatval($config['comision_plataforma']);
    $comision_plataforma = $total * ($comision_plataforma_porcentaje / 100);
    $ganancia_conductor = $total - $comision_plataforma;
    
    // ===================================================
    // PREPARAR RESPUESTA
    // ===================================================
    
    $cotizacion = [
        // Datos del viaje
        'distancia_km' => round($distancia_km, 2),
        'duracion_minutos' => $duracion_minutos,
        'tipo_vehiculo' => $tipo_vehiculo,
        
        // Desglose de precios
        'tarifa_base' => round($tarifa_base, 2),
        'precio_distancia' => round($precio_distancia, 2),
        'precio_tiempo' => round($precio_tiempo, 2),
        'subtotal' => round($subtotal, 2),
        
        // Descuentos
        'descuento_distancia' => round($descuento_distancia, 2),
        'descuento_porcentaje' => $descuento_distancia > 0 ? floatval($config['descuento_distancia_larga']) : 0.00,
        'subtotal_con_descuento' => round($subtotal_con_descuento, 2),
        
        // Recargos
        'periodo_actual' => $periodo_actual,
        'recargo_porcentaje' => round($recargo_porcentaje, 2),
        'recargo_precio' => round($recargo_precio, 2),

        // Surge dinámico por zona
        'surge_multiplier' => round($surge_multiplier, 2),
        'surge_precio' => round($surge_precio, 2),
        'surge_level' => $surgeInfo['level'],
        'surge_message' => $surgeInfo['message'],
        'zone_key' => $surgeInfo['zone_key'],
        
        // Total
        'total' => round($total, 2),
        'total_formateado' => '$' . number_format(round($total, 0), 0, ',', '.'),
        
        // Información adicional
        'tarifa_minima_aplicada' => $total == $tarifa_minima,
        'comision_plataforma' => round($comision_plataforma, 2),
        'ganancia_conductor' => round($ganancia_conductor, 2),
        
        // Timestamp
        'calculado_en' => date('Y-m-d H:i:s'),
    ];
    
    // Respuesta exitosa
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'data' => $cotizacion,
        'mensaje' => 'Cotización calculada exitosamente',
        'source' => 'pricing_php_fallback',
        'fallback' => true
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error de base de datos: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error en el servidor: ' . $e->getMessage()
    ]);
}
?>
