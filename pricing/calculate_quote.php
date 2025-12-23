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
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';

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
    
    $total = $subtotal_con_descuento + $recargo_precio;
    
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
        'mensaje' => 'Cotización calculada exitosamente'
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
