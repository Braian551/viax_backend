<?php
require_once 'config/database.php';

try {
    $db = (new Database())->getConnection();
    
    // Add distancia_recorrida
    try {
        $db->exec("ALTER TABLE solicitudes_servicio ADD COLUMN distancia_recorrida DECIMAL(10,2) DEFAULT 0");
        echo "Columna distancia_recorrida agregada.\n";
    } catch (PDOException $e) {
        echo "Columna distancia_recorrida ya existe o error: " . $e->getMessage() . "\n";
    }

    // Add tiempo_transcurrido
    try {
        $db->exec("ALTER TABLE solicitudes_servicio ADD COLUMN tiempo_transcurrido INT DEFAULT 0");
        echo "Columna tiempo_transcurrido agregada.\n";
    } catch (PDOException $e) {
        echo "Columna tiempo_transcurrido ya existe o error: " . $e->getMessage() . "\n";
    }

    echo "Migración completada.\n";

} catch (Exception $e) {
    echo "Error general: " . $e->getMessage() . "\n";
}
?>
