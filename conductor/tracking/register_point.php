<?php
/**
 * API: Registrar punto de tracking en tiempo real
 * Endpoint: conductor/tracking/register_point.php
 * Método: POST
 * 
 * Este endpoint recibe los datos de tracking GPS del conductor cada 5 segundos
 * durante un viaje activo. Almacena el punto y actualiza los acumulados.
 * 
 * Parámetros requeridos:
 * - solicitud_id: ID del viaje activo
 * - conductor_id: ID del conductor
 * - latitud: Latitud actual
 * - longitud: Longitud actual
 * - distancia_acumulada_km: Distancia total recorrida hasta ahora (calculada en app)
 * - tiempo_transcurrido_seg: Segundos desde inicio del viaje
 * 
 * Parámetros opcionales:
 * - velocidad: Velocidad actual en km/h
 * - bearing: Dirección/rumbo en grados
 * - precision_gps: Precisión del GPS en metros
 * - altitud: Altitud en metros
 * - fase_viaje: 'hacia_recogida' o 'hacia_destino'
 * - evento: Evento especial (inicio, parada, etc.)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

require_once '../../config/database.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validar campos requeridos
    $required = ['solicitud_id', 'conductor_id', 'latitud', 'longitud', 'distancia_acumulada_km', 'tiempo_transcurrido_seg'];
    foreach ($required as $field) {
        if (!isset($input[$field])) {
            throw new Exception("Campo requerido faltante: $field");
        }
    }
    
    $solicitud_id = intval($input['solicitud_id']);
    $conductor_id = intval($input['conductor_id']);
    $latitud = floatval($input['latitud']);
    $longitud = floatval($input['longitud']);
    $distancia_acumulada_km = floatval($input['distancia_acumulada_km']);
    $tiempo_transcurrido_seg = intval($input['tiempo_transcurrido_seg']);
    
    // Parámetros opcionales
    $velocidad = isset($input['velocidad']) ? floatval($input['velocidad']) : 0;
    $bearing = isset($input['bearing']) ? floatval($input['bearing']) : 0;
    $precision_gps = isset($input['precision_gps']) ? floatval($input['precision_gps']) : null;
    $altitud = isset($input['altitud']) ? floatval($input['altitud']) : null;
    $fase_viaje = $input['fase_viaje'] ?? 'hacia_destino';
    $evento = $input['evento'] ?? null;
    
    // Validaciones básicas
    if ($solicitud_id <= 0 || $conductor_id <= 0) {
        throw new Exception('IDs inválidos');
    }
    
    if ($latitud < -90 || $latitud > 90 || $longitud < -180 || $longitud > 180) {
        throw new Exception('Coordenadas inválidas');
    }
    
    $database = new Database();
    $db = $database->getConnection();
    
    // Verificar que el viaje existe y está en curso
    $stmt = $db->prepare("
        SELECT s.id, s.estado, ac.conductor_id 
        FROM solicitudes_servicio s
        LEFT JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id AND ac.estado IN ('asignado', 'llegado', 'en_curso', 'completado')
        WHERE s.id = :solicitud_id
    ");
    $stmt->execute([':solicitud_id' => $solicitud_id]);
    $viaje = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$viaje) {
        throw new Exception('Viaje no encontrado');
    }
    
    // Verificar que el conductor está asignado a este viaje
    if ($viaje['conductor_id'] && intval($viaje['conductor_id']) !== $conductor_id) {
        throw new Exception('No autorizado para este viaje');
    }
    
    // Verificar que el viaje está en estado válido para tracking
    // Estados permitidos: desde que acepta hasta que completa
    $estados_validos = ['aceptada', 'conductor_llego', 'recogido', 'en_curso', 'en_viaje', 'hacia_destino'];
    if (!in_array($viaje['estado'], $estados_validos)) {
        // No es un error crítico, simplemente no guardamos el punto
        echo json_encode([
            'success' => true,
            'message' => 'Viaje no está en curso, punto no registrado',
            'viaje_estado' => $viaje['estado']
        ]);
        exit();
    }
    
    // Obtener el último punto para calcular distancia incremental
    $distancia_desde_anterior = 0;
    $stmt = $db->prepare("
        SELECT latitud, longitud, distancia_acumulada_km
        FROM viaje_tracking_realtime
        WHERE solicitud_id = :solicitud_id
        ORDER BY timestamp_gps DESC
        LIMIT 1
    ");
    $stmt->execute([':solicitud_id' => $solicitud_id]);
    $ultimo_punto = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($ultimo_punto) {
        // Calcular distancia desde el último punto (en metros)
        $distancia_desde_anterior = calcularDistanciaHaversine(
            floatval($ultimo_punto['latitud']),
            floatval($ultimo_punto['longitud']),
            $latitud,
            $longitud
        );
    }
    
    // Calcular precio parcial basado en la distancia actual
    $precio_parcial = calcularPrecioParcial($db, $solicitud_id, $distancia_acumulada_km, $tiempo_transcurrido_seg);
    
    // Insertar el punto de tracking
    $stmt = $db->prepare("
        INSERT INTO viaje_tracking_realtime (
            solicitud_id,
            conductor_id,
            latitud,
            longitud,
            precision_gps,
            altitud,
            velocidad,
            bearing,
            distancia_acumulada_km,
            tiempo_transcurrido_seg,
            distancia_desde_anterior_m,
            precio_parcial,
            fase_viaje,
            evento,
            timestamp_gps,
            timestamp_servidor
        ) VALUES (
            :solicitud_id,
            :conductor_id,
            :latitud,
            :longitud,
            :precision_gps,
            :altitud,
            :velocidad,
            :bearing,
            :distancia_acumulada_km,
            :tiempo_transcurrido_seg,
            :distancia_desde_anterior,
            :precio_parcial,
            :fase_viaje,
            :evento,
            NOW(),
            NOW()
        )
    ");
    
    $stmt->execute([
        ':solicitud_id' => $solicitud_id,
        ':conductor_id' => $conductor_id,
        ':latitud' => $latitud,
        ':longitud' => $longitud,
        ':precision_gps' => $precision_gps,
        ':altitud' => $altitud,
        ':velocidad' => $velocidad,
        ':bearing' => $bearing,
        ':distancia_acumulada_km' => $distancia_acumulada_km,
        ':tiempo_transcurrido_seg' => $tiempo_transcurrido_seg,
        ':distancia_desde_anterior' => $distancia_desde_anterior,
        ':precio_parcial' => $precio_parcial,
        ':fase_viaje' => $fase_viaje,
        ':evento' => $evento
    ]);
    
    $punto_id = $db->lastInsertId();
    
    // Actualizar también la ubicación del conductor
    $stmt = $db->prepare("
        UPDATE detalles_conductor 
        SET latitud_actual = :latitud, 
            longitud_actual = :longitud,
            ultima_actualizacion = NOW()
        WHERE usuario_id = :conductor_id
    ");
    $stmt->execute([
        ':latitud' => $latitud,
        ':longitud' => $longitud,
        ':conductor_id' => $conductor_id
    ]);
    
    // Actualizar distancia/tiempo/precio en la solicitud
    // NOTA: precio_ajustado_por_tracking es un boolean, precio_en_tracking guarda el precio parcial
    $stmt = $db->prepare("
        UPDATE solicitudes_servicio 
        SET distancia_recorrida = :distancia,
            tiempo_transcurrido = :tiempo,
            precio_en_tracking = :precio
        WHERE id = :solicitud_id
    ");
    $stmt->execute([
        ':distancia' => $distancia_acumulada_km,
        ':tiempo' => $tiempo_transcurrido_seg,
        ':precio' => $precio_parcial,
        ':solicitud_id' => $solicitud_id
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Punto de tracking registrado',
        'data' => [
            'punto_id' => $punto_id,
            'distancia_acumulada_km' => $distancia_acumulada_km,
            'tiempo_transcurrido_seg' => $tiempo_transcurrido_seg,
            'precio_parcial' => $precio_parcial,
            'distancia_desde_anterior_m' => round($distancia_desde_anterior, 2)
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * Calcula la distancia en metros entre dos puntos usando la fórmula de Haversine
 */
function calcularDistanciaHaversine($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371000; // Radio de la Tierra en metros
    
    $lat1Rad = deg2rad($lat1);
    $lat2Rad = deg2rad($lat2);
    $deltaLat = deg2rad($lat2 - $lat1);
    $deltaLon = deg2rad($lon2 - $lon1);
    
    $a = sin($deltaLat / 2) * sin($deltaLat / 2) +
         cos($lat1Rad) * cos($lat2Rad) *
         sin($deltaLon / 2) * sin($deltaLon / 2);
    
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    
    return $earthRadius * $c;
}

/**
 * Calcula el precio parcial basado en la distancia y tiempo actual
 * Usa la tarifa de la empresa del viaje, o la tarifa global si no hay empresa
 */
function calcularPrecioParcial($db, $solicitud_id, $distancia_km, $tiempo_seg) {
    try {
        // Obtener tipo de vehículo y empresa del viaje
        $stmt = $db->prepare("
            SELECT tipo_vehiculo, empresa_id 
            FROM solicitudes_servicio 
            WHERE id = :id
        ");
        $stmt->execute([':id' => $solicitud_id]);
        $viaje = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$viaje) return 0;
        
        $tipo_vehiculo = $viaje['tipo_vehiculo'] ?? 'moto';
        $empresa_id = $viaje['empresa_id'];
        
        // Buscar configuración de precios: primero de la empresa, luego global
        $config = null;
        
        if ($empresa_id) {
            // Buscar tarifa de la empresa
            $stmt = $db->prepare("
                SELECT tarifa_base, costo_por_km, costo_por_minuto, tarifa_minima
                FROM configuracion_precios 
                WHERE empresa_id = :empresa_id AND tipo_vehiculo = :tipo AND activo = 1
                LIMIT 1
            ");
            $stmt->execute([':empresa_id' => $empresa_id, ':tipo' => $tipo_vehiculo]);
            $config = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        // Si no hay tarifa de empresa, usar tarifa global
        if (!$config) {
            $stmt = $db->prepare("
                SELECT tarifa_base, costo_por_km, costo_por_minuto, tarifa_minima
                FROM configuracion_precios 
                WHERE empresa_id IS NULL AND tipo_vehiculo = :tipo AND activo = 1
                LIMIT 1
            ");
            $stmt->execute([':tipo' => $tipo_vehiculo]);
            $config = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        if (!$config) return 0;
        
        $tiempo_min = $tiempo_seg / 60.0;
        
        $precio = floatval($config['tarifa_base']) +
                  ($distancia_km * floatval($config['costo_por_km'])) +
                  ($tiempo_min * floatval($config['costo_por_minuto']));
        
        // Aplicar tarifa mínima
        if ($precio < floatval($config['tarifa_minima'])) {
            $precio = floatval($config['tarifa_minima']);
        }
        
        return round($precio, 2);
        
    } catch (Exception $e) {
        return 0;
    }
}
