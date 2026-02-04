<?php
require_once '../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    $stmt = $db->prepare("UPDATE empresas_transporte SET logo_url = REPLACE(logo_url, 'https://pub-9e36b59ddd8dc8dcc4edc374e6140fda.r2.dev/', 'r2_proxy.php?key=') WHERE logo_url LIKE 'https://pub-9e36b59ddd8dc8dcc4edc374e6140fda.r2.dev/%'");
    $stmt->execute();
    echo "Empresas actualizadas: " . $stmt->rowCount() . "\n";

    // Update vehicle photos too if any
    $stmt2 = $db->prepare("UPDATE detalles_conductor SET foto_vehiculo = REPLACE(foto_vehiculo, 'https://pub-9e36b59ddd8dc8dcc4edc374e6140fda.r2.dev/', 'r2_proxy.php?key=') WHERE foto_vehiculo LIKE 'https://pub-9e36b59ddd8dc8dcc4edc374e6140fda.r2.dev/%'");
    $stmt2->execute();
    echo "Fotos vehículos actualizadas: " . $stmt2->rowCount() . "\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
