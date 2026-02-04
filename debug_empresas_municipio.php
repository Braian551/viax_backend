<?php
/**
 * Script de diagnóstico para verificar empresas y sus municipios/zonas de operación
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // 1. Obtener todas las empresas activas con su configuración
    $query = "
        SELECT 
            e.id,
            e.nombre,
            e.estado,
            e.verificada,
            ec.municipio,
            ec.departamento,
            ecf.zona_operacion,
            (SELECT COUNT(*) FROM usuarios u WHERE u.empresa_id = e.id AND u.tipo_usuario = 'conductor' AND u.es_activo = 1) as total_conductores,
            (SELECT COUNT(*) FROM empresa_tipos_vehiculo etv WHERE etv.empresa_id = e.id AND etv.activo = true) as tipos_vehiculo_count
        FROM empresas_transporte e
        LEFT JOIN empresas_contacto ec ON e.id = ec.empresa_id
        LEFT JOIN empresas_configuracion ecf ON e.id = ecf.empresa_id
        WHERE e.estado = 'activo'
        ORDER BY e.nombre
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute();
    $empresas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 2. Obtener municipios únicos configurados
    $municipiosQuery = "
        SELECT DISTINCT municipio 
        FROM empresas_contacto 
        WHERE municipio IS NOT NULL AND municipio != ''
        ORDER BY municipio
    ";
    $stmt = $db->query($municipiosQuery);
    $municipiosUnicos = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // 3. Obtener zonas de operación únicas
    $zonasQuery = "
        SELECT DISTINCT unnest(zona_operacion) as zona
        FROM empresas_configuracion
        WHERE zona_operacion IS NOT NULL AND array_length(zona_operacion, 1) > 0
        ORDER BY zona
    ";
    $stmt = $db->query($zonasQuery);
    $zonasUnicas = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // 4. Verificar si hay empresas que cubran Cañasgordas
    $testMunicipio = 'Cañasgordas';
    $queryTest = "
        SELECT 
            e.id, e.nombre, ec.municipio, ecf.zona_operacion
        FROM empresas_transporte e
        LEFT JOIN empresas_contacto ec ON e.id = ec.empresa_id
        LEFT JOIN empresas_configuracion ecf ON e.id = ecf.empresa_id
        WHERE e.estado = 'activo' AND e.verificada = true
        AND (
            LOWER(ec.municipio) = LOWER(:municipio)
            OR ec.municipio ILIKE :municipio_like
            OR LOWER(:municipio) = ANY(SELECT LOWER(unnest(ecf.zona_operacion)))
        )
    ";
    $stmt = $db->prepare($queryTest);
    $stmt->bindParam(':municipio', $testMunicipio);
    $municipioLike = '%' . $testMunicipio . '%';
    $stmt->bindParam(':municipio_like', $municipioLike);
    $stmt->execute();
    $empresasCanasgordas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'diagnostico' => [
            'total_empresas_activas' => count($empresas),
            'municipios_configurados' => $municipiosUnicos,
            'zonas_operacion_configuradas' => $zonasUnicas,
            'test_canasgordas' => [
                'municipio_buscado' => $testMunicipio,
                'empresas_encontradas' => count($empresasCanasgordas),
                'empresas' => $empresasCanasgordas
            ]
        ],
        'empresas' => array_map(function($e) {
            return [
                'id' => $e['id'],
                'nombre' => $e['nombre'],
                'estado' => $e['estado'],
                'verificada' => $e['verificada'],
                'municipio' => $e['municipio'],
                'departamento' => $e['departamento'],
                'zona_operacion' => $e['zona_operacion'] ? 
                    json_decode(str_replace(['{', '}'], ['[', ']'], $e['zona_operacion']), true) : [],
                'total_conductores' => intval($e['total_conductores']),
                'tipos_vehiculo_count' => intval($e['tipos_vehiculo_count'])
            ];
        }, $empresas),
        'recomendacion' => count($empresasCanasgordas) === 0 ? 
            'Las empresas Bird y Aguila NO tienen configurado "Cañasgordas" como municipio ni en su zona de operación. Debes agregar "Cañasgordas" a la columna zona_operacion en empresas_configuracion o actualizar el municipio en empresas_contacto.' : 
            'Las empresas están correctamente configuradas para Cañasgordas.'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
