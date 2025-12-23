<?php
/**
 * API: Obtener configuración de precios
 * Endpoint: pricing/get_config.php
 * 
 * Devuelve la configuración de precios activa para un tipo de vehículo
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    // Obtener tipo de vehículo (por defecto 'moto')
    $tipo_vehiculo = isset($_GET['tipo_vehiculo']) ? $_GET['tipo_vehiculo'] : 'moto';
    
    // Validar tipo de vehículo
    $tipos_validos = ['moto', 'auto', 'motocarro'];
    if (!in_array($tipo_vehiculo, $tipos_validos)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Tipo de vehículo inválido'
        ]);
        exit;
    }
    
    // Consultar configuración activa
    $query = "SELECT * FROM configuracion_precios 
              WHERE tipo_vehiculo = :tipo_vehiculo AND activo = 1 
              LIMIT 1";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':tipo_vehiculo', $tipo_vehiculo);
    $stmt->execute();
    
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($config) {
        // Determinar período actual
        $hora_actual = date('H:i:s');
        $periodo_actual = 'normal';
        $recargo_actual = 0.00;
        
        // Verificar hora pico mañana
        if ($hora_actual >= $config['hora_pico_inicio_manana'] && 
            $hora_actual <= $config['hora_pico_fin_manana']) {
            $periodo_actual = 'hora_pico';
            $recargo_actual = floatval($config['recargo_hora_pico']);
        }
        // Verificar hora pico tarde
        elseif ($hora_actual >= $config['hora_pico_inicio_tarde'] && 
                $hora_actual <= $config['hora_pico_fin_tarde']) {
            $periodo_actual = 'hora_pico';
            $recargo_actual = floatval($config['recargo_hora_pico']);
        }
        // Verificar horario nocturno
        elseif ($hora_actual >= $config['hora_nocturna_inicio'] || 
                $hora_actual <= $config['hora_nocturna_fin']) {
            $periodo_actual = 'nocturno';
            $recargo_actual = floatval($config['recargo_nocturno']);
        }
        
        // Verificar si es festivo (simplificado)
        $dia_semana = date('w');
        $es_festivo = false; // TODO: Implementar lógica real de festivos
        
        if ($es_festivo) {
            $periodo_actual = 'festivo';
            $recargo_actual = floatval($config['recargo_festivo']);
        }
        
        // Agregar información del período actual
        $config['periodo_actual'] = $periodo_actual;
        $config['recargo_actual'] = $recargo_actual;
        
        // Convertir valores a números
        $config['tarifa_base'] = floatval($config['tarifa_base']);
        $config['costo_por_km'] = floatval($config['costo_por_km']);
        $config['costo_por_minuto'] = floatval($config['costo_por_minuto']);
        $config['tarifa_minima'] = floatval($config['tarifa_minima']);
        $config['comision_plataforma'] = floatval($config['comision_plataforma']);
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data' => $config
        ]);
    } else {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'No se encontró configuración activa para este vehículo'
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error en el servidor: ' . $e->getMessage()
    ]);
}
?>
