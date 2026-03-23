<?php
/**
 * API: Gestionar reportes de pago de empresas (Admin)
 * Endpoint: GET/POST admin/empresa_payment_reports.php
 * 
 * GET: Lista los comprobantes de pago enviados por empresas
 * POST: Aprobar, rechazar o confirmar comprobantes
 * 
 * Flujo de estados:
 *   pendiente_revision → comprobante_aprobado → pagado_confirmado
 *   pendiente_revision → rechazado
 *   comprobante_aprobado → rechazado
 * 
 * Al confirmar pago se genera factura PDF y se envía por email.
 */

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/NotificationHelper.php';
require_once __DIR__ . '/../utils/Mailer.php';
require_once __DIR__ . '/../utils/SensitiveDataCrypto.php';

/**
 * Genera la URL pública del proxy R2 para una ruta relativa.
 */
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

    // ─── GET: Listar reportes de empresa ───
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $empresaId = isset($_GET['empresa_id']) ? intval($_GET['empresa_id']) : 0;
        $estado = trim($_GET['estado'] ?? '');
        $limit = min(max(intval($_GET['limit'] ?? 25), 1), 100);

        $where = [];
        $params = [];

        if ($empresaId > 0) {
            $where[] = "r.empresa_id = :empresa_id";
            $params[':empresa_id'] = $empresaId;
        }

        if ($estado !== '') {
            $where[] = "r.estado = :estado";
            $params[':estado'] = $estado;
        }

        $whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

        $query = "SELECT r.*, et.nombre AS empresa_nombre, et.email AS empresa_email, et.logo_url
                  FROM pagos_empresa_reportes r
                  INNER JOIN empresas_transporte et ON et.id = r.empresa_id
                  $whereClause
                  ORDER BY r.created_at DESC
                  LIMIT :limit";

        $stmt = $db->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rows = array_map(function ($row) {
            $row['comprobante_url'] = getR2ProxyUrl($row['comprobante_ruta']);
            $numeroPlano = decryptSensitiveData($row['numero_cuenta_destino'] ?? null);
            $row['numero_cuenta_destino_masked'] = maskSensitiveAccount($numeroPlano);
            $row['numero_cuenta_destino'] = $row['numero_cuenta_destino_masked'];
            return $row;
        }, $rows);

        // Resumen de estados
        $stmtResumen = $db->query("SELECT
            COUNT(*) FILTER (WHERE estado = 'pendiente_revision') AS pendientes,
            COUNT(*) FILTER (WHERE estado = 'comprobante_aprobado') AS aprobados,
            COUNT(*) FILTER (WHERE estado = 'pagado_confirmado') AS confirmados,
            COUNT(*) FILTER (WHERE estado = 'rechazado') AS rechazados,
            COALESCE(SUM(monto_reportado) FILTER (WHERE estado = 'pendiente_revision'), 0) AS monto_pendiente,
            COALESCE(SUM(monto_reportado) FILTER (WHERE estado = 'pagado_confirmado'), 0) AS monto_confirmado
            FROM pagos_empresa_reportes");
        $resumen = $stmtResumen->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => $rows,
            'resumen' => $resumen,
        ]);
        exit();
    }

    // ─── POST: Gestionar reportes ───
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método no permitido');
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $action = trim($input['action'] ?? '');
    $reportId = intval($input['reporte_id'] ?? 0);
    $actorUserId = intval($input['actor_user_id'] ?? 0);

    if ($reportId <= 0 || $action === '') {
        throw new Exception('Datos incompletos: reporte_id y action son requeridos');
    }

    // Obtener reporte
    $stmtReport = $db->prepare("SELECT * FROM pagos_empresa_reportes WHERE id = :id LIMIT 1");
    $stmtReport->execute([':id' => $reportId]);
    $report = $stmtReport->fetch(PDO::FETCH_ASSOC);

    if (!$report) {
        throw new Exception('Reporte no encontrado');
    }

    $empresaId = intval($report['empresa_id']);

    // Obtener datos de la empresa
    $stmtEmpresa = $db->prepare("SELECT id, nombre, email, representante_email FROM empresas_transporte WHERE id = :id LIMIT 1");
    $stmtEmpresa->execute([':id' => $empresaId]);
    $empresa = $stmtEmpresa->fetch(PDO::FETCH_ASSOC) ?: ['nombre' => 'Empresa'];

    // ─── APROBAR ───
    if ($action === 'approve') {
        if ($report['estado'] !== 'pendiente_revision') {
            throw new Exception('Solo se pueden aprobar reportes pendientes de revisión');
        }

        $stmt = $db->prepare("UPDATE pagos_empresa_reportes
                              SET estado = 'comprobante_aprobado', aprobado_por = :actor, aprobado_en = NOW(), updated_at = NOW()
                              WHERE id = :id");
        $stmt->execute([':actor' => $actorUserId ?: null, ':id' => $reportId]);

        // Notificar a usuarios de la empresa
        $stmtEmpresaUsers = $db->prepare("SELECT id FROM usuarios WHERE empresa_id = :empresa_id AND tipo_usuario = 'empresa'");
        $stmtEmpresaUsers->execute([':empresa_id' => $empresaId]);
        foreach ($stmtEmpresaUsers->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            NotificationHelper::crear(
                intval($uid),
                'empresa_payment_approved',
                'Comprobante aprobado',
                'Tu comprobante de pago a la plataforma fue aprobado. Queda pendiente la confirmación final.',
                'pago_empresa_reporte',
                $reportId,
                ['reporte_id' => $reportId]
            );
        }

        // Email a la empresa
        $emailDest = $empresa['representante_email'] ?: ($empresa['email'] ?? '');
        if (!empty($emailDest)) {
            Mailer::sendEmail(
                $emailDest,
                $empresa['nombre'] ?? 'Empresa',
                'Comprobante de pago aprobado - Viax',
                'Tu comprobante de pago a la plataforma Viax fue aprobado. Se procederá con la confirmación final del pago.'
            );
        }

        echo json_encode(['success' => true, 'message' => 'Comprobante aprobado']);
        exit();
    }

    // ─── RECHAZAR ───
    if ($action === 'reject') {
        if (!in_array($report['estado'], ['pendiente_revision', 'comprobante_aprobado'], true)) {
            throw new Exception('No se puede rechazar este reporte en su estado actual');
        }

        $motivo = trim($input['motivo'] ?? '');
        if ($motivo === '') {
            throw new Exception('Debes indicar el motivo de rechazo');
        }

        $stmt = $db->prepare("UPDATE pagos_empresa_reportes
                              SET estado = 'rechazado', motivo_rechazo = :motivo,
                                  rechazado_por = :actor, rechazado_en = NOW(), updated_at = NOW()
                              WHERE id = :id");
        $stmt->execute([
            ':motivo' => $motivo,
            ':actor' => $actorUserId ?: null,
            ':id' => $reportId,
        ]);

        // Notificar a la empresa
        $stmtEmpresaUsers = $db->prepare("SELECT id FROM usuarios WHERE empresa_id = :empresa_id AND tipo_usuario = 'empresa'");
        $stmtEmpresaUsers->execute([':empresa_id' => $empresaId]);
        foreach ($stmtEmpresaUsers->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            NotificationHelper::crear(
                intval($uid),
                'empresa_payment_rejected',
                'Comprobante rechazado',
                'Tu comprobante fue rechazado. Motivo: ' . $motivo,
                'pago_empresa_reporte',
                $reportId,
                ['reporte_id' => $reportId, 'motivo' => $motivo]
            );
        }

        $emailDest = $empresa['representante_email'] ?: ($empresa['email'] ?? '');
        if (!empty($emailDest)) {
            Mailer::sendEmail(
                $emailDest,
                $empresa['nombre'] ?? 'Empresa',
                'Comprobante de pago rechazado - Viax',
                'Tu comprobante de pago fue rechazado. Motivo: ' . $motivo . '. Por favor, verifica y envía un nuevo comprobante.'
            );
        }

        echo json_encode(['success' => true, 'message' => 'Comprobante rechazado']);
        exit();
    }

    // ─── CONFIRMAR PAGO ───
    if ($action === 'confirm_payment') {
        if ($report['estado'] !== 'comprobante_aprobado') {
            throw new Exception('Solo se pueden confirmar reportes aprobados');
        }

        $db->beginTransaction();

        $montoReportado = floatval($report['monto_reportado']);

        // Obtener saldo actual de la empresa
        $stmtSaldo = $db->prepare("SELECT saldo_pendiente FROM empresas_transporte WHERE id = :id FOR UPDATE");
        $stmtSaldo->execute([':id' => $empresaId]);
        $saldoActual = floatval($stmtSaldo->fetchColumn());

        $nuevoSaldo = max(0, $saldoActual - $montoReportado);
        if (abs($nuevoSaldo) < 1.0) {
            $nuevoSaldo = 0.0;
        }

        // Actualizar saldo
        $stmtUpdate = $db->prepare("UPDATE empresas_transporte SET saldo_pendiente = :saldo, actualizado_en = NOW() WHERE id = :id");
        $stmtUpdate->execute([':saldo' => $nuevoSaldo, ':id' => $empresaId]);

        // Registrar en pagos_empresas
        $descripcion = sprintf('Pago recibido (comprobante #%d) - transferencia', $reportId);
        $stmtPago = $db->prepare("INSERT INTO pagos_empresas
            (empresa_id, monto, tipo, descripcion, saldo_anterior, saldo_nuevo, creado_en)
            VALUES (:empresa_id, :monto, 'pago', :descripcion, :saldo_anterior, :saldo_nuevo, NOW())
            RETURNING id");
        $stmtPago->execute([
            ':empresa_id' => $empresaId,
            ':monto' => $montoReportado,
            ':descripcion' => $descripcion,
            ':saldo_anterior' => $saldoActual,
            ':saldo_nuevo' => $nuevoSaldo,
        ]);
        $pagoId = intval($stmtPago->fetchColumn());

        // Actualizar el reporte
        $stmtConfirm = $db->prepare("UPDATE pagos_empresa_reportes
                                     SET estado = 'pagado_confirmado',
                                         confirmado_por = :actor,
                                         confirmado_en = NOW(),
                                         pago_empresa_id = :pago_id,
                                         updated_at = NOW()
                                     WHERE id = :id");
        $stmtConfirm->execute([
            ':actor' => $actorUserId ?: null,
            ':pago_id' => $pagoId,
            ':id' => $reportId,
        ]);

        // Generar factura
        $stmtFacturaNum = $db->query("SELECT COALESCE(MAX(id), 0) + 1 FROM facturas");
        $nextId = intval($stmtFacturaNum->fetchColumn());
        $numeroFactura = 'VIAX-EA-' . str_pad($nextId, 6, '0', STR_PAD_LEFT);

                // Datos del admin (emisor): prioriza el emisor principal configurado por negocio.
                $stmtAdmin = $db->prepare("SELECT
                                                        u.id,
                                                        u.nombre,
                                                        u.apellido,
                                                        u.email,
                                                        aef.nombre_legal,
                                                        aef.tipo_documento,
                                                        aef.numero_documento,
                                                        aef.regimen_fiscal,
                                                        aef.direccion_fiscal,
                                                        aef.ciudad,
                                                        aef.departamento,
                                                        aef.pais,
                                                        aef.telefono,
                                                        COALESCE(aef.email_emisor, u.email) AS emisor_email
                                             FROM usuarios u
                                             LEFT JOIN admin_emisor_fiscal aef ON aef.admin_id = u.id
                                             WHERE u.tipo_usuario IN ('admin', 'administrador')
                                             ORDER BY
                                                 (LOWER(COALESCE(aef.email_emisor, u.email)) = LOWER('braianoquen@gmail.com')) DESC,
                                                 (LOWER(u.email) = LOWER('braianoquen@gmail.com')) DESC,
                                                 u.id ASC
                                             LIMIT 1");
        $stmtAdmin->execute();
        $adminData = $stmtAdmin->fetch(PDO::FETCH_ASSOC) ?: [];

        // Datos de empresa para factura
        $stmtEmpresaFull = $db->prepare("SELECT et.id, et.nombre, et.nit, et.email, et.representante_email,
                                                et.comision_admin_porcentaje
                                         FROM empresas_transporte et WHERE et.id = :id LIMIT 1");
        $stmtEmpresaFull->execute([':id' => $empresaId]);
        $empresaFull = $stmtEmpresaFull->fetch(PDO::FETCH_ASSOC) ?: [];

        $stmtFactura = $db->prepare("INSERT INTO facturas
            (numero_factura, tipo, emisor_id, emisor_tipo, emisor_nombre, emisor_documento, emisor_email,
             receptor_id, receptor_tipo, receptor_nombre, receptor_documento, receptor_email,
             subtotal, porcentaje_comision, valor_comision, total, moneda,
             pago_referencia_id, pago_referencia_tipo, reporte_id,
             concepto, fecha_emision, fecha_pago, estado, creado_por)
            VALUES
            (:numero, 'empresa_admin', :emisor_id, 'admin', :emisor_nombre, :emisor_documento, :emisor_email,
             :receptor_id, 'empresa', :receptor_nombre, :receptor_doc, :receptor_email,
             :subtotal, :pct_comision, :val_comision, :total, 'COP',
             :pago_ref_id, 'pago_empresa_plataforma', :reporte_id,
             :concepto, NOW(), NOW(), 'pagada', :creado_por)
            RETURNING id");

        $comisionPct = floatval($empresaFull['comision_admin_porcentaje'] ?? 0);
        $valorComision = round($montoReportado, 2);
        $subtotalBase = $comisionPct > 0
            ? round($valorComision / ($comisionPct / 100), 2)
            : $valorComision;
        $concepto = sprintf('Comisión administrativa del periodo - %s', $empresaFull['nombre'] ?? 'Empresa');

        $platformLegalName = 'VIAX TECHONOLOGY S.A.S';

        $stmtFactura->execute([
            ':numero' => $numeroFactura,
            ':emisor_id' => intval($adminData['id'] ?? 0),
            ':emisor_nombre' => $platformLegalName,
            ':emisor_documento' => $adminData['numero_documento'] ?? '',
            ':emisor_email' => $adminData['emisor_email'] ?? ($adminData['email'] ?? 'braianoquen@gmail.com'),
            ':receptor_id' => $empresaId,
            ':receptor_nombre' => $empresaFull['nombre'] ?? 'Empresa',
            ':receptor_doc' => $empresaFull['nit'] ?? '',
            ':receptor_email' => $empresaFull['representante_email'] ?: ($empresaFull['email'] ?? ''),
            ':subtotal' => $subtotalBase,
            ':pct_comision' => $comisionPct,
            ':val_comision' => $valorComision,
            ':total' => $valorComision,
            ':pago_ref_id' => $pagoId,
            ':reporte_id' => $reportId,
            ':concepto' => $concepto,
            ':creado_por' => $actorUserId ?: null,
        ]);

        $facturaId = intval($stmtFactura->fetchColumn());

        $db->commit();

        // Notificar a la empresa
        $stmtEmpresaUsers = $db->prepare("SELECT id FROM usuarios WHERE empresa_id = :empresa_id AND tipo_usuario = 'empresa'");
        $stmtEmpresaUsers->execute([':empresa_id' => $empresaId]);
        foreach ($stmtEmpresaUsers->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            NotificationHelper::crear(
                intval($uid),
                'empresa_payment_confirmed',
                'Pago confirmado',
                'Tu pago de $' . number_format($montoReportado, 0, ',', '.') . ' COP fue confirmado. Factura: ' . $numeroFactura,
                'factura',
                $facturaId,
                ['reporte_id' => $reportId, 'pago_id' => $pagoId, 'factura_id' => $facturaId, 'numero_factura' => $numeroFactura]
            );
        }

        // Notificar a administradores sobre confirmación y factura generada
        $stmtAdmins = $db->prepare("SELECT id FROM usuarios WHERE tipo_usuario IN ('admin', 'administrador')");
        $stmtAdmins->execute();
        foreach ($stmtAdmins->fetchAll(PDO::FETCH_COLUMN) as $adminUid) {
            NotificationHelper::crear(
                intval($adminUid),
                'invoice_generated',
                'Factura generada por pago empresarial',
                'Se confirmó el pago de ' . ($empresaFull['nombre'] ?? 'Empresa') . ' por $' . number_format($montoReportado, 0, ',', '.') . ' COP. Factura: ' . $numeroFactura,
                'factura',
                $facturaId,
                [
                    'reporte_id' => $reportId,
                    'pago_id' => $pagoId,
                    'factura_id' => $facturaId,
                    'numero_factura' => $numeroFactura,
                    'empresa_id' => $empresaId,
                ]
            );
        }

        // Generar y enviar factura PDF por email
        try {
            require_once __DIR__ . '/generate_invoice_pdf.php';
            $pdfResult = generateInvoicePdf($db, $facturaId);

            if ($pdfResult && !empty($pdfResult['pdf_path'])) {
                // Actualizar ruta PDF en la factura
                $stmtPdfUpdate = $db->prepare("UPDATE facturas SET pdf_ruta = :ruta WHERE id = :id");
                $stmtPdfUpdate->execute([':ruta' => $pdfResult['pdf_path'], ':id' => $facturaId]);

                // Descargar PDF desde R2 a archivo temporal para adjuntar al email
                $pdfAttachments = [];
                try {
                    require_once __DIR__ . '/../config/R2Service.php';
                    $r2 = new R2Service();
                    $fileData = $r2->getFile($pdfResult['pdf_path']);
                    if ($fileData && !empty($fileData['content'])) {
                        $tempPdf = tempnam(sys_get_temp_dir(), 'factura_');
                        $ext = str_contains($pdfResult['pdf_path'], '.pdf') ? '.pdf' : '.html';
                        $tempPdfPath = $tempPdf . $ext;
                        rename($tempPdf, $tempPdfPath);
                        file_put_contents($tempPdfPath, $fileData['content']);
                        $pdfAttachments[] = [
                            'path' => $tempPdfPath,
                            'name' => $numeroFactura . $ext,
                        ];
                    }
                } catch (Throwable $attErr) {
                    error_log('Error descargando PDF para adjuntar: ' . $attErr->getMessage());
                }

                // Enviar email con factura adjunta a la empresa
                $emailDest = $empresaFull['representante_email'] ?: ($empresaFull['email'] ?? '');
                if (!empty($emailDest)) {
                    Mailer::sendEmail(
                        $emailDest,
                        $empresaFull['nombre'] ?? 'Empresa',
                        'Factura de pago confirmada - ' . $numeroFactura,
                        'Tu pago de $' . number_format($montoReportado, 0, ',', '.') . ' COP fue confirmado exitosamente. ' .
                        'Factura N° ' . $numeroFactura . '. ' .
                        'Saldo anterior: $' . number_format($saldoActual, 0, ',', '.') . ' COP. ' .
                        'Nuevo saldo: $' . number_format($nuevoSaldo, 0, ',', '.') . ' COP. ' .
                        'Adjuntamos tu factura para tus registros.',
                        $pdfAttachments
                    );
                }

                // Notificar también al admin por email
                $adminEmail = $adminData['email'] ?? '';
                if (!empty($adminEmail)) {
                    Mailer::sendEmail(
                        $adminEmail,
                        trim(($adminData['nombre'] ?? 'Admin') . ' ' . ($adminData['apellido'] ?? '')),
                        'Pago confirmado - ' . ($empresaFull['nombre'] ?? 'Empresa') . ' - ' . $numeroFactura,
                        'Se confirmó el pago de ' . ($empresaFull['nombre'] ?? 'Empresa') . ' por $' . number_format($montoReportado, 0, ',', '.') . ' COP. ' .
                        'Factura N° ' . $numeroFactura . ' generada exitosamente.',
                        $pdfAttachments
                    );
                }

                // Limpiar archivos temporales
                foreach ($pdfAttachments as $att) {
                    if (isset($att['path']) && file_exists($att['path'])) {
                        @unlink($att['path']);
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('Error generando factura PDF: ' . $e->getMessage());
        }

        echo json_encode([
            'success' => true,
            'message' => 'Pago confirmado y factura generada',
            'data' => [
                'pago_id' => $pagoId,
                'factura_id' => $facturaId,
                'numero_factura' => $numeroFactura,
                'saldo_anterior' => $saldoActual,
                'saldo_nuevo' => $nuevoSaldo,
            ],
        ]);
        exit();
    }

    throw new Exception('Acción no reconocida: ' . $action);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
