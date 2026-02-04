<?php
/**
 * API: Obtener Balance de Empresa
 * Endpoint: company/get_balance.php
 * 
 * Devuelve el saldo pendiente de la empresa con la plataforma
 * y el porcentaje de comisión que paga al admin.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    if (!isset($_GET['empresa_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Se requiere empresa_id']);
        exit;
    }
    
    $empresaId = intval($_GET['empresa_id']);
    
    // Obtener datos de la empresa
    $query = "SELECT 
                id,
                nombre,
                comision_admin_porcentaje,
                saldo_pendiente,
                total_viajes_completados
              FROM empresas_transporte 
              WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->execute([$empresaId]);
    $empresa = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$empresa) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Empresa no encontrada']);
        exit;
    }
    
    // Obtener últimos movimientos
    $movQuery = "SELECT tipo, monto, descripcion, creado_en
                 FROM pagos_empresas
                 WHERE empresa_id = ?
                 ORDER BY creado_en DESC
                 LIMIT 10";
    $movStmt = $conn->prepare($movQuery);
    $movStmt->execute([$empresaId]);
    $movimientos = $movStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular resumen
    $resumenQuery = "SELECT 
                       SUM(CASE WHEN tipo = 'cargo' THEN monto ELSE 0 END) as total_cargos,
                       SUM(CASE WHEN tipo = 'pago' THEN monto ELSE 0 END) as total_pagos
                     FROM pagos_empresas
                     WHERE empresa_id = ?";
    $resumenStmt = $conn->prepare($resumenQuery);
    $resumenStmt->execute([$empresaId]);
    $resumen = $resumenStmt->fetch(PDO::FETCH_ASSOC);
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'data' => [
            'empresa' => [
                'id' => $empresa['id'],
                'nombre' => $empresa['nombre'],
                'comision_admin_porcentaje' => floatval($empresa['comision_admin_porcentaje']),
                'saldo_pendiente' => floatval($empresa['saldo_pendiente']),
                'total_viajes' => intval($empresa['total_viajes_completados'])
            ],
            'resumen' => [
                'total_cargos' => floatval($resumen['total_cargos'] ?? 0),
                'total_pagos' => floatval($resumen['total_pagos'] ?? 0)
            ],
            'ultimos_movimientos' => $movimientos
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error del servidor: ' . $e->getMessage()
    ]);
}
?>
