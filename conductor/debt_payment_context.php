<?php
header('Content-Type: application/json');
$viaxOrigin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
$viaxAllowedOrigins = ['https://viaxcol.online', 'https://www.viaxcol.online'];
if ($viaxOrigin !== '' && in_array($viaxOrigin, $viaxAllowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $viaxOrigin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';
require_once '../utils/NotificationHelper.php';
require_once '../utils/SensitiveDataCrypto.php';

function hasColumn(PDO $db, string $table, string $column): bool
{
        $sql = "SELECT 1
                        FROM information_schema.columns
                        WHERE table_schema = 'public'
                            AND table_name = :table
                            AND column_name = :column
                        LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([
                ':table' => $table,
                ':column' => $column,
        ]);
        return (bool) $stmt->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

function getLastQuincenaDate(DateTime $now): DateTime {
    $day = intval($now->format('d'));
    if ($day > 15) {
        return new DateTime($now->format('Y-m-15'));
    }

    $lastMonth = (clone $now)->modify('first day of this month')->modify('-1 day');
    return new DateTime($lastMonth->format('Y-m-t'));
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
    $conductorId = isset($_GET['conductor_id']) ? intval($_GET['conductor_id']) : 0;
    if ($conductorId <= 0) {
        throw new Exception('ID de conductor inválido');
    }

    $database = new Database();
    $db = $database->getConnection();

    $stmtConductor = $db->prepare("SELECT id, empresa_id, nombre, apellido, email FROM usuarios WHERE id = :id AND tipo_usuario = 'conductor' LIMIT 1");
    $stmtConductor->execute([':id' => $conductorId]);
    $conductor = $stmtConductor->fetch(PDO::FETCH_ASSOC);

    if (!$conductor) {
        throw new Exception('Conductor no encontrado');
    }

    $empresaId = intval($conductor['empresa_id'] ?? 0);
    if ($empresaId <= 0) {
        throw new Exception('El conductor no está asociado a una empresa');
    }

    $completedStates = "'completada', 'completado', 'entregado', 'finalizada', 'finalizado'";
    $hasCompletedAt = hasColumn($db, 'solicitudes_servicio', 'completed_at');
    $tripDateExpr = $hasCompletedAt
        ? "COALESCE(s.completed_at, s.completado_en, s.solicitado_en, s.fecha_creacion)"
        : "COALESCE(s.completado_en, s.solicitado_en, s.fecha_creacion)";

    // Deuda por ciclo: viajes y pagos posteriores al último pago confirmado.
    $stmtAnchor = $db->prepare("SELECT MAX(confirmado_en) AS ultimo_pago_confirmado
        FROM pagos_comision_reportes
        WHERE conductor_id = :conductor_id
          AND estado = 'pagado_confirmado'");
    $stmtAnchor->execute([':conductor_id' => $conductorId]);
    $anchorData = $stmtAnchor->fetch(PDO::FETCH_ASSOC) ?: [];
    $anchorTs = $anchorData['ultimo_pago_confirmado'] ?? null;

    $queryComisionTotal = "SELECT 
        COALESCE(SUM(
            CASE 
                WHEN vrt.comision_plataforma_valor > 0 THEN vrt.comision_plataforma_valor
                WHEN vrt.comision_plataforma_porcentaje > 0 THEN 
                    COALESCE(NULLIF(vrt.precio_final_aplicado, 0), NULLIF(s.precio_final, 0), s.precio_estimado) 
                    * (vrt.comision_plataforma_porcentaje / 100)
                ELSE 0
            END
        ), 0) as comision_adeudada
    FROM solicitudes_servicio s
    INNER JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
    LEFT JOIN viaje_resumen_tracking vrt ON s.id = vrt.solicitud_id
    WHERE ac.conductor_id = :conductor_id
    AND LOWER(COALESCE(s.estado, '')) IN ($completedStates)" . ($anchorTs ? "
    AND $tripDateExpr > :anchor_ts" : "");

    $stmtTotal = $db->prepare($queryComisionTotal);
    $paramsTotal = [':conductor_id' => $conductorId];
    if ($anchorTs) {
        $paramsTotal[':anchor_ts'] = $anchorTs;
    }
    $stmtTotal->execute($paramsTotal);
    $totalComision = floatval($stmtTotal->fetch(PDO::FETCH_ASSOC)['comision_adeudada'] ?? 0);

    $queryPagado = "SELECT COALESCE(SUM(monto), 0) AS total_pagado
        FROM pagos_comision
        WHERE conductor_id = :conductor_id" . ($anchorTs ? "
        AND fecha_pago > :anchor_ts" : "");
    $stmtPagado = $db->prepare($queryPagado);
    $paramsPagado = [':conductor_id' => $conductorId];
    if ($anchorTs) {
        $paramsPagado[':anchor_ts'] = $anchorTs;
    }
    $stmtPagado->execute($paramsPagado);
    $totalPagado = floatval($stmtPagado->fetch(PDO::FETCH_ASSOC)['total_pagado'] ?? 0);

    $deudaActual = max(0, $totalComision - $totalPagado);

    $stmtConfig = $db->prepare("SELECT banco_codigo, banco_nombre, tipo_cuenta, numero_cuenta, titular_cuenta, documento_titular, referencia_transferencia
                                FROM empresas_configuracion WHERE empresa_id = :empresa_id LIMIT 1");
    $stmtConfig->execute([':empresa_id' => $empresaId]);
    $bankConfig = $stmtConfig->fetch(PDO::FETCH_ASSOC) ?: [];

    $numeroCuentaPlano = decryptSensitiveData($bankConfig['numero_cuenta'] ?? null);

    $hasTransferAccount = !empty($bankConfig['banco_nombre'])
        && !empty($bankConfig['tipo_cuenta'])
        && !empty($numeroCuentaPlano);

    $stmtEmpresa = $db->prepare("SELECT id, nombre, email FROM empresas_transporte WHERE id = :empresa_id LIMIT 1");
    $stmtEmpresa->execute([':empresa_id' => $empresaId]);
    $empresa = $stmtEmpresa->fetch(PDO::FETCH_ASSOC) ?: ['id' => $empresaId, 'nombre' => 'Empresa'];

    $stmtReport = $db->prepare("SELECT * FROM pagos_comision_reportes WHERE conductor_id = :conductor_id ORDER BY created_at DESC LIMIT 1");
    $stmtReport->execute([':conductor_id' => $conductorId]);
    $lastReport = $stmtReport->fetch(PDO::FETCH_ASSOC);

    $status = $lastReport['estado'] ?? 'sin_reporte';

    $now = new DateTime('now');
    $lastQuincena = getLastQuincenaDate($now);
    $deadline = (clone $lastQuincena)->modify('+15 days');
    $isOverdue = $now > $deadline;

    $isMandatory = $deudaActual > 0
        && $isOverdue
        && in_array($status, ['sin_reporte', 'rechazado'], true);

    $showAlert = $deudaActual > 0
        && in_array($status, ['sin_reporte', 'rechazado', 'pendiente_revision', 'comprobante_aprobado'], true);

    $half = intval($lastQuincena->format('d')) <= 15 ? 'Q1' : 'Q2';
    $periodoClave = $lastQuincena->format('Y-m') . '-' . $half;

    if ($deudaActual > 0 && $showAlert) {
        $stmtReminder = $db->prepare("SELECT 1 FROM conductor_alertas_deuda WHERE conductor_id = :conductor_id AND periodo_clave = :periodo AND tipo_alerta = 'recordatorio' LIMIT 1");
        $stmtReminder->execute([':conductor_id' => $conductorId, ':periodo' => $periodoClave]);

        if (!$stmtReminder->fetch()) {
            $stmtInsert = $db->prepare("INSERT INTO conductor_alertas_deuda (conductor_id, periodo_clave, tipo_alerta) VALUES (:conductor_id, :periodo, 'recordatorio')");
            $stmtInsert->execute([':conductor_id' => $conductorId, ':periodo' => $periodoClave]);

            NotificationHelper::crear(
                $conductorId,
                'debt_payment_reminder',
                'Recordatorio de pago de deuda',
                'Tienes una deuda de comisión pendiente. Puedes reportar el pago desde Comisiones > Pagar deuda.',
                'deuda_comision',
                null,
                ['empresa_id' => $empresaId, 'deuda' => $deudaActual]
            );
        }

        if ($isMandatory) {
            $stmtMandatory = $db->prepare("SELECT 1 FROM conductor_alertas_deuda WHERE conductor_id = :conductor_id AND periodo_clave = :periodo AND tipo_alerta = 'obligatoria' LIMIT 1");
            $stmtMandatory->execute([':conductor_id' => $conductorId, ':periodo' => $periodoClave]);

            if (!$stmtMandatory->fetch()) {
                $stmtInsertMandatory = $db->prepare("INSERT INTO conductor_alertas_deuda (conductor_id, periodo_clave, tipo_alerta) VALUES (:conductor_id, :periodo, 'obligatoria')");
                $stmtInsertMandatory->execute([':conductor_id' => $conductorId, ':periodo' => $periodoClave]);

                NotificationHelper::crear(
                    $conductorId,
                    'debt_payment_mandatory',
                    'Pago de deuda obligatorio',
                    'Tu deuda de comisión está vencida. Debes reportar el pago con comprobante para continuar el proceso.',
                    'deuda_comision',
                    null,
                    ['empresa_id' => $empresaId, 'deuda' => $deudaActual, 'obligatoria' => true]
                );
            }
        }
    }

    if ($lastReport) {
        $lastReport['comprobante_url'] = getR2ProxyUrl($lastReport['comprobante_ruta']);
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'deuda_actual' => round($deudaActual, 2),
            'total_pagado' => round($totalPagado, 2),
            'estado_reporte' => $status,
            'alerta' => [
                'mostrar' => $showAlert,
                'obligatoria' => $isMandatory,
                'quincena_referencia' => $lastQuincena->format('Y-m-d'),
                'fecha_limite_reporte' => $deadline->format('Y-m-d'),
            ],
            'empresa' => [
                'id' => intval($empresa['id'] ?? $empresaId),
                'nombre' => $empresa['nombre'] ?? 'Empresa',
            ],
            'cuenta_transferencia' => [
                'configurada' => $hasTransferAccount,
                'banco_codigo' => $bankConfig['banco_codigo'] ?? null,
                'banco_nombre' => $bankConfig['banco_nombre'] ?? null,
                'tipo_cuenta' => $bankConfig['tipo_cuenta'] ?? null,
                'numero_cuenta' => maskSensitiveAccount($numeroCuentaPlano),
                'numero_cuenta_masked' => maskSensitiveAccount($numeroCuentaPlano),
                'titular_cuenta' => $bankConfig['titular_cuenta'] ?? null,
                'documento_titular' => $bankConfig['documento_titular'] ?? null,
                'referencia_transferencia' => $bankConfig['referencia_transferencia'] ?? null,
                'resource' => 'empresa_bank',
                'resource_id' => $empresaId,
            ],
            'reporte_actual' => $lastReport,
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
