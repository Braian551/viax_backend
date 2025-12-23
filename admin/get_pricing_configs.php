<?php
/**
 * API: Obtener todas las configuraciones de precios
 * Endpoint: admin/get_pricing_configs.php
 * 
 * Devuelve todas las configuraciones de precios del sistema
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    // Consultar todas las configuraciones de precios ordenadas por tipo de vehículo
    $query = "SELECT 
                id,
                tipo_vehiculo,
                tarifa_base,
                costo_por_km,
                costo_por_minuto,
                tarifa_minima,
                tarifa_maxima,
                recargo_hora_pico,
                recargo_nocturno,
                recargo_festivo,
                descuento_distancia_larga,
                umbral_km_descuento,
                hora_pico_inicio_manana,
                hora_pico_fin_manana,
                hora_pico_inicio_tarde,
                hora_pico_fin_tarde,
                hora_nocturna_inicio,
                hora_nocturna_fin,
                comision_plataforma,
                comision_metodo_pago,
                distancia_minima,
                distancia_maxima,
                tiempo_espera_gratis,
                costo_tiempo_espera,
                activo,
                fecha_creacion,
                fecha_actualizacion,
                notas
              FROM configuracion_precios 
              ORDER BY 
                CASE tipo_vehiculo
                    WHEN 'moto' THEN 1
                    WHEN 'carro' THEN 2
                    WHEN 'moto_carga' THEN 3
                    WHEN 'carro_carga' THEN 4
                    ELSE 5
                END,
                activo DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->execute();
    
    $configs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Convertir valores numéricos
    foreach ($configs as &$config) {
        $config['tarifa_base'] = floatval($config['tarifa_base']);
        $config['costo_por_km'] = floatval($config['costo_por_km']);
        $config['costo_por_minuto'] = floatval($config['costo_por_minuto']);
        $config['tarifa_minima'] = floatval($config['tarifa_minima']);
        $config['tarifa_maxima'] = $config['tarifa_maxima'] ? floatval($config['tarifa_maxima']) : null;
        $config['recargo_hora_pico'] = floatval($config['recargo_hora_pico']);
        $config['recargo_nocturno'] = floatval($config['recargo_nocturno']);
        $config['recargo_festivo'] = floatval($config['recargo_festivo']);
        $config['descuento_distancia_larga'] = floatval($config['descuento_distancia_larga']);
        $config['umbral_km_descuento'] = floatval($config['umbral_km_descuento']);
        $config['comision_plataforma'] = floatval($config['comision_plataforma']);
        $config['comision_metodo_pago'] = floatval($config['comision_metodo_pago']);
        $config['distancia_minima'] = floatval($config['distancia_minima']);
        $config['distancia_maxima'] = floatval($config['distancia_maxima']);
        $config['tiempo_espera_gratis'] = intval($config['tiempo_espera_gratis']);
        $config['costo_tiempo_espera'] = floatval($config['costo_tiempo_espera']);
        $config['activo'] = intval($config['activo']);
    }
    
    // Obtener estadísticas adicionales
    $statsQuery = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN activo = 1 THEN 1 ELSE 0 END) as activos,
                    MAX(fecha_actualizacion) as ultima_actualizacion
                   FROM configuracion_precios";
    
    $statsStmt = $conn->prepare($statsQuery);
    $statsStmt->execute();
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'data' => $configs,
        'stats' => [
            'total' => intval($stats['total']),
            'activos' => intval($stats['activos']),
            'ultima_actualizacion' => $stats['ultima_actualizacion']
        ],
        'message' => 'Configuraciones de precios obtenidas exitosamente'
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error en el servidor: ' . $e->getMessage()
    ]);
}
?>
