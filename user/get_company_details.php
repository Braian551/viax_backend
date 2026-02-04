<?php
/**
 * API: Obtener Información Detallada de una Empresa para Clientes
 * Endpoint: user/get_company_details.php
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['empresa_id'])) {
        throw new Exception('empresa_id es requerido');
    }
    
    $empresaId = intval($data['empresa_id']);
    
    $db = new Database();
    $conn = $db->getConnection();
    
    // Query with correct column names
    $empresaQuery = "
        SELECT 
            e.id,
            e.nombre,
            e.logo_url,
            e.verificada,
            e.descripcion,
            e.creado_en,
            ec.telefono,
            ec.email,
            ec.municipio,
            ec.departamento,
            (
                SELECT COUNT(*) 
                FROM usuarios u 
                WHERE u.tipo_usuario = 'conductor' 
                AND u.empresa_id = e.id 
                AND u.es_activo = 1
            ) as total_conductores,
            (
                SELECT COUNT(*) 
                FROM usuarios u 
                WHERE u.tipo_usuario = 'conductor' 
                AND u.empresa_id = e.id 
                AND u.es_activo = 1
            ) as conductores_activos,
            (
                SELECT COUNT(ss.id) 
                FROM solicitudes_servicio ss
                JOIN asignaciones_conductor ac ON ss.id = ac.solicitud_id
                JOIN usuarios u ON ac.conductor_id = u.id
                WHERE u.empresa_id = e.id
                AND ss.estado = 'completada'
            ) as total_viajes_completados,
            (
                SELECT COALESCE(AVG(c.calificacion), 0)
                FROM calificaciones c
                JOIN usuarios u ON c.usuario_calificado_id = u.id
                WHERE u.empresa_id = e.id
            ) as calificacion_promedio,
            (
                SELECT COUNT(c.id)
                FROM calificaciones c
                JOIN usuarios u ON c.usuario_calificado_id = u.id
                WHERE u.empresa_id = e.id
            ) as total_calificaciones
        FROM empresas_transporte e
        LEFT JOIN empresas_contacto ec ON e.id = ec.empresa_id
        WHERE e.id = :empresa_id
    ";
    $stmt = $conn->prepare($empresaQuery);
    $stmt->execute(['empresa_id' => $empresaId]);
    $empresa = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$empresa) {
        throw new Exception('Empresa no encontrada');
    }
    
    // Get vehicle types
    $vehiculosQuery = "
        SELECT 
            etv.tipo_vehiculo_codigo as codigo,
            ctv.nombre
        FROM empresa_tipos_vehiculo etv
        INNER JOIN catalogo_tipos_vehiculo ctv ON etv.tipo_vehiculo_codigo = ctv.codigo
        WHERE etv.empresa_id = :empresa_id
        AND etv.activo = true
        ORDER BY ctv.orden
    ";
    $stmt = $conn->prepare($vehiculosQuery);
    $stmt->execute(['empresa_id' => $empresaId]);
    $tiposVehiculo = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate year from creado_en
    $anioRegistro = null;
    if ($empresa['creado_en']) {
        $anioRegistro = (int) date('Y', strtotime($empresa['creado_en']));
    }
    
    // Convert logo URL to proxy URL
    $logoUrl = $empresa['logo_url'];
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    
    if (!empty($logoUrl)) {
        if (strpos($logoUrl, 'r2_proxy.php') !== false) {
            // Ya es una URL del proxy, retornar como está
            // No hacer nada
        } elseif (strpos($logoUrl, 'r2.dev/') !== false) {
            // Extract key from R2 direct URL
            $parts = explode('r2.dev/', $logoUrl);
            $key = end($parts);
            $logoUrl = "$protocol://$host/viax/backend/r2_proxy.php?key=" . urlencode($key);
        } elseif (strpos($logoUrl, 'http') !== 0) {
            // Relative path - convert to proxy URL
            $logoUrl = "$protocol://$host/viax/backend/r2_proxy.php?key=" . urlencode($logoUrl);
        }
    }
    
    // Build response
    $response = [
        'success' => true,
        'empresa' => [
            'id' => intval($empresa['id']),
            'nombre' => $empresa['nombre'],
            'logo_url' => $logoUrl,
            'verificada' => (bool)$empresa['verificada'],
            'descripcion' => $empresa['descripcion'],
            'telefono' => $empresa['telefono'],
            'email' => $empresa['email'],
            'website' => null,
            'municipio' => $empresa['municipio'],
            'departamento' => $empresa['departamento'],
            'anio_fundacion' => null,
            'anio_registro' => $anioRegistro,
            'total_conductores' => intval($empresa['total_conductores'] ?? 0),
            'viajes_completados' => intval($empresa['total_viajes_completados'] ?? 0),
            'calificacion_promedio' => $empresa['calificacion_promedio'] ? round(floatval($empresa['calificacion_promedio']), 1) : null,
            'total_calificaciones' => intval($empresa['total_calificaciones'] ?? 0),
            'tipos_vehiculo' => $tiposVehiculo
        ]
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
