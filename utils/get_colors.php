<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    $query = "SELECT id, nombre, hex_code FROM colores_vehiculo WHERE activo = TRUE ORDER BY nombre ASC";
    $stmt = $db->prepare($query);
    $stmt->execute();

    $colors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => $colors
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching colors: ' . $e->getMessage()
    ]);
}
?>
