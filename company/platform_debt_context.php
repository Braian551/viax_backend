<?php
/**
 * API: Contexto de deuda de empresa con la plataforma
 * Endpoint: GET company/platform_debt_context.php
 * 
 * Retorna la deuda actual de la empresa con el administrador,
 * la configuración de cuenta bancaria del admin para transferencias,
 * y el estado del último reporte de pago.
 */

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';
require_once '../utils/SensitiveDataCrypto.php';

try {
    $empresaId = isset($_GET['empresa_id']) ? intval($_GET['empresa_id']) : 0;

    if ($empresaId <= 0) {
        throw new Exception('empresa_id es requerido');
    }

    $database = new Database();
    $db = $database->getConnection();
    $debtEpsilon = 1.0;

    // Datos de la empresa y su deuda
    $stmtEmpresa = $db->prepare("SELECT id, nombre, email, saldo_pendiente, comision_admin_porcentaje
                                 FROM empresas_transporte WHERE id = :id LIMIT 1");
    $stmtEmpresa->execute([':id' => $empresaId]);
    $empresa = $stmtEmpresa->fetch(PDO::FETCH_ASSOC);

    if (!$empresa) {
        throw new Exception('Empresa no encontrada');
    }

    $deudaActual = floatval($empresa['saldo_pendiente'] ?? 0);

    // Deuda dinámica de la empresa basada en deuda actual de sus conductores.
    $stmtDynamicDebt = $db->prepare("\n        SELECT COALESCE(SUM(GREATEST(COALESCE(comisiones.total_comision, 0) - COALESCE(pagos.total_pagado, 0), 0)), 0) AS deuda_dinamica\n        FROM usuarios u\n        LEFT JOIN (\n            SELECT\n                ac.conductor_id,\n                SUM(\n                    CASE\n                        WHEN vrt.comision_plataforma_valor > 0 THEN vrt.comision_plataforma_valor\n                        WHEN vrt.comision_plataforma_porcentaje > 0 THEN\n                            COALESCE(NULLIF(vrt.precio_final_aplicado, 0), NULLIF(s.precio_final, 0), s.precio_estimado)\n                            * (vrt.comision_plataforma_porcentaje / 100)\n                        ELSE 0\n                    END\n                ) AS total_comision\n            FROM solicitudes_servicio s\n            INNER JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id\n            LEFT JOIN viaje_resumen_tracking vrt ON s.id = vrt.solicitud_id\n            WHERE s.estado IN ('completada', 'entregado')\n            GROUP BY ac.conductor_id\n        ) comisiones ON comisiones.conductor_id = u.id\n        LEFT JOIN (\n            SELECT conductor_id, SUM(monto) AS total_pagado\n            FROM pagos_comision\n            GROUP BY conductor_id\n        ) pagos ON pagos.conductor_id = u.id\n        WHERE u.empresa_id = :empresa_id\n          AND u.tipo_usuario = 'conductor'\n    ");
    $stmtDynamicDebt->execute([':empresa_id' => $empresaId]);
    $deudaDinamica = floatval($stmtDynamicDebt->fetchColumn() ?: 0);
    if ($deudaDinamica > $deudaActual) {
        $deudaActual = $deudaDinamica;
    }
    if (abs($deudaActual) < $debtEpsilon) {
        $deudaActual = 0.0;
    }
    $deudaActiva = $deudaActual >= $debtEpsilon;

    // Total pagado históricamente
    $stmtTotalPagado = $db->prepare("SELECT COALESCE(SUM(monto), 0) FROM pagos_empresas WHERE empresa_id = :id AND tipo = 'pago'");
    $stmtTotalPagado->execute([':id' => $empresaId]);
    $totalPagado = floatval($stmtTotalPagado->fetchColumn());

    // Total cargado históricamente
    $stmtTotalCargos = $db->prepare("SELECT COALESCE(SUM(monto), 0) FROM pagos_empresas WHERE empresa_id = :id AND tipo = 'cargo'");
    $stmtTotalCargos->execute([':id' => $empresaId]);
    $totalCargos = floatval($stmtTotalCargos->fetchColumn());

    // Cuenta bancaria del administrador
    $stmtAdminBank = $db->prepare("SELECT id, banco_codigo, banco_nombre, tipo_cuenta, numero_cuenta,
                                          titular_cuenta, documento_titular, referencia_transferencia
                                   FROM admin_configuracion_banco LIMIT 1");
    $stmtAdminBank->execute();
    $adminBank = $stmtAdminBank->fetch(PDO::FETCH_ASSOC);

    $cuentaTransferencia = null;
    $numeroCuentaPlano = decryptSensitiveData($adminBank['numero_cuenta'] ?? null);

    if ($adminBank && !empty($adminBank['banco_nombre']) && !empty($numeroCuentaPlano)) {
        $tipoCuenta = strtolower(trim((string)($adminBank['tipo_cuenta'] ?? '')));
        $bancoNombre = strtolower(trim((string)($adminBank['banco_nombre'] ?? '')));
        $metodoRecaudo = ($tipoCuenta === 'nequi' || $bancoNombre === 'nequi')
            ? 'nequi'
            : 'cuenta_bancaria';

        $cuentaTransferencia = [
            'configurada' => true,
            'metodo_recaudo' => $metodoRecaudo,
            'banco_codigo' => $adminBank['banco_codigo'],
            'banco_nombre' => $adminBank['banco_nombre'],
            'tipo_cuenta' => $adminBank['tipo_cuenta'],
            'numero_cuenta' => maskSensitiveAccount($numeroCuentaPlano),
            'numero_cuenta_masked' => maskSensitiveAccount($numeroCuentaPlano),
            'titular_cuenta' => $adminBank['titular_cuenta'],
            'documento_titular' => $adminBank['documento_titular'],
            'referencia_transferencia' => $adminBank['referencia_transferencia'],
            'resource' => 'admin_bank',
            'resource_id' => intval($adminBank['id'] ?? 0),
        ];
    } else {
        $cuentaTransferencia = [
            'configurada' => false,
            'metodo_recaudo' => 'cuenta_bancaria',
        ];
    }

    // Último reporte de pago
    $stmtLastReport = $db->prepare("SELECT id, estado, monto_reportado, created_at, comprobante_ruta, motivo_rechazo
                                    FROM pagos_empresa_reportes
                                    WHERE empresa_id = :id
                                    ORDER BY created_at DESC LIMIT 1");
    $stmtLastReport->execute([':id' => $empresaId]);
    $lastReport = $stmtLastReport->fetch(PDO::FETCH_ASSOC);

    $reporteActual = null;
    $estadoReporte = 'sin_reporte';
    if ($lastReport) {
        $estadoReporte = $lastReport['estado'];
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $comprobanteUrl = "$protocol://$host/r2_proxy.php?key=" . urlencode($lastReport['comprobante_ruta']);

        $reporteActual = [
            'id' => intval($lastReport['id']),
            'estado' => $lastReport['estado'],
            'monto_reportado' => floatval($lastReport['monto_reportado']),
            'created_at' => $lastReport['created_at'],
            'comprobante_url' => $comprobanteUrl,
            'motivo_rechazo' => $lastReport['motivo_rechazo'],
        ];
    }

    // Alertas quincenales
    $hoy = new DateTime();
    $dia = intval($hoy->format('d'));
    $periodoRef = $dia <= 15
        ? $hoy->format('Y-m') . '-Q1'
        : $hoy->format('Y-m') . '-Q2';

    $mostrarAlerta = $deudaActiva;
    $alertaObligatoria = false;

    if ($mostrarAlerta) {
        // Verificar si ya reportó en este periodo
        $stmtCheck = $db->prepare("SELECT id FROM pagos_empresa_reportes
                                   WHERE empresa_id = :id AND estado <> 'rechazado'
                                   AND created_at >= :inicio
                                   ORDER BY created_at DESC LIMIT 1");

        $inicioQuincena = $dia <= 15
            ? $hoy->format('Y-m-01')
            : $hoy->format('Y-m-16');

        $stmtCheck->execute([':id' => $empresaId, ':inicio' => $inicioQuincena]);
        $hasReportThisPeriod = $stmtCheck->fetch() !== false;

        if ($hasReportThisPeriod) {
            $mostrarAlerta = false;
        }

        // Si la deuda es grande y no ha reportado, es obligatoria
        if ($mostrarAlerta && !$hasReportThisPeriod && $deudaActual > 100000) {
            $alertaObligatoria = true;
        }
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'deuda_actual' => $deudaActual,
            'deuda_activa' => $deudaActiva,
            'total_pagado' => $totalPagado,
            'total_cargos' => $totalCargos,
            'comision_porcentaje' => floatval($empresa['comision_admin_porcentaje'] ?? 0),
            'estado_reporte' => $estadoReporte,
            'alerta' => [
                'mostrar' => $mostrarAlerta,
                'obligatoria' => $alertaObligatoria,
                'quincena_referencia' => $inicioQuincena ?? null,
            ],
            'empresa' => [
                'id' => intval($empresa['id']),
                'nombre' => $empresa['nombre'],
            ],
            'cuenta_transferencia' => $cuentaTransferencia,
            'reporte_actual' => $reporteActual,
        ],
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
