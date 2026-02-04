<?php
require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $conductor_id = 278; // ID de Leidy Andrea
    
    echo "--- Verificando columnas ---\n";
    $stmt = $db->prepare("SELECT column_name FROM information_schema.columns WHERE table_name = 'detalles_conductor'");
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    print_r($columns);

    // Determinar nombre correcto de columna de razón de rechazo
    $razon_col = in_array('razon_rechazo', $columns) ? 'razon_rechazo' : 
                (in_array('motivo_rechazo', $columns) ? 'motivo_rechazo' : null);
                
    // Determinar nombre correcto de columna de estado
    $estado_col = in_array('estado_aprobacion', $columns) ? 'estado_aprobacion' : 
                 (in_array('estado_verificacion', $columns) ? 'estado_verificacion' : 'estado');

    echo "Usando columna de estado: $estado_col\n";
    echo "Usando columna de razón: " . ($razon_col ?: "Ninguna") . "\n";

    // 1. Actualizar detalles_conductor
    $sql1 = "UPDATE detalles_conductor SET $estado_col = 'pendiente'";
    if ($razon_col) {
        $sql1 .= ", $razon_col = NULL";
    }
    // Asegurar que estado_verificacion también sea pendiente si existe
    if (in_array('estado_verificacion', $columns) && $estado_col !== 'estado_verificacion') {
        $sql1 .= ", estado_verificacion = 'pendiente'";
    }
    $sql1 .= " WHERE usuario_id = :id";
    
    $stmt1 = $db->prepare($sql1);
    $stmt1->execute([':id' => $conductor_id]);
    echo "detalles_conductor actualizado: " . $stmt1->rowCount() . " filas\n";
    
    // 2. Actualizar solicitudes_vinculacion_conductor
    $stmt2 = $db->prepare("
        UPDATE solicitudes_vinculacion_conductor 
        SET estado = 'pendiente', 
            motivo_rechazo = NULL,
            actualizado_en = NOW()
        WHERE conductor_id = :id
    ");
    $stmt2->execute([':id' => $conductor_id]);
    echo "solicitudes_vinculacion_conductor actualizado: " . $stmt2->rowCount() . " filas\n";
    
    // 3. Resetear usuarios
    $stmt3 = $db->prepare("UPDATE usuarios SET es_verificado = 0 WHERE id = :id");
    $stmt3->execute([':id' => $conductor_id]);
    echo "usuarios actualizado: " . $stmt3->rowCount() . " filas\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>
