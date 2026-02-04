<?php
require_once __DIR__ . '/config/database.php';

$db = (new Database())->getConnection();
$r = $db->query("SELECT column_name, is_nullable, column_default FROM information_schema.columns WHERE table_name = 'usuarios' ORDER BY ordinal_position");

echo "=== usuarios columns ===\n\n";
foreach($r as $c) {
    echo $c['column_name'] . ' | nullable: ' . $c['is_nullable'] . ' | default: ' . ($c['column_default'] ?? 'NULL') . "\n";
}
/*
// Also try an INSERT and see if it works
echo "\n=== Testing INSERT for conductor_id 5 ===\n";
try {
    $db->beginTransaction();
    
    $sql = "INSERT INTO detalles_conductor (usuario_id, estado_verificacion, fecha_ultima_verificacion, creado_en, actualizado_en) 
            VALUES (5, 'aprobado', NOW(), NOW(), NOW())";
    $result = $db->exec($sql);
    echo "INSERT result: $result rows affected\n";
    
    $db->commit();
    echo "✅ INSERT successful!\n";
} catch (Exception $e) {
    $db->rollBack();
    echo "❌ INSERT failed: " . $e->getMessage() . "\n";
}
*/

// Verify
$stmt = $db->query("SELECT usuario_id, estado_verificacion FROM detalles_conductor WHERE usuario_id = 5");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if ($row) {
    echo "✅ Record exists: estado_verificacion = " . $row['estado_verificacion'] . "\n";
} else {
    echo "❌ Record still not found!\n";
}
?>
