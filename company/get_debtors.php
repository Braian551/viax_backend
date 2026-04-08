<?php
/**
 * API: Obtener Deudores de Empresa
 * Endpoint: company/get_debtors.php
 * 
 * Devuelve una lista de conductores de la empresa con su estado financiero
 * (comisión total calculada, pagada y deuda actual).
 */

require_once '../config/database.php';

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

header('Content-Type: application/json');
$viaxOrigin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
$viaxAllowedOrigins = ['https://viaxcol.online', 'https://www.viaxcol.online'];
if ($viaxOrigin !== '' && in_array($viaxOrigin, $viaxAllowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $viaxOrigin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    if (empty($_GET['empresa_id'])) {
        throw new Exception('ID de empresa requerido');
    }
    
    $empresaId = $_GET['empresa_id'];
    
    $database = new Database();
    $db = $database->getConnection();
    $completedStates = "'completada', 'completado', 'entregado', 'finalizada', 'finalizado'";
    $hasCompletedAt = hasColumn($db, 'solicitudes_servicio', 'completed_at');
    $tripDateExpr = $hasCompletedAt
        ? "COALESCE(s.completed_at, s.completado_en, s.solicitado_en, s.fecha_creacion)"
        : "COALESCE(s.completado_en, s.solicitado_en, s.fecha_creacion)";
    
    // Obtener conductores de la empresa y calcular deuda por ciclo vigente.
    // Ciclo vigente = transacciones posteriores al último pago confirmado.
    $stmtConductores = $db->prepare("SELECT 
            u.id,
            u.empresa_id,
            u.nombre,
            u.apellido,
            u.email,
            u.telefono,
            u.foto_perfil
        FROM usuarios u
        WHERE u.empresa_id = :empresa_id
          AND u.tipo_usuario = 'conductor'");
    $stmtConductores->bindParam(':empresa_id', $empresaId);
    $stmtConductores->execute();
    $conductores = $stmtConductores->fetchAll(PDO::FETCH_ASSOC);

    $stmtAnchor = $db->prepare("SELECT MAX(confirmado_en) AS ultimo_pago_confirmado
        FROM pagos_comision_reportes
        WHERE conductor_id = :conductor_id
          AND estado = 'pagado_confirmado'");

    $stmtComisionSinAnchor = $db->prepare("SELECT COALESCE(SUM(
            CASE
                WHEN vrt.comision_plataforma_valor > 0 THEN vrt.comision_plataforma_valor
                WHEN vrt.comision_plataforma_porcentaje > 0 THEN
                    COALESCE(NULLIF(vrt.precio_final_aplicado, 0), NULLIF(s.precio_final, 0), s.precio_estimado)
                    * (vrt.comision_plataforma_porcentaje / 100)
                ELSE 0
            END
        ), 0) AS total_comision
        FROM solicitudes_servicio s
        INNER JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
        LEFT JOIN viaje_resumen_tracking vrt ON s.id = vrt.solicitud_id
        WHERE ac.conductor_id = :conductor_id
                    AND LOWER(COALESCE(s.estado, '')) IN ($completedStates)");

    $stmtComisionConAnchor = $db->prepare("SELECT COALESCE(SUM(
            CASE
                WHEN vrt.comision_plataforma_valor > 0 THEN vrt.comision_plataforma_valor
                WHEN vrt.comision_plataforma_porcentaje > 0 THEN
                    COALESCE(NULLIF(vrt.precio_final_aplicado, 0), NULLIF(s.precio_final, 0), s.precio_estimado)
                    * (vrt.comision_plataforma_porcentaje / 100)
                ELSE 0
            END
        ), 0) AS total_comision
        FROM solicitudes_servicio s
        INNER JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
        LEFT JOIN viaje_resumen_tracking vrt ON s.id = vrt.solicitud_id
        WHERE ac.conductor_id = :conductor_id
                    AND LOWER(COALESCE(s.estado, '')) IN ($completedStates)
                    AND $tripDateExpr > :anchor_ts");

    $stmtPagosSinAnchor = $db->prepare("SELECT COALESCE(SUM(monto), 0) AS total_pagado
        FROM pagos_comision
        WHERE conductor_id = :conductor_id");

    $stmtPagosConAnchor = $db->prepare("SELECT COALESCE(SUM(monto), 0) AS total_pagado
        FROM pagos_comision
        WHERE conductor_id = :conductor_id
          AND fecha_pago > :anchor_ts");
    
    // Calcular deuda real en PHP
    $deudores = [];
    $totalDeulaGlobal = 0;
    
    foreach ($conductores as $c) {
        $conductorId = intval($c['id'] ?? 0);

        $stmtAnchor->execute([':conductor_id' => $conductorId]);
        $anchorData = $stmtAnchor->fetch(PDO::FETCH_ASSOC) ?: [];
        $anchorTs = $anchorData['ultimo_pago_confirmado'] ?? null;

        if ($anchorTs) {
            $stmtComisionConAnchor->execute([
                ':conductor_id' => $conductorId,
                ':anchor_ts' => $anchorTs,
            ]);
            $totalComision = floatval($stmtComisionConAnchor->fetch(PDO::FETCH_ASSOC)['total_comision'] ?? 0);

            $stmtPagosConAnchor->execute([
                ':conductor_id' => $conductorId,
                ':anchor_ts' => $anchorTs,
            ]);
            $totalPagado = floatval($stmtPagosConAnchor->fetch(PDO::FETCH_ASSOC)['total_pagado'] ?? 0);
        } else {
            $stmtComisionSinAnchor->execute([':conductor_id' => $conductorId]);
            $totalComision = floatval($stmtComisionSinAnchor->fetch(PDO::FETCH_ASSOC)['total_comision'] ?? 0);

            $stmtPagosSinAnchor->execute([':conductor_id' => $conductorId]);
            $totalPagado = floatval($stmtPagosSinAnchor->fetch(PDO::FETCH_ASSOC)['total_pagado'] ?? 0);
        }

        $c['total_comision'] = round($totalComision, 2);
        $c['total_pagado'] = round($totalPagado, 2);
        $deuda = $totalComision - $totalPagado;
        
        // Formatear para respuesta
        $c['deuda_actual'] = $deuda > 0 ? $deuda : 0;
        $c['saldo_a_favor'] = $deuda < 0 ? abs($deuda) : 0; // Si pagó de más (raro pero posible)
        
        if ($deuda > 0) {
            $totalDeulaGlobal += $deuda;
        }
        
        $deudores[] = $c;
    }
    
    usort($deudores, function($a, $b) {
        $deudaA = floatval($a['deuda_actual'] ?? 0);
        $deudaB = floatval($b['deuda_actual'] ?? 0);
        return $deudaB <=> $deudaA;
    });

    echo json_encode([
        'success' => true,
        'data' => $deudores,
        'resumen' => [
            'total_conductores' => count($deudores),
            'deuda_total_empresa' => round($totalDeulaGlobal, 2)
        ]
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
