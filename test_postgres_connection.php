<?php
// backend/test_postgres_connection.php
// Test de conexión a PostgreSQL

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once 'config/database.php';

$response = [
    'test' => 'PostgreSQL Connection Test',
    'timestamp' => date('Y-m-d H:i:s'),
    'status' => 'pending',
    'details' => []
];

try {
    // Crear instancia de la base de datos
    $database = new Database();
    
    // Intentar obtener la conexión
    $conn = $database->getConnection();
    
    if ($conn) {
        $response['status'] = 'success';
        $response['message'] = '✅ Conexión a PostgreSQL establecida correctamente';
        
        // Obtener información del servidor
        $stmt = $conn->query("SELECT version()");
        $version = $stmt->fetch();
        $response['details']['server_version'] = $version['version'];
        
        // Obtener el nombre de la base de datos actual
        $stmt = $conn->query("SELECT current_database()");
        $dbName = $stmt->fetch();
        $response['details']['database_name'] = $dbName['current_database'];
        
        // Obtener el usuario actual
        $stmt = $conn->query("SELECT current_user");
        $user = $stmt->fetch();
        $response['details']['current_user'] = $user['current_user'];
        
        // Listar tablas existentes en la base de datos
        $stmt = $conn->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' ORDER BY table_name");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $response['details']['tables_count'] = count($tables);
        $response['details']['tables'] = $tables;
        
    } else {
        $response['status'] = 'error';
        $response['message'] = '❌ No se pudo establecer la conexión';
    }
    
} catch (Exception $e) {
    $response['status'] = 'error';
    $response['message'] = '❌ Error de conexión';
    $response['error'] = $e->getMessage();
    
    // Sugerencias de solución
    $response['suggestions'] = [
        '1. Verifica que PostgreSQL esté corriendo',
        '2. Verifica que la base de datos "viax" exista',
        '3. Verifica las credenciales (usuario: postgres, contraseña: root)',
        '4. Verifica que el puerto 5432 esté disponible',
        '5. Verifica que la extensión pdo_pgsql esté habilitada en PHP'
    ];
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
