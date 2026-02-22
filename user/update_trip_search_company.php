<?php
/**
 * Endpoint: Actualizar empresa objetivo durante búsqueda de conductor
 *
 * Permite cambiar dinámicamente la empresa de una solicitud pendiente
 * para mejorar asignación progresiva entre empresas.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';

try {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!$data) {
        throw new Exception('JSON inválido');
    }

    $solicitudId = isset($data['solicitud_id']) ? intval($data['solicitud_id']) : 0;
    $clienteId = isset($data['cliente_id']) ? intval($data['cliente_id']) : 0;
    $empresaId = array_key_exists('empresa_id', $data) && $data['empresa_id'] !== null
        ? intval($data['empresa_id'])
        : null;

    if ($solicitudId <= 0 || $clienteId <= 0) {
        throw new Exception('solicitud_id y cliente_id son requeridos');
    }

    $database = new Database();
    $db = $database->getConnection();

    $db->beginTransaction();

    $stmtSolicitud = $db->prepare("\n        SELECT id, cliente_id, estado\n        FROM solicitudes_servicio\n        WHERE id = ?\n        FOR UPDATE\n    ");
    $stmtSolicitud->execute([$solicitudId]);
    $solicitud = $stmtSolicitud->fetch(PDO::FETCH_ASSOC);

    if (!$solicitud) {
        throw new Exception('Solicitud no encontrada');
    }

    if (intval($solicitud['cliente_id']) !== $clienteId) {
        throw new Exception('No autorizado para modificar esta solicitud');
    }

    if ($solicitud['estado'] !== 'pendiente') {
        throw new Exception('Solo se puede cambiar empresa en solicitudes pendientes');
    }

    $empresaInfo = null;

    if ($empresaId !== null) {
        if ($empresaId <= 0) {
            throw new Exception('empresa_id inválido');
        }

        $stmtEmpresa = $db->prepare("\n            SELECT id, nombre, logo_url\n            FROM empresas_transporte\n            WHERE id = ?\n              AND estado = 'activo'\n              AND verificada = true\n        ");
        $stmtEmpresa->execute([$empresaId]);
        $empresaInfo = $stmtEmpresa->fetch(PDO::FETCH_ASSOC);

        if (!$empresaInfo) {
            throw new Exception('Empresa no válida para búsqueda');
        }
    }

    $stmtUpdate = $db->prepare("\n        UPDATE solicitudes_servicio\n        SET empresa_id = :empresa_id\n        WHERE id = :solicitud_id\n    ");
    $stmtUpdate->bindValue(':empresa_id', $empresaId, $empresaId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmtUpdate->bindValue(':solicitud_id', $solicitudId, PDO::PARAM_INT);
    $stmtUpdate->execute();

    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => $empresaId === null
            ? 'Búsqueda cambiada a modo libre competencia'
            : 'Empresa de búsqueda actualizada',
        'solicitud_id' => $solicitudId,
        'empresa' => $empresaInfo ? [
            'id' => intval($empresaInfo['id']),
            'nombre' => $empresaInfo['nombre'],
            'logo_url' => $empresaInfo['logo_url'],
        ] : null,
    ]);
} catch (Exception $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
