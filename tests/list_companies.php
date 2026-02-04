<?php
require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    $stmt = $db->query("SELECT id, nombre, estado, nit, logo_url FROM empresas_transporte");
    $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Total Companies: " . count($companies) . "\n";
    print_r($companies);

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
