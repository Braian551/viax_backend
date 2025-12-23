<?php
/**
 * API: Actualizar configuración de precios
 * Endpoint: admin/update_pricing_config.php
 * 
 * Actualiza los valores de una configuración de precios específica
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Manejar preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    // Obtener datos JSON
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    
    if (!$data) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Datos inválidos o vacíos'
        ]);
        exit;
    }
    
    // Validar ID
    if (!isset($data['id']) || empty($data['id'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'ID de configuración no proporcionado'
        ]);
        exit;
    }
    
    $id = intval($data['id']);
    
    // Verificar que la configuración existe
    $checkQuery = "SELECT id, tipo_vehiculo FROM configuracion_precios WHERE id = :id";
    $checkStmt = $conn->prepare($checkQuery);
    $checkStmt->bindParam(':id', $id);
    $checkStmt->execute();
    
    if ($checkStmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Configuración no encontrada'
        ]);
        exit;
    }
    
    $currentConfig = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    // Preparar campos para actualizar
    $updateFields = [];
    $params = ['id' => $id];
    
    // Validar y preparar campos monetarios
    $monetaryFields = [
        'tarifa_base',
        'costo_por_km',
        'costo_por_minuto',
        'tarifa_minima',
        'tarifa_maxima',
        'costo_por_minuto_espera'
    ];
    
    foreach ($monetaryFields as $field) {
        if (isset($data[$field])) {
            $value = floatval($data[$field]);
            if ($value < 0) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => "El valor de $field no puede ser negativo"
                ]);
                exit;
            }
            $updateFields[] = "$field = :$field";
            $params[$field] = $value;
        }
    }
    
    // Validar y preparar campos de porcentaje
    $percentageFields = [
        'recargo_hora_pico',
        'recargo_nocturno',
        'recargo_festivo',
        'descuento_distancia_larga',
        'comision_plataforma',
        'comision_metodo_pago'
    ];
    
    foreach ($percentageFields as $field) {
        if (isset($data[$field])) {
            $value = floatval($data[$field]);
            if ($value < 0 || $value > 100) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => "El valor de $field debe estar entre 0 y 100"
                ]);
                exit;
            }
            $updateFields[] = "$field = :$field";
            $params[$field] = $value;
        }
    }
    
    // Campos enteros
    if (isset($data['tiempo_espera_gratis'])) {
        $value = intval($data['tiempo_espera_gratis']);
        if ($value < 0) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Los minutos de espera gratis no pueden ser negativos'
            ]);
            exit;
        }
        $updateFields[] = "tiempo_espera_gratis = :tiempo_espera_gratis";
        $params['tiempo_espera_gratis'] = $value;
    }
    
    // Campos decimales adicionales
    $additionalFields = ['umbral_km_descuento', 'distancia_minima', 'distancia_maxima', 'costo_tiempo_espera'];
    foreach ($additionalFields as $field) {
        if (isset($data[$field])) {
            $value = floatval($data[$field]);
            if ($value < 0) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => "El valor de $field no puede ser negativo"
                ]);
                exit;
            }
            $updateFields[] = "$field = :$field";
            $params[$field] = $value;
        }
    }
    
    // Campo activo
    if (isset($data['activo'])) {
        $value = intval($data['activo']) === 1 ? 1 : 0;
        $updateFields[] = "activo = :activo";
        $params['activo'] = $value;
    }
    
    // Campo notas
    if (isset($data['notas'])) {
        $updateFields[] = "notas = :notas";
        $params['notas'] = $data['notas'];
    }
    
    if (empty($updateFields)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'No se proporcionaron campos para actualizar'
        ]);
        exit;
    }
    
    // Agregar fecha de actualización
    $updateFields[] = "fecha_actualizacion = NOW()";
    
    // Construir y ejecutar consulta de actualización
    $updateQuery = "UPDATE configuracion_precios 
                    SET " . implode(', ', $updateFields) . "
                    WHERE id = :id";
    
    $conn->beginTransaction();
    
    try {
        $updateStmt = $conn->prepare($updateQuery);
        $updateStmt->execute($params);
        
        // Registrar en historial de precios (si existe la tabla)
        $historialQuery = "INSERT INTO historial_precios 
                          (config_id, tipo_vehiculo, campo_modificado, valor_anterior, valor_nuevo, fecha_cambio)
                          VALUES (:config_id, :tipo_vehiculo, :campo, :valor_anterior, :valor_nuevo, NOW())";
        
        try {
            $historialStmt = $conn->prepare($historialQuery);
            
            foreach ($params as $campo => $valorNuevo) {
                if ($campo === 'id') continue;
                
                // Obtener valor anterior
                $oldValueQuery = "SELECT $campo FROM configuracion_precios WHERE id = :id";
                $oldValueStmt = $conn->prepare($oldValueQuery);
                $oldValueStmt->bindParam(':id', $id);
                $oldValueStmt->execute();
                $oldValue = $oldValueStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($oldValue && isset($oldValue[$campo])) {
                    $historialStmt->execute([
                        ':config_id' => $id,
                        ':tipo_vehiculo' => $currentConfig['tipo_vehiculo'],
                        ':campo' => $campo,
                        ':valor_anterior' => $oldValue[$campo],
                        ':valor_nuevo' => $valorNuevo
                    ]);
                }
            }
        } catch (Exception $e) {
            // Si falla el historial, no es crítico
            error_log("Error al guardar historial: " . $e->getMessage());
        }
        
        // Registrar en logs de auditoría
        $logQuery = "INSERT INTO logs_auditoria 
                    (usuario_id, accion, tabla_afectada, registro_id, descripcion, fecha_hora)
                    VALUES (NULL, 'update', 'configuracion_precios', :id, :descripcion, NOW())";
        
        try {
            $logStmt = $conn->prepare($logQuery);
            $logStmt->execute([
                ':id' => $id,
                ':descripcion' => "Configuración de precios actualizada para " . $currentConfig['tipo_vehiculo']
            ]);
        } catch (Exception $e) {
            error_log("Error al guardar log de auditoría: " . $e->getMessage());
        }
        
        $conn->commit();
        
        // Obtener configuración actualizada
        $selectQuery = "SELECT * FROM configuracion_precios WHERE id = :id";
        $selectStmt = $conn->prepare($selectQuery);
        $selectStmt->bindParam(':id', $id);
        $selectStmt->execute();
        $updatedConfig = $selectStmt->fetch(PDO::FETCH_ASSOC);
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Configuración de precios actualizada exitosamente',
            'data' => $updatedConfig
        ], JSON_PRETTY_PRINT);
        
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error en el servidor: ' . $e->getMessage()
    ]);
}
?>
