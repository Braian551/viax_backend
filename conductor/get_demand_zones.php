<?php
/**
 * API: Obtener Zonas de Alta Demanda (Surge Pricing)
 * Endpoint: conductor/get_demand_zones.php
 * 
 * Sistema similar a Uber/Didi que calcula zonas calientes basándose en:
 * - Número de solicitudes activas por zona
 * - Número de conductores disponibles por zona
 * - Ratio demanda/oferta para calcular multiplicadores
 * 
 * Divide el área en cuadrantes y calcula la demanda para cada uno
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

try {
    // Obtener parámetros
    $data = $_SERVER['REQUEST_METHOD'] === 'POST' 
        ? json_decode(file_get_contents('php://input'), true) 
        : $_GET;
    
    // Ubicación del conductor (centro de búsqueda)
    $centerLat = isset($data['latitud']) ? floatval($data['latitud']) : null;
    $centerLng = isset($data['longitud']) ? floatval($data['longitud']) : null;
    
    // Radio de búsqueda en km (por defecto 10km)
    $searchRadiusKm = isset($data['radio_km']) ? floatval($data['radio_km']) : 10.0;
    
    // Tamaño de cada zona/cuadrante en km (por defecto 0.5km)
    $zoneSize = isset($data['zone_size_km']) ? floatval($data['zone_size_km']) : 0.5;
    
    $database = new Database();
    $db = $database->getConnection();
    
    // Si no hay ubicación, usar una ubicación por defecto (Bogotá centro)
    if ($centerLat === null || $centerLng === null) {
        // Intentar obtener ubicación promedio de solicitudes activas
        $avgQuery = "SELECT 
                        AVG(latitud_recogida) as avg_lat,
                        AVG(longitud_recogida) as avg_lng
                     FROM solicitudes_servicio
                     WHERE estado IN ('pendiente', 'aceptado')
                     AND COALESCE(solicitado_en, fecha_creacion) >= NOW() - INTERVAL '30 minutes'";
        $avgStmt = $db->query($avgQuery);
        $avgResult = $avgStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($avgResult && $avgResult['avg_lat']) {
            $centerLat = floatval($avgResult['avg_lat']);
            $centerLng = floatval($avgResult['avg_lng']);
        } else {
            // Bogotá como default
            $centerLat = 4.6097;
            $centerLng = -74.0817;
        }
    }
    
    // Calcular límites del área de búsqueda
    // 1 grado de latitud ≈ 111km
    // 1 grado de longitud ≈ 111km * cos(latitud)
    $latDelta = $searchRadiusKm / 111.0;
    $lngDelta = $searchRadiusKm / (111.0 * cos(deg2rad($centerLat)));
    
    $minLat = $centerLat - $latDelta;
    $maxLat = $centerLat + $latDelta;
    $minLng = $centerLng - $lngDelta;
    $maxLng = $centerLng + $lngDelta;
    
    // Calcular tamaño del grid
    $latStep = $zoneSize / 111.0;
    $lngStep = $zoneSize / (111.0 * cos(deg2rad($centerLat)));
    
    // Obtener solicitudes activas en el área
    $requestsQuery = "
        SELECT 
            latitud_recogida,
            longitud_recogida,
            estado,
            tipo_servicio
        FROM solicitudes_servicio
        WHERE estado IN ('pendiente')
        AND latitud_recogida BETWEEN ? AND ?
        AND longitud_recogida BETWEEN ? AND ?
        AND COALESCE(solicitado_en, fecha_creacion) >= NOW() - INTERVAL '30 minutes'
    ";
    
    $requestsStmt = $db->prepare($requestsQuery);
    $requestsStmt->execute([$minLat, $maxLat, $minLng, $maxLng]);
    $activeRequests = $requestsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener conductores disponibles en el área
    $driversQuery = "
        SELECT 
            dc.latitud_actual,
            dc.longitud_actual,
            dc.vehiculo_tipo
        FROM detalles_conductor dc
        INNER JOIN usuarios u ON dc.usuario_id = u.id
        WHERE dc.disponible = 1
        AND dc.estado_verificacion = 'aprobado'
        AND dc.latitud_actual BETWEEN ? AND ?
        AND dc.longitud_actual BETWEEN ? AND ?
        AND dc.ultima_actualizacion >= NOW() - INTERVAL '10 minutes'
    ";
    
    $driversStmt = $db->prepare($driversQuery);
    $driversStmt->execute([$minLat, $maxLat, $minLng, $maxLng]);
    $availableDrivers = $driversStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Crear grid de zonas y contar solicitudes/conductores en cada una
    $zones = [];
    $zoneId = 0;
    
    for ($lat = $minLat; $lat < $maxLat; $lat += $latStep) {
        for ($lng = $minLng; $lng < $maxLng; $lng += $lngStep) {
            $zoneCenterLat = $lat + ($latStep / 2);
            $zoneCenterLng = $lng + ($lngStep / 2);
            
            // Contar solicitudes en esta zona
            $requestCount = 0;
            foreach ($activeRequests as $request) {
                $reqLat = floatval($request['latitud_recogida']);
                $reqLng = floatval($request['longitud_recogida']);
                
                if ($reqLat >= $lat && $reqLat < ($lat + $latStep) &&
                    $reqLng >= $lng && $reqLng < ($lng + $lngStep)) {
                    $requestCount++;
                }
            }
            
            // Contar conductores en esta zona
            $driverCount = 0;
            foreach ($availableDrivers as $driver) {
                $drvLat = floatval($driver['latitud_actual']);
                $drvLng = floatval($driver['longitud_actual']);
                
                if ($drvLat >= $lat && $drvLat < ($lat + $latStep) &&
                    $drvLng >= $lng && $drvLng < ($lng + $lngStep)) {
                    $driverCount++;
                }
            }
            
            // Solo incluir zonas con al menos 1 solicitud
            if ($requestCount > 0) {
                // Calcular nivel de demanda y multiplicador
                $demandData = calculateDemandLevel($requestCount, $driverCount);
                
                $zones[] = [
                    'id' => 'zone_' . ($zoneId++),
                    'center_lat' => round($zoneCenterLat, 6),
                    'center_lng' => round($zoneCenterLng, 6),
                    'radius_km' => $zoneSize / 2,
                    'demand_level' => $demandData['level'],
                    'surge_multiplier' => $demandData['multiplier'],
                    'active_requests' => $requestCount,
                    'available_drivers' => $driverCount,
                    'last_updated' => date('Y-m-d H:i:s'),
                ];
            }
        }
    }
    
    // Ordenar zonas por nivel de demanda (mayor primero)
    usort($zones, function($a, $b) {
        return $b['demand_level'] - $a['demand_level'];
    });
    
    // Limitar a las 20 zonas más calientes
    $zones = array_slice($zones, 0, 20);
    
    // Agregar zonas simuladas si no hay datos reales (para demo)
    if (empty($zones) && count($activeRequests) === 0) {
        $zones = generateDemoZones($centerLat, $centerLng, $zoneSize);
    }
    
    echo json_encode([
        'success' => true,
        'zones' => $zones,
        'total_zones' => count($zones),
        'total_requests' => count($activeRequests),
        'total_drivers' => count($availableDrivers),
        'search_center' => [
            'lat' => $centerLat,
            'lng' => $centerLng,
        ],
        'search_radius_km' => $searchRadiusKm,
        'zone_size_km' => $zoneSize,
        'server_time' => date('Y-m-d H:i:s'),
        'refresh_interval' => 30, // Segundos recomendados para refrescar
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error al obtener zonas de demanda: ' . $e->getMessage(),
        'zones' => [],
    ]);
}

/**
 * Calcular nivel de demanda y multiplicador de precio
 * basado en ratio solicitudes/conductores
 */
function calculateDemandLevel($requests, $drivers) {
    if ($drivers == 0) {
        // Sin conductores = máxima demanda
        if ($requests >= 5) return ['level' => 5, 'multiplier' => 2.5];
        if ($requests >= 3) return ['level' => 4, 'multiplier' => 2.0];
        if ($requests >= 2) return ['level' => 3, 'multiplier' => 1.5];
        return ['level' => 2, 'multiplier' => 1.3];
    }
    
    $ratio = $requests / $drivers;
    
    if ($ratio >= 4.0) {
        return ['level' => 5, 'multiplier' => 2.5]; // Muy alta demanda
    } elseif ($ratio >= 3.0) {
        return ['level' => 4, 'multiplier' => 2.0]; // Alta demanda
    } elseif ($ratio >= 2.0) {
        return ['level' => 3, 'multiplier' => 1.5]; // Media demanda
    } elseif ($ratio >= 1.0) {
        return ['level' => 2, 'multiplier' => 1.2]; // Demanda normal-alta
    } else {
        return ['level' => 1, 'multiplier' => 1.0]; // Baja demanda
    }
}

/**
 * Generar zonas de demostración cuando no hay datos reales
 * Útil para testing y demos
 */
function generateDemoZones($centerLat, $centerLng, $zoneSize) {
    $demoZones = [];
    $latStep = $zoneSize / 111.0;
    $lngStep = $zoneSize / (111.0 * cos(deg2rad($centerLat)));
    
    // Crear algunas zonas de demo alrededor del centro
    $offsets = [
        ['lat' => 0, 'lng' => 0, 'level' => 4, 'mult' => 1.8, 'req' => 5],
        ['lat' => 2, 'lng' => 1, 'level' => 3, 'mult' => 1.5, 'req' => 3],
        ['lat' => -1, 'lng' => 2, 'level' => 5, 'mult' => 2.2, 'req' => 8],
        ['lat' => 1, 'lng' => -2, 'level' => 2, 'mult' => 1.3, 'req' => 2],
        ['lat' => -2, 'lng' => -1, 'level' => 3, 'mult' => 1.6, 'req' => 4],
        ['lat' => 3, 'lng' => 0, 'level' => 4, 'mult' => 1.9, 'req' => 6],
        ['lat' => 0, 'lng' => 3, 'level' => 2, 'mult' => 1.2, 'req' => 2],
        ['lat' => -3, 'lng' => 1, 'level' => 5, 'mult' => 2.5, 'req' => 10],
    ];
    
    foreach ($offsets as $i => $offset) {
        $demoZones[] = [
            'id' => 'demo_zone_' . $i,
            'center_lat' => round($centerLat + ($offset['lat'] * $latStep), 6),
            'center_lng' => round($centerLng + ($offset['lng'] * $lngStep), 6),
            'radius_km' => $zoneSize / 2,
            'demand_level' => $offset['level'],
            'surge_multiplier' => $offset['mult'],
            'active_requests' => $offset['req'],
            'available_drivers' => max(0, $offset['req'] - $offset['level']),
            'last_updated' => date('Y-m-d H:i:s'),
            'is_demo' => true,
        ];
    }
    
    return $demoZones;
}
