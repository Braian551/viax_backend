<?php
/**
 * Company Pricing API - Full Features
 * Permite a las empresas gestionar sus propias tarifas completas
 */

require_once '../config/config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    $database = new Database();
    $db = $database->getConnection();
    $method = $_SERVER['REQUEST_METHOD'];
    
    if ($method === 'GET') {
        $input = $_GET;
    } else {
        $input = getJsonInput();
    }

    $empresaId = isset($input['empresa_id']) ? intval($input['empresa_id']) : null;
    
    if (!$empresaId) {
        sendJsonResponse(false, 'Falta parametro empresa_id');
        exit();
    }

    switch ($method) {
        case 'GET':
            handleGetPricing($db, $empresaId);
            break;
        case 'POST':
        case 'PUT':
            handleUpdatePricing($db, $input, $empresaId);
            break;
        default:
            sendJsonResponse(false, 'Método no permitido');
    }

} catch (Exception $e) {
    error_log("Error in company/pricing.php: " . $e->getMessage());
    sendJsonResponse(false, 'Error del servidor: ' . $e->getMessage());
}

function handleGetPricing($db, $empresaId) {
    try {
        // 1. Obtener info de la empresa (incluyendo comisión admin)
        $empresaQuery = "SELECT id, nombre, comision_admin_porcentaje, saldo_pendiente 
                         FROM empresas_transporte WHERE id = ?";
        $empresaStmt = $db->prepare($empresaQuery);
        $empresaStmt->execute([$empresaId]);
        $empresaInfo = $empresaStmt->fetch(PDO::FETCH_ASSOC);
        
        // 2. Obtener vehículos ACTIVOS de la empresa (Source of Truth)
        $queryActivos = "SELECT tipo_vehiculo_codigo FROM empresa_tipos_vehiculo WHERE empresa_id = ? AND activo = true";
        $stmtActivos = $db->prepare($queryActivos);
        $stmtActivos->execute([$empresaId]);
        $vehiculosActivos = $stmtActivos->fetchAll(PDO::FETCH_COLUMN); // ['moto', 'carro']
        
        // Flag para saber si usamos la tabla normalizada (si tiene registros)
        // Si no tiene registros, asumimos modo legacy o nuevo sin config
        $hasNormalizedData = count($vehiculosActivos) > 0;
        
        // 3. Obtener configuración global (default)
        $queryGlobal = "SELECT * FROM configuracion_precios WHERE empresa_id IS NULL AND activo = 1";
        $stmtGlobal = $db->query($queryGlobal);
        $globalPrices = $stmtGlobal->fetchAll(PDO::FETCH_ASSOC);
        
        // 4. Obtener configuración específica de la empresa
        $queryEmpresa = "SELECT * FROM configuracion_precios WHERE empresa_id = ?";
        $stmtEmpresa = $db->prepare($queryEmpresa);
        $stmtEmpresa->execute([$empresaId]);
        $empresaPrices = $stmtEmpresa->fetchAll(PDO::FETCH_ASSOC);
        
        // Organizar por tipo_vehiculo
        $merged = [];
        
        // A. Mapear globales como base
        foreach ($globalPrices as $p) {
            $type = $p['tipo_vehiculo'];
            $p['es_global'] = true;
            $p['heredado'] = true;
            $merged[$type] = $p;
        }
        
        // B. Sobrescribir con empresa
        foreach ($empresaPrices as $p) {
            $type = $p['tipo_vehiculo'];
            $p['es_global'] = false;
            $p['heredado'] = false;
            $merged[$type] = $p;
        }
        
        // 5. FILTRADO FINAL: Solo devolver lo que está activo
        $finalResult = [];
        
        foreach ($merged as $type => $price) {
            $isVisible = false;
            
            if ($hasNormalizedData) {
                // Modo Estricto: Solo si está en la lista de activos de la empresa
                if (in_array($type, $vehiculosActivos)) {
                    $isVisible = true;
                }
            } else {
                // Modo Fallback (Legacy): Si el precio tiene el flag activo
                // O si es un precio de la empresa (incluso si activo=0, para que puedan verlo y reactivarlo? 
                // No, el usuario pidió que NO aparezca si no está habilitado)
                if (($price['activo'] ?? 0) == 1) {
                    $isVisible = true;
                }
            }
            
            if ($isVisible) {
                $finalResult[] = $price;
            }
        }
        
        sendJsonResponse(true, 'Tarifas obtenidas', [
            'precios' => $finalResult,
            'empresa' => $empresaInfo ? [
                'id' => $empresaInfo['id'],
                'nombre' => $empresaInfo['nombre'],
                'comision_admin_porcentaje' => floatval($empresaInfo['comision_admin_porcentaje'] ?? 0),
                'saldo_pendiente' => floatval($empresaInfo['saldo_pendiente'] ?? 0)
            ] : null
        ]);
        
    } catch (Exception $e) {
        sendJsonResponse(false, 'Error al leer tarifas: ' . $e->getMessage());
    }
}

function handleUpdatePricing($db, $input, $empresaId) {
    try {
        // Validar si viene un precio individual o array
        $precios = [];
        if (isset($input['precios']) && is_array($input['precios'])) {
            $precios = $input['precios'];
        } elseif (isset($input['tipo_vehiculo'])) {
            // Precio individual
            $precios = [$input];
        } else {
            sendJsonResponse(false, 'Se requiere datos de precios');
            return;
        }
        
        $db->beginTransaction();
        
        foreach ($precios as $precio) {
            $tipo = $precio['tipo_vehiculo'];
            
            // Verificar si existe para actualizar o insertar
            $check = "SELECT id FROM configuracion_precios WHERE empresa_id = ? AND tipo_vehiculo = ?";
            $stmtCheck = $db->prepare($check);
            $stmtCheck->execute([$empresaId, $tipo]);
            $exists = $stmtCheck->fetch();
            
            if ($exists) {
                // Update completo
                $sql = "UPDATE configuracion_precios SET 
                        tarifa_base = ?, 
                        costo_por_km = ?, 
                        costo_por_minuto = ?, 
                        tarifa_minima = ?,
                        tarifa_maxima = ?,
                        recargo_hora_pico = ?, 
                        recargo_nocturno = ?, 
                        recargo_festivo = ?,
                        descuento_distancia_larga = ?,
                        umbral_km_descuento = ?,
                        comision_plataforma = ?,
                        comision_metodo_pago = ?,
                        distancia_minima = ?,
                        distancia_maxima = ?,
                        tiempo_espera_gratis = ?,
                        costo_tiempo_espera = ?,
                        activo = ?,
                        fecha_actualizacion = NOW()
                        WHERE id = ?";
                $stmt = $db->prepare($sql);
                $stmt->execute([
                    $precio['tarifa_base'] ?? 0,
                    $precio['costo_por_km'] ?? 0,
                    $precio['costo_por_minuto'] ?? 0,
                    $precio['tarifa_minima'] ?? 0,
                    $precio['tarifa_maxima'] ?? null,
                    $precio['recargo_hora_pico'] ?? 0,
                    $precio['recargo_nocturno'] ?? 0,
                    $precio['recargo_festivo'] ?? 0,
                    $precio['descuento_distancia_larga'] ?? 0,
                    $precio['umbral_km_descuento'] ?? 15,
                    $precio['comision_plataforma'] ?? 0,
                    $precio['comision_metodo_pago'] ?? 0,
                    $precio['distancia_minima'] ?? 1,
                    $precio['distancia_maxima'] ?? 50,
                    $precio['tiempo_espera_gratis'] ?? 3,
                    $precio['costo_tiempo_espera'] ?? 0,
                    $precio['activo'] ?? 1,
                    $exists['id']
                ]);
            } else {
                // Insert completo
                $sql = "INSERT INTO configuracion_precios (
                        empresa_id, tipo_vehiculo, tarifa_base, costo_por_km, costo_por_minuto,
                        tarifa_minima, tarifa_maxima, recargo_hora_pico, recargo_nocturno, 
                        recargo_festivo, descuento_distancia_larga, umbral_km_descuento,
                        comision_plataforma, comision_metodo_pago, distancia_minima, 
                        distancia_maxima, tiempo_espera_gratis, costo_tiempo_espera, activo
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $db->prepare($sql);
                $stmt->execute([
                    $empresaId, $tipo,
                    $precio['tarifa_base'] ?? 0,
                    $precio['costo_por_km'] ?? 0,
                    $precio['costo_por_minuto'] ?? 0,
                    $precio['tarifa_minima'] ?? 0,
                    $precio['tarifa_maxima'] ?? null,
                    $precio['recargo_hora_pico'] ?? 0,
                    $precio['recargo_nocturno'] ?? 0,
                    $precio['recargo_festivo'] ?? 0,
                    $precio['descuento_distancia_larga'] ?? 0,
                    $precio['umbral_km_descuento'] ?? 15,
                    $precio['comision_plataforma'] ?? 0,
                    $precio['comision_metodo_pago'] ?? 0,
                    $precio['distancia_minima'] ?? 1,
                    $precio['distancia_maxima'] ?? 50,
                    $precio['tiempo_espera_gratis'] ?? 3,
                    $precio['costo_tiempo_espera'] ?? 0,
                    $precio['activo'] ?? 1
                ]);
            }
        }
        
        $db->commit();
        sendJsonResponse(true, 'Tarifas actualizadas correctamente');
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Error Update Pricing: " . $e->getMessage());
        sendJsonResponse(false, 'Error al actualizar: ' . $e->getMessage());
    }
}
?>
