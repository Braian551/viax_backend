<?php
require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    $stmt = $db->prepare("SELECT * FROM usuarios WHERE id = 6");
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'user_6' => $user,
        'all_conductores' => $db->query("SELECT id, nombre, tipo_usuario FROM usuarios WHERE tipo_usuario LIKE '%onductor%'")->fetchAll(PDO::FETCH_ASSOC)
    ]);

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
