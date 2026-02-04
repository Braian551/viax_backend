<?php
require_once __DIR__ . '/../config/database.php';

echo "Iniciando Migración 019: Agregar foto_vehiculo...\n";

try {
    $database = new Database();
    $db = $database->getConnection();

    // Read SQL file
    $sql = file_get_contents(__DIR__ . '/019_add_vehicle_photo.sql');
    
    if (!$sql) {
        throw new Exception("No se pudo leer el archivo SQL");
    }

    // Execute
    $db->exec($sql);
    echo "¡Migración 019 completada con éxito!\n";
    
    // Verify
    $stmt = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name='detalles_conductor' AND column_name='foto_vehiculo'");
    if ($stmt->fetch()) {
        echo "VERIFICACIÓN: Columna 'foto_vehiculo' existe.\n";
    } else {
        echo "ERROR DE VERIFICACIÓN: Columna no encontrada.\n";
    }

} catch (Exception $e) {
    echo "Error crítico: " . $e->getMessage() . "\n";
    exit(1);
}
?>
