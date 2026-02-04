<?php
require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Read SQL file
    $sql = file_get_contents(__DIR__ . '/020_create_colors_table.sql');
    
    // Split by semicolons to execute multiple statements if needed, 
    // but typically we can run specific blocks. 
    // For simplicity with this driver, let's run it command by command or as a block if supported.
    // PDO::exec supports multiple statements if the driver allows it, but it's safer to split.
    
    $statements = array_filter(array_map('trim', explode(';', $sql)));

    foreach ($statements as $statement) {
        if (!empty($statement)) {
            $db->exec($statement);
        }
    }
    
    echo "Migration 020 executed successfully.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1); // Exit with error code
}
?>
