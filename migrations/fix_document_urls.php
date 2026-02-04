<?php
// Migration script to fix document URLs
// Removes 'r2_proxy.php?key=' prefix from stored paths

require_once __DIR__ . '/../config/database.php';

$database = new Database();
$db = $database->getConnection();

$columns = [
    'licencia_foto_url',
    'soat_foto_url',
    'tecnomecanica_foto_url',
    'tarjeta_propiedad_foto_url',
    'seguro_foto_url'
];

$prefix = 'r2_proxy.php?key=';
$prefixLen = strlen($prefix);

$fixed = 0;

foreach ($columns as $col) {
    // Find records with the bad prefix
    $stmt = $db->prepare("SELECT id, $col FROM detalles_conductor WHERE $col LIKE :pattern");
    $stmt->execute([':pattern' => $prefix . '%']);
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $oldVal = $row[$col];
        $newVal = substr($oldVal, $prefixLen); // Strip the prefix
        
        $update = $db->prepare("UPDATE detalles_conductor SET $col = :newval WHERE id = :id");
        $update->execute([':newval' => $newVal, ':id' => $row['id']]);
        $fixed++;
        echo "Fixed $col (ID {$row['id']}): $oldVal -> $newVal\n";
    }
}

// Also fix documentos_verificacion table
$stmt2 = $db->prepare("SELECT id, ruta_archivo FROM documentos_verificacion WHERE ruta_archivo LIKE :pattern");
$stmt2->execute([':pattern' => $prefix . '%']);
while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
    $oldVal = $row['ruta_archivo'];
    $newVal = substr($oldVal, $prefixLen);
    
    $update = $db->prepare("UPDATE documentos_verificacion SET ruta_archivo = :newval WHERE id = :id");
    $update->execute([':newval' => $newVal, ':id' => $row['id']]);
    $fixed++;
    echo "Fixed documentos_verificacion (ID {$row['id']}): $oldVal -> $newVal\n";
}

echo "\nDone. Fixed $fixed records.\n";
?>
