<?php
require_once __DIR__ . '/../config/database.php';

$db = (new Database())->getConnection();

$stmt = $db->prepare("SELECT id, nombre, email, tipo_usuario, empresa_id, es_activo, es_verificado FROM usuarios WHERE id = 277");
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);

echo "User 277 details:\n";
foreach ($user as $key => $value) {
    echo "  $key: " . ($value ?? 'NULL') . "\n";
}
?>
