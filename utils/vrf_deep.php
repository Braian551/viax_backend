<?php
require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    echo "=== BUSQUEDA POR NOMBRE 'Oscar Alejandro' ===\n";
    $stmt = $db->prepare("SELECT id, email, nombre, apellido, empresa_id FROM usuarios WHERE nombre LIKE ?");
    $stmt->execute(['%Oscar Alejandro%']);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($users as $user) {
        $id = $user['id'];
        echo "ID: $id | Email: {$user['email']} | Nombre: {$user['nombre']} {$user['apellido']} | Empresa_ID: {$user['empresa_id']}\n";
        
        $stmt2 = $db->prepare("SELECT id, estado_verificacion, estado_aprobacion, aprobado FROM detalles_conductor WHERE usuario_id = ?");
        $stmt2->execute([$id]);
        $details = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        foreach ($details as $d) {
             echo "   -> Detalles ID: {$d['id']} | Ver: {$d['estado_verificacion']} | Apr: {$d['estado_aprobacion']} | Aprobado: {$d['aprobado']}\n";
        }

        $stmt3 = $db->prepare("SELECT id, estado, empresa_id FROM solicitudes_vinculacion_conductor WHERE conductor_id = ?");
        $stmt3->execute([$id]);
        $sols = $stmt3->fetchAll(PDO::FETCH_ASSOC);
        foreach ($sols as $s) {
            echo "   -> Solicitud ID: {$s['id']} | Estado: {$s['estado']} | Empresa: {$s['empresa_id']}\n";
        }
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>
