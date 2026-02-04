<?php
/**
 * API: Registrar pago de comisión
 * Endpoint: admin/registrar_pago_comision.php
 * 
 * Permite al administrador registrar un pago de deuda de comisión
 * de un conductor.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $conductor_id = $input['conductor_id'] ?? null;
    $monto = $input['monto'] ?? null;
    $admin_id = $input['admin_id'] ?? null; // Opcional
    $notas = $input['notas'] ?? null;
    $metodo_pago = $input['metodo_pago'] ?? 'efectivo';
    
    if (!$conductor_id || !$monto || $monto <= 0) {
        throw new Exception('ID de conductor y monto positivo son requeridos');
    }

    $database = new Database();
    $db = $database->getConnection();
    
    // Verificar que el conductor existe
    $stmtVerify = $db->prepare("SELECT id FROM usuarios WHERE id = ?");
    $stmtVerify->execute([$conductor_id]);
    if (!$stmtVerify->fetch()) {
        throw new Exception('Conductor no encontrado');
    }

    // Insertar el pago
    $query = "INSERT INTO pagos_comision 
              (conductor_id, monto, metodo_pago, admin_id, notas, fecha_pago) 
              VALUES (:conductor_id, :monto, :metodo_pago, :admin_id, :notas, NOW())";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':conductor_id', $conductor_id);
    $stmt->bindParam(':monto', $monto);
    $stmt->bindParam(':metodo_pago', $metodo_pago);
    $stmt->bindParam(':admin_id', $admin_id);
    $stmt->bindParam(':notas', $notas);
    
    if ($stmt->execute()) {
        $id_pago = $db->lastInsertId();

        // ---------------------------------------------------------
        // LOGICA DE COMISIÓN EMPRESA - ADMIN (Actualización Solicitada)
        // ---------------------------------------------------------
        // Cuando la empresa "recibe" el dinero del conductor (registra pago),
        // se debe calcular la comisión que la empresa le debe al admin.
        
        // 1. Obtener empresa del conductor
        $stmtEmpresa = $db->prepare("SELECT empresa_id FROM usuarios WHERE id = ?");
        $stmtEmpresa->execute([$conductor_id]);
        $conductorData = $stmtEmpresa->fetch(PDO::FETCH_ASSOC);
        
        if ($conductorData && !empty($conductorData['empresa_id'])) {
            $empresa_id = $conductorData['empresa_id'];
            
            // 2. Obtener porcentaje de comisión del admin para esa empresa
            $stmtConfig = $db->prepare("SELECT comision_admin_porcentaje, saldo_pendiente FROM empresas_transporte WHERE id = ?");
            $stmtConfig->execute([$empresa_id]);
            $configEmpresa = $stmtConfig->fetch(PDO::FETCH_ASSOC);
            
            if ($configEmpresa) {
                $porcentaje = floatval($configEmpresa['comision_admin_porcentaje']);
                
                // Si hay una comisión configurada (> 0), aplicarla
                if ($porcentaje > 0) {
                    $comision_admin_valor = $monto * ($porcentaje / 100);
                    
                    // 3. Actualizar saldo de la empresa (AUMENTA su deuda con el admin)
                    $nuevo_saldo = floatval($configEmpresa['saldo_pendiente']) + $comision_admin_valor;
                    
                    $stmtUpdateSaldo = $db->prepare("UPDATE empresas_transporte SET saldo_pendiente = :nuevo_saldo, actualizado_en = NOW() WHERE id = :id");
                    $stmtUpdateSaldo->execute([':nuevo_saldo' => $nuevo_saldo, ':id' => $empresa_id]);
                    
                    // 4. Registrar el cargo en el historial de la empresa
                    $stmtCargo = $db->prepare("
                        INSERT INTO pagos_empresas (empresa_id, monto, tipo, descripcion, saldo_anterior, saldo_nuevo, creado_en)
                        VALUES (:empresa_id, :monto, 'cargo', :descripcion, :saldo_anterior, :saldo_nuevo, NOW())
                    ");
                    
                    $descripcion = "Comisión sobre recaudo conductor #$conductor_id ($porcentaje%)";
                    
                    $stmtCargo->execute([
                        ':empresa_id' => $empresa_id,
                        ':monto' => $comision_admin_valor, // El monto del cargo es la comisión calculada
                        ':descripcion' => $descripcion,
                        ':saldo_anterior' => $configEmpresa['saldo_pendiente'],
                        ':saldo_nuevo' => $nuevo_saldo
                    ]);
                }
            }
        }

        echo json_encode([
            'success' => true,
            'message' => 'Pago registrado correctamente',
            'id_pago' => $id_pago,
            'monto' => $monto
        ]);
    } else {
        throw new Exception('Error al registrar el pago en la base de datos');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
