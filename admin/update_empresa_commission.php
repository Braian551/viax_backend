<?php
/**
 * API: Actualizar Comisión de Admin para Empresa
 * Endpoint: admin/update_empresa_commission.php
 * 
 * Permite al admin definir qué porcentaje de la comisión de cada
 * empresa se queda la plataforma.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    
    if (!$data) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Datos inválidos']);
        exit;
    }
    
    // Validar campos requeridos
    if (!isset($data['empresa_id']) || !isset($data['comision_admin_porcentaje'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'message' => 'Se requiere empresa_id y comision_admin_porcentaje'
        ]);
        exit;
    }
    
    $empresaId = intval($data['empresa_id']);
    $comision = floatval($data['comision_admin_porcentaje']);
    
    // Validar rango de comisión (0-100%)
    if ($comision < 0 || $comision > 100) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'message' => 'La comisión debe estar entre 0 y 100%'
        ]);
        exit;
    }
    
    // Verificar que la empresa existe
    $checkQuery = "SELECT id, nombre, comision_admin_porcentaje FROM empresas_transporte WHERE id = ?";
    $checkStmt = $conn->prepare($checkQuery);
    $checkStmt->execute([$empresaId]);
    $empresa = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$empresa) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Empresa no encontrada']);
        exit;
    }
    
    $comisionAnterior = $empresa['comision_admin_porcentaje'];
    
    // Actualizar comisión
    $updateQuery = "UPDATE empresas_transporte 
                    SET comision_admin_porcentaje = ?,
                        actualizado_en = CURRENT_TIMESTAMP
                    WHERE id = ?";
    $updateStmt = $conn->prepare($updateQuery);
    $updateStmt->execute([$comision, $empresaId]);
    
    // Registrar en log de auditoría si existe
    try {
        $logQuery = "INSERT INTO logs_auditoria 
                    (usuario_id, accion, entidad, entidad_id, descripcion, fecha_creacion)
                    VALUES (NULL, 'update_commission', 'empresas_transporte', ?, ?, NOW())";
        $logStmt = $conn->prepare($logQuery);
        $logStmt->execute([
            $empresaId,
            "Comisión admin actualizada de {$comisionAnterior}% a {$comision}% para empresa: {$empresa['nombre']}"
        ]);
    } catch (Exception $e) {
        // Log de auditoría no crítico
        error_log("Error en log auditoría: " . $e->getMessage());
    }
    
    // Enviar correo de notificación
    try {
        // Obtener datos adicionales para email (incluir logo_url)
        $emailQuery = "SELECT nombre, email, representante_nombre, representante_email, logo_url FROM empresas_transporte WHERE id = ?";
        $emailStmt = $conn->prepare($emailQuery);
        $emailStmt->execute([$empresaId]);
        $empresaEmail = $emailStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($empresaEmail) {
            $empresaEmail['nombre_empresa'] = $empresaEmail['nombre'];
            $toEmail = $empresaEmail['representante_email'] ?: $empresaEmail['email'];
            $toName = $empresaEmail['representante_nombre'];
            
            require_once __DIR__ . '/../utils/Mailer.php';
            Mailer::sendCompanyCommissionChangedEmail($toEmail, $toName, $empresaEmail, $comisionAnterior, $comision);
        }
    } catch (Exception $e) {
        error_log("Error enviando email de comisión: " . $e->getMessage());
    }
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Comisión actualizada correctamente',
        'data' => [
            'empresa_id' => $empresaId,
            'empresa_nombre' => $empresa['nombre'],
            'comision_anterior' => $comisionAnterior,
            'comision_nueva' => $comision
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
