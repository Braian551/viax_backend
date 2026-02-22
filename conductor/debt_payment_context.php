<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';
require_once '../utils/NotificationHelper.php';

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

    $queryComisionTotal = "SELECT 
        COALESCE(SUM(
            CASE 
                WHEN vrt.comision_plataforma_valor > 0 THEN vrt.comision_plataforma_valor
                WHEN vrt.comision_plataforma_porcentaje > 0 THEN 
                    COALESCE(NULLIF(vrt.precio_final_aplicado, 0), NULLIF(s.precio_final, 0), s.precio_estimado) 
                    * (vrt.comision_plataforma_porcentaje / 100)
                WHEN cp.comision_plataforma IS NOT NULL THEN 
                    COALESCE(NULLIF(vrt.precio_final_aplicado, 0), NULLIF(s.precio_final, 0), s.precio_estimado) 
                    * (cp.comision_plataforma / 100)
                ELSE COALESCE(NULLIF(vrt.precio_final_aplicado, 0), NULLIF(s.precio_final, 0), s.precio_estimado) * 0.10
            END
        ), 0) as comision_adeudada
    FROM solicitudes_servicio s
    INNER JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
    LEFT JOIN viaje_resumen_tracking vrt ON s.id = vrt.solicitud_id
    LEFT JOIN configuracion_precios cp ON s.empresa_id = cp.empresa_id 
        AND s.tipo_vehiculo = cp.tipo_vehiculo AND cp.activo = 1
    WHERE ac.conductor_id = :conductor_id
    AND s.estado IN ('completada', 'entregado')";

    $stmtTotal = $db->prepare($queryComisionTotal);
    $stmtTotal->execute([':conductor_id' => $conductorId]);
    $totalComision = floatval($stmtTotal->fetch(PDO::FETCH_ASSOC)['comision_adeudada'] ?? 0);

    $stmtPagado = $db->prepare("SELECT COALESCE(SUM(monto), 0) AS total_pagado FROM pagos_comision WHERE conductor_id = :conductor_id");
    $stmtPagado->execute([':conductor_id' => $conductorId]);
    $totalPagado = floatval($stmtPagado->fetch(PDO::FETCH_ASSOC)['total_pagado'] ?? 0);

    $deudaActual = max(0, $totalComision - $totalPagado);

    $stmtConfig = $db->prepare("SELECT banco_codigo, banco_nombre, tipo_cuenta, numero_cuenta, titular_cuenta, documento_titular, referencia_transferencia
                                FROM empresas_configuracion WHERE empresa_id = :empresa_id LIMIT 1");
    $stmtConfig->execute([':empresa_id' => $empresaId]);
    $bankConfig = $stmtConfig->fetch(PDO::FETCH_ASSOC) ?: [];

    $hasTransferAccount = !empty($bankConfig['banco_nombre'])
        && !empty($bankConfig['tipo_cuenta'])
        && !empty($bankConfig['numero_cuenta']);

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
                'numero_cuenta' => $bankConfig['numero_cuenta'] ?? null,
                'titular_cuenta' => $bankConfig['titular_cuenta'] ?? null,
                'documento_titular' => $bankConfig['documento_titular'] ?? null,
                'referencia_transferencia' => $bankConfig['referencia_transferencia'] ?? null,
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
