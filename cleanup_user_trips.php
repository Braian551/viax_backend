<?php
require_once 'config/database.php';
$database = new Database();
$conn = $database->getConnection();

$emails = ['secretoestoico8052@gmail.com', 'tracongamescorreos@gmail.com'];

echo "--- Iniciando proceso de finalización de viajes ---\n";

foreach ($emails as $email) {
    try {
        $stmt = $conn->prepare("SELECT id, tipo_usuario FROM usuarios WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            echo "Usuario no encontrado: $email\n";
            continue;
        }

        $userId = $user['id'];
        $role = $user['tipo_usuario'];

        echo "Procesando $email (ID: $userId, Rol: $role)...\n";

        // Actualizar viajes en curso
        $activeStatuses = ['aceptada', 'en_camino', 'conductor_llego', 'recogido', 'en_curso'];
        $statusList = "'" . implode("','", $activeStatuses) . "'";

        if ($role === 'conductor') {
            $stmtUpdate = $conn->prepare("UPDATE solicitudes_servicio SET estado = 'completada' WHERE estado IN ($statusList) AND id IN (SELECT solicitud_id FROM asignaciones_conductor WHERE conductor_id = ? AND estado IN ('asignado', 'llegado', 'en_curso'))");
        } else {
            $stmtUpdate = $conn->prepare("UPDATE solicitudes_servicio SET estado = 'completada' WHERE cliente_id = ? AND estado IN ($statusList)");
        }

        $stmtUpdate->execute([$userId]);
        $affected = $stmtUpdate->rowCount();

        echo "Hecho. Viajes finalizados: $affected\n";

    } catch (Exception $e) {
        echo "Error con $email: " . $e->getMessage() . "\n";
    }
}

echo "--- Proceso terminado ---\n";
?>
