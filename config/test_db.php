<?php
require 'database.php';
try {
    $db = (new Database())->getConnection();
    
    echo "--- PAGOS COMISION (confirmados) ---\n";
    $stmt1 = $db->query("SELECT id, conductor_id, monto, fecha_pago FROM pagos_comision ORDER BY fecha_pago DESC LIMIT 5");
    print_r($stmt1->fetchAll(PDO::FETCH_ASSOC));

    echo "\n--- REPORTES DE PAGO (comprobantes) ---\n";
    $stmt2 = $db->query("SELECT id, conductor_id, monto_reportado, estado, created_at FROM pagos_comision_reportes ORDER BY created_at DESC LIMIT 5");
    print_r($stmt2->fetchAll(PDO::FETCH_ASSOC));

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
