<?php
/**
 * Script de migración para sincronizar zona_operacion de empresas existentes
 * 
 * Este script actualiza la zona_operacion de todas las empresas activas
 * basándose en su municipio registrado en empresas_contacto O empresas_transporte.
 * 
 * Ejecutar una sola vez después de implementar la nueva lógica de aprobación.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "=== Sincronizando zona_operacion de empresas existentes ===\n\n";
    
    // Obtener todas las empresas activas con su municipio de contacto Y de la tabla principal
    $query = "
        SELECT 
            e.id,
            e.nombre,
            e.estado,
            e.municipio as municipio_principal,
            e.departamento as departamento_principal,
            ec.municipio as municipio_contacto,
            ec.departamento as departamento_contacto,
            ecf.zona_operacion
        FROM empresas_transporte e
        LEFT JOIN empresas_contacto ec ON e.id = ec.empresa_id
        LEFT JOIN empresas_configuracion ecf ON e.id = ecf.empresa_id
        WHERE e.estado = 'activo'
        ORDER BY e.nombre
    ";
    
    $stmt = $db->query($query);
    $empresas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $actualizadas = 0;
    $errores = 0;
    $resultados = [];
    
    foreach ($empresas as $empresa) {
        $empresaId = $empresa['id'];
        
        // Usar municipio de empresas_contacto primero, luego de empresas_transporte
        $municipio = trim($empresa['municipio_contacto'] ?? '') ?: trim($empresa['municipio_principal'] ?? '');
        $departamento = trim($empresa['departamento_contacto'] ?? '') ?: trim($empresa['departamento_principal'] ?? '');
        
        if (empty($municipio)) {
            $resultados[] = [
                'empresa' => $empresa['nombre'],
                'id' => $empresaId,
                'estado' => 'omitido',
                'razon' => 'Sin municipio configurado en ninguna tabla'
            ];
            continue;
        }
        
        // Parsear zona actual si existe
        $zonaActual = [];
        if ($empresa['zona_operacion']) {
            $zonaStr = trim($empresa['zona_operacion'], '{}');
            if (!empty($zonaStr)) {
                $zonaActual = array_map(function($z) {
                    return trim(trim($z), '"\'');
                }, explode(',', $zonaStr));
            }
        }
        
        // Verificar si el municipio ya está en la zona
        $municipioLower = strtolower($municipio);
        $yaExiste = false;
        foreach ($zonaActual as $zona) {
            if (strtolower(trim($zona)) === $municipioLower) {
                $yaExiste = true;
                break;
            }
        }
        
        if ($yaExiste) {
            $resultados[] = [
                'empresa' => $empresa['nombre'],
                'id' => $empresaId,
                'estado' => 'sin_cambios',
                'razon' => "Municipio '$municipio' ya está en zona_operacion"
            ];
            continue;
        }
        
        // Agregar municipio y departamento
        $zonaNueva = $zonaActual;
        $zonaNueva[] = $municipio;
        
        if (!empty($departamento)) {
            $depLower = strtolower($departamento);
            $depExiste = false;
            foreach ($zonaNueva as $zona) {
                if (strtolower(trim($zona)) === $depLower) {
                    $depExiste = true;
                    break;
                }
            }
            if (!$depExiste) {
                $zonaNueva[] = $departamento;
            }
        }
        
        // Formatear para PostgreSQL
        $zonaPostgres = '{' . implode(',', array_map(function($m) {
            return '"' . str_replace('"', '\"', trim($m)) . '"';
        }, array_unique(array_filter($zonaNueva)))) . '}';
        
        try {
            // Verificar si existe configuración
            $checkQuery = "SELECT id FROM empresas_configuracion WHERE empresa_id = ?";
            $checkStmt = $db->prepare($checkQuery);
            $checkStmt->execute([$empresaId]);
            $configExists = $checkStmt->fetch();
            
            if ($configExists) {
                $updateQuery = "UPDATE empresas_configuracion 
                               SET zona_operacion = :zona, actualizado_en = NOW() 
                               WHERE empresa_id = :empresa_id";
                $updateStmt = $db->prepare($updateQuery);
                $updateStmt->bindParam(':zona', $zonaPostgres);
                $updateStmt->bindParam(':empresa_id', $empresaId, PDO::PARAM_INT);
                $updateStmt->execute();
            } else {
                $insertQuery = "INSERT INTO empresas_configuracion (empresa_id, zona_operacion, creado_en) 
                               VALUES (:empresa_id, :zona, NOW())";
                $insertStmt = $db->prepare($insertQuery);
                $insertStmt->bindParam(':empresa_id', $empresaId, PDO::PARAM_INT);
                $insertStmt->bindParam(':zona', $zonaPostgres);
                $insertStmt->execute();
            }
            
            // También asegurar que empresas_contacto tenga los datos
            $checkContacto = $db->prepare("SELECT id FROM empresas_contacto WHERE empresa_id = ?");
            $checkContacto->execute([$empresaId]);
            
            if (!$checkContacto->fetch()) {
                // Crear registro de contacto si no existe
                $insertContacto = $db->prepare("
                    INSERT INTO empresas_contacto (empresa_id, municipio, departamento, creado_en) 
                    VALUES (?, ?, ?, NOW())
                ");
                $insertContacto->execute([$empresaId, $municipio, $departamento]);
            }
            
            $actualizadas++;
            $resultados[] = [
                'empresa' => $empresa['nombre'],
                'id' => $empresaId,
                'estado' => 'actualizado',
                'municipio_origen' => $empresa['municipio_contacto'] ? 'empresas_contacto' : 'empresas_transporte',
                'zona_anterior' => $zonaActual,
                'zona_nueva' => $zonaNueva
            ];
            
        } catch (Exception $e) {
            $errores++;
            $resultados[] = [
                'empresa' => $empresa['nombre'],
                'id' => $empresaId,
                'estado' => 'error',
                'error' => $e->getMessage()
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'mensaje' => "Sincronización completada",
        'resumen' => [
            'total_empresas' => count($empresas),
            'actualizadas' => $actualizadas,
            'errores' => $errores,
            'sin_cambios' => count($empresas) - $actualizadas - $errores
        ],
        'resultados' => $resultados
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
