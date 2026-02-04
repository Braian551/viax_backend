<?php
require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    echo "Testing UNACCENT...\n";
    
    // Try to use unaccent
    try {
        $sql = "SELECT unaccent('Águila')";
        $stmt = $db->query($sql);
        $result = $stmt->fetchColumn();
        echo "UNACCENT result: $result\n";
    } catch (Exception $e) {
        echo "UNACCENT failed: " . $e->getMessage() . "\n";
        
        // Fallback test
        echo "Testing TRANSLATE fallback...\n";
        $sql = "SELECT translate(lower('Águila'), 'áéíóúÁÉÍÓÚ', 'aeiouAEIOU')";
        $stmt = $db->query($sql);
        $result = $stmt->fetchColumn();
        echo "TRANSLATE result: $result\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
