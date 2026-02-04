<?php
/**
 * Endpoint: Buscar Conductores Cercanos Disponibles
 * 
 * Filtra conductores por:
 * - Tipo de vehículo solicitado
 * - Empresa seleccionada (si se proporciona)
 * - Distancia al punto de recogida
 * - Estado activo y disponible
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
    
    // Validar datos requeridos
    if (!isset($data['latitud']) || !isset($data['longitud']) || !isset($data['tipo_vehiculo'])) {
        throw new Exception('Datos requeridos: latitud, longitud, tipo_vehiculo');
    }
    
    $latitud = $data['latitud'];
    $longitud = $data['longitud'];
    $tipoVehiculo = $data['tipo_vehiculo'];
    $empresaId = isset($data['empresa_id']) ? intval($data['empresa_id']) : null;
    $radioKm = $data['radio_km'] ?? 5.0;
    
    $database = new Database();
    $db = $database->getConnection();
    
    // Mapear tipo de vehículo de la app a la BD
    $vehiculoTipoMap = [
        'moto' => 'moto',
        'auto' => 'auto',
        'motocarro' => 'motocarro'
    ];
    $vehiculoTipoBD = $vehiculoTipoMap[$tipoVehiculo] ?? 'moto';
    
    // Construir query base para buscar conductores cercanos
    $query = "
        SELECT 
            u.id,
            u.nombre,
            u.apellido,
            u.telefono,
            u.foto_perfil,
            u.empresa_id,
            dc.vehiculo_tipo,
            dc.vehiculo_marca,
            dc.vehiculo_modelo,
            dc.vehiculo_placa,
            dc.vehiculo_color,
            dc.calificacion_promedio,
            dc.total_viajes,
            dc.latitud_actual,
            dc.longitud_actual,
            (6371 * acos(
                cos(radians(?)) * cos(radians(dc.latitud_actual)) *
                cos(radians(dc.longitud_actual) - radians(?)) +
                sin(radians(?)) * sin(radians(dc.latitud_actual))
            )) AS distancia_km
        FROM usuarios u
        INNER JOIN detalles_conductor dc ON u.id = dc.usuario_id
        WHERE u.tipo_usuario = 'conductor'
        AND u.es_activo = 1
        AND dc.disponible = 1
        AND dc.estado_verificacion = 'aprobado'
        AND dc.vehiculo_tipo = ?
        AND dc.latitud_actual IS NOT NULL
        AND dc.longitud_actual IS NOT NULL
        AND (6371 * acos(
            cos(radians(?)) * cos(radians(dc.latitud_actual)) *
            cos(radians(dc.longitud_actual) - radians(?)) +
            sin(radians(?)) * sin(radians(dc.latitud_actual))
        )) <= ?";
    
    // Agregar filtro de empresa si se proporciona
    $params = [
        $latitud,
        $longitud,
        $latitud,
        $vehiculoTipoBD,
        $latitud,
        $longitud,
        $latitud,
        $radioKm
    ];
    
    if ($empresaId !== null) {
        $query .= " AND u.empresa_id = ?";
        $params[] = $empresaId;
    }
    
    $query .= " ORDER BY distancia_km ASC LIMIT 20";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    
    $conductores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formatear respuesta
    $conductoresFormateados = array_map(function($c) {
        return [
            'id' => (int)$c['id'],
            'nombre' => $c['nombre'],
            'apellido' => $c['apellido'],
            'telefono' => $c['telefono'],
            'foto_perfil' => $c['foto_perfil'],
            'empresa_id' => isset($c['empresa_id']) ? (int)$c['empresa_id'] : null,
            'vehiculo_tipo' => $c['vehiculo_tipo'],
            'vehiculo_marca' => $c['vehiculo_marca'],
            'vehiculo_modelo' => $c['vehiculo_modelo'],
            'vehiculo_placa' => $c['vehiculo_placa'],
            'vehiculo_color' => $c['vehiculo_color'],
            'calificacion_promedio' => $c['calificacion_promedio'] ? (float)$c['calificacion_promedio'] : null,
            'total_viajes' => (int)($c['total_viajes'] ?? 0),
            'latitud_actual' => (float)$c['latitud_actual'],
            'longitud_actual' => (float)$c['longitud_actual'],
            'distancia_km' => round((float)$c['distancia_km'], 2),
        ];
    }, $conductores);
    
    echo json_encode([
        'success' => true,
        'total' => count($conductoresFormateados),
        'conductores' => $conductoresFormateados
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
