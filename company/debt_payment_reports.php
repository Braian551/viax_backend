<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';
require_once '../utils/NotificationHelper.php';
require_once '../utils/Mailer.php';

function notifyCompanyUsers(
    PDO $db,
    int $empresaId,
    ?int $excludeUserId,
    string $title,
    string $message,
    string $referenceType,
    ?int $referenceId = null,
    array $data = []
): void {
    try {
        $query = "SELECT id FROM usuarios WHERE empresa_id = :empresa_id AND tipo_usuario = 'empresa'";
        $params = [':empresa_id' => $empresaId];

        if ($excludeUserId !== null && $excludeUserId > 0) {
            $query .= " AND id <> :exclude_user_id";
            $params[':exclude_user_id'] = $excludeUserId;
        }

        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $companyUsers = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        foreach ($companyUsers as $userId) {
            NotificationHelper::crear(
                intval($userId),
                'system',
                $title,
                $message,
                $referenceType,
                $referenceId,
                $data
            );
        }
    } catch (Throwable $e) {
        error_log('Error enviando notificación interna de empresa: ' . $e->getMessage());
    }
}

function getR2ProxyUrl(string $key): string {
    if (str_starts_with($key, 'http://') || str_starts_with($key, 'https://')) {
        return $key;
    }
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return "$protocol://$host/r2_proxy.php?key=" . urlencode($key);
}

try {
    $database = new Database();
    $db = $database->getConnection();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $empresaId = isset($_GET['empresa_id']) ? intval($_GET['empresa_id']) : 0;
        if ($empresaId <= 0) {
            throw new Exception('empresa_id es requerido');
        }

        $conductorId = isset($_GET['conductor_id']) ? intval($_GET['conductor_id']) : 0;
        $estado = trim($_GET['estado'] ?? '');
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 25;
        if ($limit <= 0) {
            $limit = 25;
        }
        if ($limit > 100) {
            $limit = 100;
        }

        $where = ["r.empresa_id = :empresa_id"];
        $params = [':empresa_id' => $empresaId];

        if ($conductorId > 0) {
            $where[] = "r.conductor_id = :conductor_id";
            $params[':conductor_id'] = $conductorId;
        }

        if ($estado !== '') {
            $where[] = "r.estado = :estado";
            $params[':estado'] = $estado;
        }

        $query = "SELECT r.*, u.nombre, u.apellido, u.email
                  FROM pagos_comision_reportes r
                  INNER JOIN usuarios u ON u.id = r.conductor_id
                  WHERE " . implode(' AND ', $where) . "
                  ORDER BY r.created_at DESC
                  LIMIT :limit";

        $stmt = $db->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rows = array_map(function($row) {
            $row['comprobante_url'] = getR2ProxyUrl($row['comprobante_ruta']);
            return $row;
        }, $rows);

        echo json_encode([
            'success' => true,
            'data' => $rows,
        ]);
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método no permitido');
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $action = trim($input['action'] ?? '');
    $reportId = intval($input['reporte_id'] ?? 0);
    $empresaId = intval($input['empresa_id'] ?? 0);
    $actorUserId = intval($input['actor_user_id'] ?? 0);

    if ($reportId <= 0 || $empresaId <= 0 || $action === '') {
        throw new Exception('Datos incompletos');
    }

    $stmtReport = $db->prepare("SELECT * FROM pagos_comision_reportes WHERE id = :id AND empresa_id = :empresa_id LIMIT 1");
    $stmtReport->execute([':id' => $reportId, ':empresa_id' => $empresaId]);
    $report = $stmtReport->fetch(PDO::FETCH_ASSOC);

    if (!$report) {
        throw new Exception('Reporte no encontrado');
    }

    $conductorId = intval($report['conductor_id']);

    $stmtConductor = $db->prepare("SELECT nombre, apellido, email FROM usuarios WHERE id = :id LIMIT 1");
    $stmtConductor->execute([':id' => $conductorId]);
    $conductor = $stmtConductor->fetch(PDO::FETCH_ASSOC) ?: ['nombre' => 'Conductor'];

    if ($action === 'approve') {
        if ($report['estado'] !== 'pendiente_revision') {
            throw new Exception('Solo puedes aprobar reportes pendientes de revisión');
        }

        $stmt = $db->prepare("UPDATE pagos_comision_reportes
                              SET estado = 'comprobante_aprobado', aprobado_por = :actor, aprobado_en = NOW(), updated_at = NOW()
                              WHERE id = :id");
        $stmt->execute([':actor' => $actorUserId ?: null, ':id' => $reportId]);

        NotificationHelper::crear(
            $conductorId,
            'debt_payment_approved',
            'Comprobante aprobado',
            'Tu comprobante fue aprobado. La empresa revisará la confirmación final del pago.',
            'pago_comision_reporte',
            $reportId,
            ['reporte_id' => $reportId]
        );

        if (!empty($conductor['email'])) {
            Mailer::sendEmail(
                $conductor['email'],
                trim(($conductor['nombre'] ?? '') . ' ' . ($conductor['apellido'] ?? '')),
                'Comprobante de deuda aprobado',
                'Tu comprobante de pago de comisión fue aprobado por la empresa. Queda pendiente la confirmación final del pago.'
            );
        }

        notifyCompanyUsers(
            $db,
            $empresaId,
            $actorUserId > 0 ? $actorUserId : null,
            'Comprobante aprobado',
            'Se aprobó el comprobante de deuda de ' . trim(($conductor['nombre'] ?? '') . ' ' . ($conductor['apellido'] ?? '')) . '.',
            'pago_comision_reporte',
            $reportId,
            ['accion' => 'approve', 'reporte_id' => $reportId, 'conductor_id' => $conductorId]
        );

        echo json_encode(['success' => true, 'message' => 'Comprobante aprobado']);
        exit();
    }

    if ($action === 'reject') {
        if (!in_array($report['estado'], ['pendiente_revision', 'comprobante_aprobado'], true)) {
            throw new Exception('No puedes rechazar este reporte en su estado actual');
        }

        $motivo = trim($input['motivo'] ?? '');
        if ($motivo === '') {
            throw new Exception('Debes indicar el motivo de rechazo');
        }

        $stmt = $db->prepare("UPDATE pagos_comision_reportes
                              SET estado = 'rechazado', motivo_rechazo = :motivo,
                                  rechazado_por = :actor, rechazado_en = NOW(), updated_at = NOW()
                              WHERE id = :id");
        $stmt->execute([
            ':motivo' => $motivo,
            ':actor' => $actorUserId ?: null,
            ':id' => $reportId,
        ]);

        NotificationHelper::crear(
            $conductorId,
            'debt_payment_rejected',
            'Comprobante rechazado',
            'La empresa rechazó tu comprobante. Revisa el motivo y vuelve a reportar el pago.',
            'pago_comision_reporte',
            $reportId,
            ['reporte_id' => $reportId, 'motivo' => $motivo]
        );

        if (!empty($conductor['email'])) {
            Mailer::sendEmail(
                $conductor['email'],
                trim(($conductor['nombre'] ?? '') . ' ' . ($conductor['apellido'] ?? '')),
                'Comprobante de deuda rechazado',
                'Tu comprobante de pago fue rechazado. Motivo: ' . $motivo
            );
        }

        notifyCompanyUsers(
            $db,
            $empresaId,
            $actorUserId > 0 ? $actorUserId : null,
            'Comprobante rechazado',
            'Se rechazó el comprobante de deuda de ' . trim(($conductor['nombre'] ?? '') . ' ' . ($conductor['apellido'] ?? '')) . '.',
            'pago_comision_reporte',
            $reportId,
            ['accion' => 'reject', 'reporte_id' => $reportId, 'conductor_id' => $conductorId]
        );

        echo json_encode(['success' => true, 'message' => 'Comprobante rechazado']);
        exit();
    }

    if ($action === 'confirm_payment') {
        if ($report['estado'] !== 'comprobante_aprobado') {
            throw new Exception('Solo puedes confirmar reportes aprobados');
        }

        $db->beginTransaction();

        $stmtPago = $db->prepare("INSERT INTO pagos_comision
            (conductor_id, monto, metodo_pago, admin_id, notas, fecha_pago)
            VALUES (:conductor_id, :monto, 'transferencia', :admin_id, :notas, NOW())
            RETURNING id");

        $stmtPago->execute([
            ':conductor_id' => $conductorId,
            ':monto' => $report['monto_reportado'],
            ':admin_id' => $actorUserId ?: null,
            ':notas' => 'Pago confirmado desde comprobante #' . $reportId,
        ]);

        $pagoId = intval($stmtPago->fetchColumn());

        $stmtEmpresa = $db->prepare("SELECT id, nombre, comision_admin_porcentaje, saldo_pendiente
                                     FROM empresas_transporte
                                     WHERE id = :id
                                     LIMIT 1
                                     FOR UPDATE");
        $stmtEmpresa->execute([':id' => $empresaId]);
        $empresa = $stmtEmpresa->fetch(PDO::FETCH_ASSOC);

        if ($empresa) {
            $porcentajeAdmin = floatval($empresa['comision_admin_porcentaje'] ?? 0);
            if ($porcentajeAdmin > 0) {
                $montoReportado = floatval($report['monto_reportado'] ?? 0);
                $cargoAdmin = $montoReportado * ($porcentajeAdmin / 100);

                if ($cargoAdmin > 0) {
                    $saldoAnterior = floatval($empresa['saldo_pendiente'] ?? 0);
                    $saldoNuevo = $saldoAnterior + $cargoAdmin;

                    $stmtUpdateSaldo = $db->prepare("UPDATE empresas_transporte
                                                     SET saldo_pendiente = :saldo_nuevo,
                                                         actualizado_en = NOW()
                                                     WHERE id = :id");
                    $stmtUpdateSaldo->execute([
                        ':saldo_nuevo' => $saldoNuevo,
                        ':id' => $empresaId,
                    ]);

                    $descripcionCargo = sprintf(
                        'Comisión sobre recaudo conductor #%d (%.2f%%) - comprobante #%d',
                        $conductorId,
                        $porcentajeAdmin,
                        $reportId
                    );

                    $stmtCargo = $db->prepare("INSERT INTO pagos_empresas
                        (empresa_id, monto, tipo, descripcion, saldo_anterior, saldo_nuevo, creado_en)
                        VALUES (:empresa_id, :monto, 'cargo', :descripcion, :saldo_anterior, :saldo_nuevo, NOW())");
                    $stmtCargo->execute([
                        ':empresa_id' => $empresaId,
                        ':monto' => $cargoAdmin,
                        ':descripcion' => $descripcionCargo,
                        ':saldo_anterior' => $saldoAnterior,
                        ':saldo_nuevo' => $saldoNuevo,
                    ]);
                }
            }
        }

        $stmtUpdate = $db->prepare("UPDATE pagos_comision_reportes
                                    SET estado = 'pagado_confirmado',
                                        confirmado_por = :actor,
                                        confirmado_en = NOW(),
                                        pago_comision_id = :pago_id,
                                        updated_at = NOW()
                                    WHERE id = :id");

        $stmtUpdate->execute([
            ':actor' => $actorUserId ?: null,
            ':pago_id' => $pagoId,
            ':id' => $reportId,
        ]);

        $db->commit();

        NotificationHelper::crear(
            $conductorId,
            'debt_payment_confirmed',
            'Deuda pagada confirmada',
            'La empresa confirmó tu pago de deuda de comisión.',
            'pago',
            $pagoId,
            ['reporte_id' => $reportId, 'pago_id' => $pagoId]
        );

        if (!empty($conductor['email'])) {
            Mailer::sendEmail(
                $conductor['email'],
                trim(($conductor['nombre'] ?? '') . ' ' . ($conductor['apellido'] ?? '')),
                'Pago de deuda confirmado',
                'Tu pago de deuda de comisión fue confirmado por la empresa. Gracias por mantener tu cuenta al día.'
            );
        }

        notifyCompanyUsers(
            $db,
            $empresaId,
            $actorUserId > 0 ? $actorUserId : null,
            'Pago de deuda confirmado',
            'Se confirmó el pago de deuda de ' . trim(($conductor['nombre'] ?? '') . ' ' . ($conductor['apellido'] ?? '')) . '.',
            'pago',
            $pagoId,
            ['accion' => 'confirm_payment', 'reporte_id' => $reportId, 'pago_id' => $pagoId, 'conductor_id' => $conductorId]
        );

        echo json_encode([
            'success' => true,
            'message' => 'Pago confirmado correctamente',
            'data' => ['pago_id' => $pagoId],
        ]);
        exit();
    }

    throw new Exception('Acción no soportada');
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
