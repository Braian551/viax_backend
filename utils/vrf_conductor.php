<?php
require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    $id = 277;
    $stmt = $db->prepare("SELECT id, email, nombre, apellido, es_verificado, es_activo FROM usuarios WHERE id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        echo "USER 277:\n";
        echo "ID: {$user['id']} | Email: {$user['email']} | Name: {$user['nombre']} {$user['apellido']} | Verificado: {$user['es_verificado']} | Activo: {$user['es_activo']}\n";
        
        $stmt2 = $db->prepare("SELECT * FROM detalles_conductor WHERE usuario_id = ?");
        $stmt2->execute([$id]);
        $details = $stmt2->fetch(PDO::FETCH_ASSOC);
        if ($details) {
            echo "DETALLES CONDUCTOR:\n";
            print_r($details);
        } else {
            echo "Sin registro en detalles_conductor\n";
        }

        $stmt3 = $db->prepare("SELECT * FROM solicitudes_vinculacion_conductor WHERE conductor_id = ?");
        $stmt3->execute([$id]);
        $solicitudes = $stmt3->fetchAll(PDO::FETCH_ASSOC);
        if ($solicitudes) {
            echo "SOLICITUDES:\n";
            print_r($solicitudes);
        } else {
            echo "Sin solicitudes\n";
        }
    } else {
        echo "Usuario 277 no encontrado\n";
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>
