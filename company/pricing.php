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
        
        // 2. Obtener vehículos ACTIVOS de la empresa (fuente principal de verdad)
        $queryActivos = "SELECT tipo_vehiculo_codigo FROM empresa_tipos_vehiculo WHERE empresa_id = ? AND activo = true";
        $stmtActivos = $db->prepare($queryActivos);
        $stmtActivos->execute([$empresaId]);
        $vehiculosActivosRaw = $stmtActivos->fetchAll(PDO::FETCH_COLUMN);
        // Normalizamos para evitar desalineación entre catálogos legacy y nuevos.
        $vehiculosActivos = array_values(array_unique(array_map('normalizeVehicleTypeCode', $vehiculosActivosRaw))); // ['moto', 'carro']
        
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
        
        // Organizar por tipo_vehiculo ya normalizado
        $merged = [];
        
        // A. Mapear globales como base
        foreach ($globalPrices as $p) {
            $type = normalizeVehicleTypeCode($p['tipo_vehiculo'] ?? '');
            if ($type === '') {
                continue;
            }
            $p['tipo_vehiculo'] = $type;
            $p['es_global'] = true;
            $p['heredado'] = true;
            $merged[$type] = $p;
        }
        
        // B. Sobrescribir con empresa
        foreach ($empresaPrices as $p) {
            $type = normalizeVehicleTypeCode($p['tipo_vehiculo'] ?? '');
            if ($type === '') {
                continue;
            }
            $p['tipo_vehiculo'] = $type;
            $p['es_global'] = false;
            $p['heredado'] = false;
            $merged[$type] = $p;
        }
        
        // 5. FILTRADO FINAL: solo devolver lo que está activo para la empresa
        $finalResult = [];
        
        foreach ($merged as $type => $price) {
            $isVisible = false;
            
            if ($hasNormalizedData) {
                // Modo estricto: solo si está en la lista activa de la empresa.
                if (in_array($type, $vehiculosActivos)) {
                    $isVisible = true;
                }
            } else {
                // Modo fallback (legacy): solo si el precio está activo.
                if (($price['activo'] ?? 0) == 1) {
                    $isVisible = true;
                }
            }
            
            if ($isVisible) {
                $finalResult[] = $price;
            }
        }
        
        // Orden estable para UI de app/sitio web.
        usort($finalResult, function ($a, $b) {
            $order = [
                'moto' => 1,
                'mototaxi' => 2,
                'taxi' => 3,
                'carro' => 4,
            ];
            $aType = normalizeVehicleTypeCode($a['tipo_vehiculo'] ?? '');
            $bType = normalizeVehicleTypeCode($b['tipo_vehiculo'] ?? '');
            $aOrder = $order[$aType] ?? 999;
            $bOrder = $order[$bType] ?? 999;
            if ($aOrder === $bOrder) {
                return strcmp($aType, $bType);
            }
            return $aOrder <=> $bOrder;
        });

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
            $tipo = normalizeVehicleTypeCode($precio['tipo_vehiculo'] ?? '');
            if ($tipo === '') {
                continue;
            }
            $tipoAliases = getVehicleTypeAliases($tipo);
            
            // Buscar coincidencias también por alias para actualizar datos legacy.
            $inPlaceholders = implode(',', array_fill(0, count($tipoAliases), '?'));
            $check = "SELECT id FROM configuracion_precios WHERE empresa_id = ? AND tipo_vehiculo IN ($inPlaceholders) LIMIT 1";
            $stmtCheck = $db->prepare($check);
            $stmtCheck->execute(array_merge([$empresaId], $tipoAliases));
            $exists = $stmtCheck->fetch();
            
            if ($exists) {
                // Update completo y canonización de tipo_vehiculo.
                $sql = "UPDATE configuracion_precios SET 
                        tipo_vehiculo = ?,
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
                    $tipo,
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
                // Insert completo en formato canónico.
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

function normalizeVehicleTypeCode($type) {
    $value = strtolower(trim((string)$type));

    if ($value === '') {
        return '';
    }

    // Alias históricos usados en distintas migraciones/versiones.
    $aliases = [
        'auto' => 'carro',
        'automovil' => 'carro',
        'car' => 'carro',
        'motocarro' => 'mototaxi',
        'moto_carga' => 'mototaxi',
        'moto carga' => 'mototaxi',
    ];

    return $aliases[$value] ?? $value;
}

function getVehicleTypeAliases($canonicalType) {
    $canonical = normalizeVehicleTypeCode($canonicalType);

    // Conjunto de alias permitidos para encontrar registros antiguos.
    $aliasByCanonical = [
        'carro' => ['carro', 'auto', 'automovil', 'car'],
        'mototaxi' => ['mototaxi', 'motocarro', 'moto_carga', 'moto carga'],
        'moto' => ['moto'],
        'taxi' => ['taxi'],
    ];

    return $aliasByCanonical[$canonical] ?? [$canonical];
}
?>
