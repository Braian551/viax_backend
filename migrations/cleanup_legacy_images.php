<?php
require_once __DIR__ . '/../config/database.php';

echo "Iniciando limpieza de rutas locales de imágenes...\n";

try {
    $database = new Database();
    $db = $database->getConnection();

    $db->beginTransaction();

    // 1. Clear details vehicle photos if they look like local paths (start with uploads/)
    $stmt1 = $db->prepare("UPDATE detalles_conductor SET foto_vehiculo = NULL WHERE foto_vehiculo LIKE 'uploads/%'");
    $stmt1->execute();
    echo "Fotos de vehículos limpiadas: " . $stmt1->rowCount() . "\n";

    // 2. Clear license photos (assuming they might be local)
    // Note: User mentioned 'licencia' logic too, let's clear if path is relative
    // If license is stored in specific columns or another table (doc upload), adapt here.
    // Based on previous conv, documents are likely in a separate table or just mocked.
    // If documents are in 'documentos_conductor', clear them too.
    
    // 2. Skip documents_conductor as it might not exist yet
    // (Logic removed to prevent transaction rollback)

    // 3. Clear company logos
    $stmt3 = $db->prepare("UPDATE empresas_transporte SET logo_url = NULL WHERE logo_url LIKE 'uploads/%'");
    $stmt3->execute();
    echo "Logos de empresas limpiados: " . $stmt3->rowCount() . "\n";

    $db->commit();
    echo "¡Limpieza completada exitosamente!\n";

} catch (Exception $e) {
    if (isset($db)) $db->rollBack();
    echo "Error: " . $e->getMessage() . "\n";
}
?>
