<?php
require_once __DIR__ . '/../config/database.php';

$db = (new Database())->getConnection();

echo "=== FIXING TIPO_USUARIO FOR USER 277 ===\n";
echo "Before: ";
echo $db->query("SELECT tipo_usuario FROM usuarios WHERE id = 277")->fetchColumn() . "\n";

$db->exec("UPDATE usuarios SET tipo_usuario = 'conductor' WHERE id = 277");

echo "After: ";
echo $db->query("SELECT tipo_usuario FROM usuarios WHERE id = 277")->fetchColumn() . "\n";

echo "DONE! User 277's tipo_usuario is now 'conductor'\n";
?>
