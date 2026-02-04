<?php
require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    echo "Testing query with ILIKE...\n";
    $query = "aguila"; // Unaccented
    $term = "%$query%";
    
    $sql = "SELECT * FROM empresas_transporte WHERE nombre ILIKE :query";
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':query', $term);
    $stmt->execute();
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Results found: " . count($results) . "\n";
    print_r($results);

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
