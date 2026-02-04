<?php
/**
 * Script para agregar municipios a la zona de operación de empresas
 * 
 * Uso: Ejecutar este script para agregar "Cañasgordas" (y otros municipios de Antioquia)
 * a la zona de operación de las empresas Bird y Aguila.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $db->beginTransaction();
    
    // Municipios de Antioquia a agregar a la zona de operación
    $municipiosAntioquia = [
        'Cañasgordas',
        'Medellín',
        'Bello',
        'Envigado',
        'Itagüí',
        'Sabaneta',
        'La Estrella',
        'Copacabana',
        'Girardota',
        'Barbosa',
        'Rionegro',
        'Marinilla',
        'El Carmen de Viboral',
        'La Ceja',
        'Santa Fe de Antioquia',
        'Santafé de Antioquia',
        'San Jerónimo',
        'Sopetrán',
        'Olaya',
        'Liborina',
        'Sabanalarga',
        'Buriticá',
        'Dabeiba',
        'Frontino',
        'Uramita',
        'Peque',
        'Giraldo',
        'Abriaquí',
        'Antioquia' // Cobertura general
    ];
    
    // Obtener empresas Bird y Aguila
    $queryEmpresas = "SELECT id, nombre FROM empresas_transporte WHERE LOWER(nombre) IN ('bird', 'aguila') AND estado = 'activo'";
    $stmt = $db->query($queryEmpresas);
    $empresas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($empresas)) {
        throw new Exception('No se encontraron las empresas Bird y Aguila activas');
    }
    
    $resultados = [];
    
    foreach ($empresas as $empresa) {
        $empresaId = $empresa['id'];
        $empresaNombre = $empresa['nombre'];
        
        // Verificar si existe configuración
        $checkQuery = "SELECT id, zona_operacion FROM empresas_configuracion WHERE empresa_id = ?";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->execute([$empresaId]);
        $config = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($config) {
            // Actualizar zona_operacion existente
            $zonaActual = [];
            if ($config['zona_operacion']) {
                // Parsear array de PostgreSQL
                $zonaStr = trim($config['zona_operacion'], '{}');
                if (!empty($zonaStr)) {
                    $zonaActual = array_map('trim', explode(',', $zonaStr));
                    // Limpiar comillas
                    $zonaActual = array_map(function($z) {
                        return trim($z, '"\'');
                    }, $zonaActual);
                }
            }
            
            // Agregar nuevos municipios (sin duplicados)
            $zonaNueva = array_unique(array_merge($zonaActual, $municipiosAntioquia));
            
            // Formatear para PostgreSQL
            $zonaPostgres = '{' . implode(',', array_map(function($m) {
                return '"' . str_replace('"', '\"', $m) . '"';
            }, $zonaNueva)) . '}';
            
            $updateQuery = "UPDATE empresas_configuracion SET zona_operacion = :zona, actualizado_en = NOW() WHERE empresa_id = :empresa_id";
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->bindParam(':zona', $zonaPostgres);
            $updateStmt->bindParam(':empresa_id', $empresaId, PDO::PARAM_INT);
            $updateStmt->execute();
            
            $resultados[] = [
                'empresa' => $empresaNombre,
                'accion' => 'actualizado',
                'zona_anterior' => $zonaActual,
                'zona_nueva' => $zonaNueva
            ];
        } else {
            // Crear nueva configuración
            $zonaPostgres = '{' . implode(',', array_map(function($m) {
                return '"' . str_replace('"', '\"', $m) . '"';
            }, $municipiosAntioquia)) . '}';
            
            $insertQuery = "INSERT INTO empresas_configuracion (empresa_id, zona_operacion, creado_en) VALUES (:empresa_id, :zona, NOW())";
            $insertStmt = $db->prepare($insertQuery);
            $insertStmt->bindParam(':empresa_id', $empresaId, PDO::PARAM_INT);
            $insertStmt->bindParam(':zona', $zonaPostgres);
            $insertStmt->execute();
            
            $resultados[] = [
                'empresa' => $empresaNombre,
                'accion' => 'creado',
                'zona_nueva' => $municipiosAntioquia
            ];
        }
    }
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Zona de operación actualizada correctamente',
        'empresas_actualizadas' => count($resultados),
        'resultados' => $resultados,
        'municipios_agregados' => $municipiosAntioquia
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    if (isset($db)) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
