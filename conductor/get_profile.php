<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
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

    if ($conductor_id <= 0) {
        throw new Exception('ID de conductor inválido');
    }

    // Obtener información completa del conductor
    $query = "SELECT 
                u.id,
                u.nombre,
                u.apellido,
                u.email,
                u.telefono,
                u.foto_perfil,
                dc.licencia_conduccion,
                dc.licencia_expedicion,
                dc.licencia_vencimiento,
                dc.licencia_categoria,
                dc.licencia_foto_url,
                dc.vehiculo_tipo,
                dc.vehiculo_marca,
                dc.vehiculo_modelo,
                dc.vehiculo_anio,
                dc.vehiculo_color,
                dc.vehiculo_placa,
                dc.aseguradora,
                dc.numero_poliza_seguro,
                dc.vencimiento_seguro,
                dc.seguro_foto_url,
                dc.soat_numero,
                dc.soat_vencimiento,
                dc.soat_foto_url,
                dc.tecnomecanica_numero,
                dc.tecnomecanica_vencimiento,
                dc.tecnomecanica_foto_url,
                dc.tarjeta_propiedad_numero,
                dc.tarjeta_propiedad_foto_url,
                dc.calificacion_promedio,
                dc.total_viajes,
                dc.disponible,
                dc.estado_verificacion,
                dc.fecha_ultima_verificacion,
                dc.aprobado,
                dc.estado_aprobacion
              FROM usuarios u
              LEFT JOIN detalles_conductor dc ON u.id = dc.usuario_id
              WHERE u.id = :conductor_id AND u.tipo_usuario = 'conductor'";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':conductor_id', $conductor_id, PDO::PARAM_INT);
    $stmt->execute();

    $conductor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$conductor) {
        throw new Exception('Conductor no encontrado');
    }

    // Build profile response
    $profile = [
        'conductor_id' => (int)$conductor['id'],
        'nombre_completo' => trim($conductor['nombre'] . ' ' . $conductor['apellido']),
        'email' => $conductor['email'],
        'telefono' => $conductor['telefono'],
        'foto_perfil' => $conductor['foto_perfil'],
        'calificacion_promedio' => (float)($conductor['calificacion_promedio'] ?? 0.0),
        'total_viajes' => (int)($conductor['total_viajes'] ?? 0),
        'disponible' => (bool)($conductor['disponible'] ?? false),
        'estado_verificacion' => $conductor['estado_verificacion'] ?? 'pendiente',
        'fecha_ultima_verificacion' => $conductor['fecha_ultima_verificacion'],
        'aprobado' => (int)($conductor['aprobado'] ?? 0),
        'estado_aprobacion' => $conductor['estado_aprobacion'] ?? 'pendiente',
        
        // License information
        'licencia' => null,
        
        // Vehicle information
        'vehiculo' => null,
        
        // Profile completion status
        'is_profile_complete' => false,
        'completion_percentage' => 0.0,
        'pending_tasks' => [],
        'documentos_pendientes' => [],
        'documentos_rechazados' => []
    ];

    // Build license info if exists
    if (!empty($conductor['licencia_conduccion'])) {
        $profile['licencia'] = [
            'licencia_conduccion' => $conductor['licencia_conduccion'],
            'licencia_expedicion' => $conductor['licencia_expedicion'],
            'licencia_vencimiento' => $conductor['licencia_vencimiento'],
            'licencia_categoria' => $conductor['licencia_categoria'] ?? 'C1',
            'estado' => 'activa',
            'licencia_foto_url' => $conductor['licencia_foto_url'],
            'licencia_foto_reverso' => null // Not yet implemented
        ];
    }

    // Build vehicle info if exists
    if (!empty($conductor['vehiculo_placa'])) {
        $profile['vehiculo'] = [
            'vehiculo_placa' => $conductor['vehiculo_placa'],
            'vehiculo_tipo' => $conductor['vehiculo_tipo'],
            'vehiculo_marca' => $conductor['vehiculo_marca'],
            'vehiculo_modelo' => $conductor['vehiculo_modelo'],
            'vehiculo_anio' => (int)$conductor['vehiculo_anio'],
            'vehiculo_color' => $conductor['vehiculo_color'],
            'aseguradora' => $conductor['aseguradora'],
            'numero_poliza_seguro' => $conductor['numero_poliza_seguro'],
            'vencimiento_seguro' => $conductor['vencimiento_seguro'],
            'seguro_foto_url' => $conductor['seguro_foto_url'],
            'soat_numero' => $conductor['soat_numero'],
            'soat_vencimiento' => $conductor['soat_vencimiento'],
            'soat_foto_url' => $conductor['soat_foto_url'],
            'tecnomecanica_numero' => $conductor['tecnomecanica_numero'],
            'tecnomecanica_vencimiento' => $conductor['tecnomecanica_vencimiento'],
            'tecnomecanica_foto_url' => $conductor['tecnomecanica_foto_url'],
            'tarjeta_propiedad_numero' => $conductor['tarjeta_propiedad_numero'],
            'tarjeta_propiedad_foto_url' => $conductor['tarjeta_propiedad_foto_url'],
            'foto_vehiculo' => null // Not yet implemented
        ];
    }

    // Calculate completion percentage
    $total_items = 2; // license and vehicle (with documents)
    $completed_items = 0;
    
    // Check license completion
    $license_complete = !empty($conductor['licencia_conduccion']) && 
                       !empty($conductor['licencia_vencimiento']) &&
                       !empty($conductor['licencia_categoria']);
    
    if ($license_complete) {
        $completed_items++;
    } else {
        $profile['pending_tasks'][] = 'Completar información de licencia';
        $profile['documentos_pendientes'][] = 'licencia';
    }
    
    // Check vehicle completion
    $vehicle_complete = !empty($conductor['vehiculo_placa']) && 
                       !empty($conductor['vehiculo_marca']) &&
                       !empty($conductor['vehiculo_modelo']) &&
                       !empty($conductor['vehiculo_anio']) &&
                       !empty($conductor['soat_numero']) &&
                       !empty($conductor['soat_vencimiento']) &&
                       !empty($conductor['tecnomecanica_numero']) &&
                       !empty($conductor['tecnomecanica_vencimiento']) &&
                       !empty($conductor['tarjeta_propiedad_numero']);
    
    if ($vehicle_complete) {
        $completed_items++;
    } else if (!empty($conductor['vehiculo_placa'])) {
        $profile['pending_tasks'][] = 'Completar documentos del vehículo';
        $profile['documentos_pendientes'][] = 'documentos_vehiculo';
    } else {
        $profile['pending_tasks'][] = 'Registrar vehículo';
        $profile['documentos_pendientes'][] = 'vehiculo';
    }
    
    $profile['completion_percentage'] = $total_items > 0 ? round($completed_items / $total_items, 2) : 0;
    $profile['is_profile_complete'] = $license_complete && $vehicle_complete;

    echo json_encode([
        'success' => true,
        'profile' => $profile,
        'message' => 'Perfil del conductor obtenido exitosamente'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
