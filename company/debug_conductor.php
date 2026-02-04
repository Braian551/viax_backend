<?php
/**
 * Debug script to check conductor document status
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: text/plain; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $conductor_id = isset($_GET['id']) ? intval($_GET['id']) : 5;
    
    echo "=== Debug: Conductor ID $conductor_id ===\n\n";
    
    // 1. Check user table
    echo "1. User (usuarios table):\n";
    $stmt = $db->prepare("SELECT id, nombre, apellido, email, tipo_usuario, es_activo, es_verificado, empresa_id FROM usuarios WHERE id = :id");
    $stmt->bindParam(':id', $conductor_id, PDO::PARAM_INT);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        foreach ($user as $key => $value) {
            echo "   $key: $value\n";
        }
    } else {
        echo "   ❌ User not found!\n";
    }
    
    // 2. Check detalles_conductor table
    echo "\n2. Detalles Conductor (detalles_conductor table):\n";
    $stmt = $db->prepare("SELECT * FROM detalles_conductor WHERE usuario_id = :id");
    $stmt->bindParam(':id', $conductor_id, PDO::PARAM_INT);
    $stmt->execute();
    $detalles = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($detalles) {
        foreach ($detalles as $key => $value) {
            if (!empty($value) || $key === 'estado_verificacion') {
                echo "   $key: " . ($value ?? 'NULL') . "\n";
            }
        }
    } else {
        echo "   ❌ No record in detalles_conductor for this user!\n";
    }
    
    // 3. Check if there are any solicitudes
    echo "\n3. Solicitudes de vinculación:\n";
    $stmt = $db->prepare("SELECT * FROM solicitudes_vinculacion_conductor WHERE conductor_id = :id");
    $stmt->bindParam(':id', $conductor_id, PDO::PARAM_INT);
    $stmt->execute();
    $solicitudes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($solicitudes) > 0) {
        foreach ($solicitudes as $sol) {
            echo "   Solicitud ID: {$sol['id']}, Estado: {$sol['estado']}, Empresa ID: {$sol['empresa_id']}\n";
        }
    } else {
        echo "   No solicitudes found\n";
    }
    
    // 4. List all conductors
    echo "\n4. All conductors linked to empresa 1:\n";
    $stmt = $db->query("SELECT u.id, u.nombre, u.apellido, dc.estado_verificacion 
                        FROM usuarios u 
                        LEFT JOIN detalles_conductor dc ON u.id = dc.usuario_id 
                        WHERE u.empresa_id = 1 OR u.id IN (SELECT conductor_id FROM solicitudes_vinculacion_conductor WHERE empresa_id = 1)
                        LIMIT 10");
    $conductores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($conductores as $c) {
        echo "   ID: {$c['id']}, Nombre: {$c['nombre']} {$c['apellido']}, Estado: " . ($c['estado_verificacion'] ?? 'NULL') . "\n";
    }
    
    echo "\n✅ Debug completed!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>
