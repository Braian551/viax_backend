<?php
/**
 * API: Registrar pago de empresa a la plataforma
 * Endpoint: admin/registrar_pago_empresa.php
 * 
 * Permite registrar cuando una empresa paga su deuda con la plataforma.
 * Reduce el saldo_pendiente de la empresa.
 */

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

require_once __DIR__ . '/../config/database.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $empresa_id = isset($input['empresa_id']) ? intval($input['empresa_id']) : 0;
    $monto = isset($input['monto']) ? floatval($input['monto']) : 0;
    $admin_id = isset($input['admin_id']) ? intval($input['admin_id']) : null;
    $notas = isset($input['notas']) ? trim($input['notas']) : null;
    $metodo_pago = isset($input['metodo_pago']) ? trim($input['metodo_pago']) : 'transferencia';
    
    if ($empresa_id <= 0) {
        throw new Exception('ID de empresa es requerido');
    }
    
    if ($monto <= 0) {
        throw new Exception('El monto debe ser mayor a cero');
    }

    $database = new Database();
    $db = $database->getConnection();
    
    $db->beginTransaction();

    // Obtener saldo actual de la empresa
    $stmt = $db->prepare("SELECT id, nombre, saldo_pendiente FROM empresas_transporte WHERE id = :id FOR UPDATE");
    $stmt->execute([':id' => $empresa_id]);
    $empresa = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$empresa) {
        throw new Exception('Empresa no encontrada');
    }

    $saldo_actual = floatval($empresa['saldo_pendiente']);
    $nuevo_saldo = max(0, $saldo_actual - $monto); // No permitir saldo negativo

    // Actualizar saldo pendiente
    $stmt = $db->prepare("UPDATE empresas_transporte SET saldo_pendiente = :nuevo_saldo, actualizado_en = NOW() WHERE id = :id");
    $stmt->execute([':nuevo_saldo' => $nuevo_saldo, ':id' => $empresa_id]);

    // Registrar el pago en pagos_empresas
    $descripcion = "Pago recibido" . ($metodo_pago ? " ($metodo_pago)" : "") . ($notas ? " - $notas" : "");
    
    $stmt = $db->prepare("
        INSERT INTO pagos_empresas (empresa_id, monto, tipo, descripcion, saldo_anterior, saldo_nuevo, creado_en)
        VALUES (:empresa_id, :monto, 'pago', :descripcion, :saldo_anterior, :saldo_nuevo, NOW())
    ");
    $stmt->execute([
        ':empresa_id' => $empresa_id,
        ':monto' => $monto,
        ':descripcion' => $descripcion,
        ':saldo_anterior' => $saldo_actual,
        ':saldo_nuevo' => $nuevo_saldo
    ]);
    
    $pago_id = $db->lastInsertId();
    
    $db->commit();

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Pago registrado correctamente',
        'data' => [
            'pago_id' => intval($pago_id),
            'empresa_id' => $empresa_id,
            'empresa_nombre' => $empresa['nombre'],
            'monto_pagado' => $monto,
            'saldo_anterior' => $saldo_actual,
            'saldo_nuevo' => $nuevo_saldo
        ]
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
