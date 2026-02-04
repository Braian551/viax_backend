<?php
/**
 * API: Dashboard Stats para Empresas
 * 
 * Retorna estadísticas en tiempo real del dashboard de la empresa:
 * - Viajes de hoy
 * - Total de conductores activos
 * - Ganancias (comisiones de la empresa)
 * - Estadísticas adicionales
 */

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    $empresa_id = isset($_GET['empresa_id']) ? intval($_GET['empresa_id']) : 0;
    $periodo = isset($_GET['periodo']) ? $_GET['periodo'] : 'hoy'; // hoy, semana, mes, anio

    if ($empresa_id <= 0) {
        throw new Exception('ID de empresa inválido');
    }

    // Verificar que la empresa existe
    $stmt = $db->prepare("SELECT id, nombre, comision_admin_porcentaje FROM empresas_transporte WHERE id = :id");
    $stmt->bindParam(':id', $empresa_id, PDO::PARAM_INT);
    $stmt->execute();
    $empresa = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$empresa) {
        throw new Exception('Empresa no encontrada');
    }

    $comision_empresa = floatval($empresa['comision_admin_porcentaje'] ?? 10);

    // Determinar rango de fechas según período
    $fecha_inicio = date('Y-m-d');
    $fecha_fin = date('Y-m-d');
    
    switch ($periodo) {
        case 'semana':
            $fecha_inicio = date('Y-m-d', strtotime('-7 days'));
            break;
        case 'mes':
            $fecha_inicio = date('Y-m-01');
            break;
        case 'anio':
            $fecha_inicio = date('Y-01-01');
            break;
        default: // hoy
            $fecha_inicio = date('Y-m-d');
    }

    // 1. Viajes de hoy (o del período seleccionado)
    // El conductor está en asignaciones_conductor, no en solicitudes_servicio
    $viajes_sql = "SELECT 
                    COUNT(DISTINCT ss.id) as total_viajes,
                    COUNT(DISTINCT CASE WHEN ss.estado IN ('completada', 'entregado') THEN ss.id END) as viajes_completados,
                    COUNT(DISTINCT CASE WHEN ss.estado IN ('pendiente', 'aceptada', 'en_camino', 'en_progreso') THEN ss.id END) as viajes_activos,
                    COUNT(DISTINCT CASE WHEN ss.estado IN ('cancelado', 'cancelada') THEN ss.id END) as viajes_cancelados
                   FROM solicitudes_servicio ss
                   JOIN asignaciones_conductor ac ON ss.id = ac.solicitud_id
                   JOIN usuarios u ON ac.conductor_id = u.id
                   WHERE u.empresa_id = :empresa_id
                   AND DATE(ss.fecha_creacion) >= :fecha_inicio
                   AND DATE(ss.fecha_creacion) <= :fecha_fin";
    
    $stmt = $db->prepare($viajes_sql);
    $stmt->bindParam(':empresa_id', $empresa_id, PDO::PARAM_INT);
    $stmt->bindParam(':fecha_inicio', $fecha_inicio);
    $stmt->bindParam(':fecha_fin', $fecha_fin);
    $stmt->execute();
    $viajes_stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // 2. Total de conductores de la empresa (Solo conductores aprobados y vinculados)
    $conductores_sql = "SELECT 
                         COUNT(DISTINCT u.id) as total,
                         COUNT(DISTINCT CASE WHEN u.es_activo = 1 THEN u.id END) as activos,
                         COUNT(DISTINCT CASE WHEN u.es_activo = 0 THEN u.id END) as inactivos
                        FROM usuarios u
                        WHERE u.tipo_usuario = 'conductor' 
                        AND u.empresa_id = :empresa_id";
    
    $stmt = $db->prepare($conductores_sql);
    $stmt->bindParam(':empresa_id', $empresa_id, PDO::PARAM_INT);
    $stmt->execute();
    $conductores_stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // 3. Ganancias (comisiones RECIBIDAS/COBRADAS de conductores)
    // Se calcula sumando los pagos registrados en pagos_comision
    $ganancias_sql = "SELECT 
                       COALESCE(SUM(pc.monto), 0) as ganancias_empresa,
                       COUNT(pc.id) as viajes_pagados
                      FROM pagos_comision pc
                      JOIN usuarios u ON pc.conductor_id = u.id
                      WHERE u.empresa_id = :empresa_id
                      AND DATE(pc.fecha_pago) >= :fecha_inicio
                      AND DATE(pc.fecha_pago) <= :fecha_fin";
    
    $stmt = $db->prepare($ganancias_sql);
    $stmt->bindParam(':empresa_id', $empresa_id, PDO::PARAM_INT);
    $stmt->bindParam(':fecha_inicio', $fecha_inicio);
    $stmt->bindParam(':fecha_fin', $fecha_fin);
    $stmt->execute();
    $ganancias_stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // 4. Solicitudes de vinculación pendientes
    $solicitudes_sql = "SELECT COUNT(*) FROM solicitudes_vinculacion_conductor 
                        WHERE empresa_id = :empresa_id AND estado = 'pendiente'";
    $stmt = $db->prepare($solicitudes_sql);
    $stmt->bindParam(':empresa_id', $empresa_id, PDO::PARAM_INT);
    $stmt->execute();
    $solicitudes_pendientes = $stmt->fetchColumn();

    // 5. Calificación promedio de conductores de la empresa
    $rating_sql = "SELECT 
                    COALESCE(AVG(c.calificacion), 0) as promedio,
                    COUNT(c.id) as total_calificaciones
                   FROM calificaciones c
                   JOIN usuarios u ON c.usuario_calificado_id = u.id
                   WHERE u.empresa_id = :empresa_id
                   AND u.tipo_usuario = 'conductor'";
    
    $stmt = $db->prepare($rating_sql);
    $stmt->bindParam(':empresa_id', $empresa_id, PDO::PARAM_INT);
    $stmt->execute();
    $rating_stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // 6. Comparativa con período anterior (para mostrar tendencias)
    $dias_periodo = (strtotime($fecha_fin) - strtotime($fecha_inicio)) / 86400 + 1;
    $fecha_inicio_anterior = date('Y-m-d', strtotime($fecha_inicio . " - $dias_periodo days"));
    $fecha_fin_anterior = date('Y-m-d', strtotime($fecha_inicio . " - 1 day"));

    $viajes_anterior_sql = "SELECT COUNT(DISTINCT ss.id) as total
                            FROM solicitudes_servicio ss
                            JOIN asignaciones_conductor ac ON ss.id = ac.solicitud_id
                            JOIN usuarios u ON ac.conductor_id = u.id
                            WHERE u.empresa_id = :empresa_id
                            AND DATE(ss.fecha_creacion) >= :fecha_inicio
                            AND DATE(ss.fecha_creacion) <= :fecha_fin";
    
    $stmt = $db->prepare($viajes_anterior_sql);
    $stmt->bindParam(':empresa_id', $empresa_id, PDO::PARAM_INT);
    $stmt->bindParam(':fecha_inicio', $fecha_inicio_anterior);
    $stmt->bindParam(':fecha_fin', $fecha_fin_anterior);
    $stmt->execute();
    $viajes_anterior = $stmt->fetchColumn();

    // Calcular porcentaje de cambio
    $cambio_viajes = 0;
    if ($viajes_anterior > 0) {
        $cambio_viajes = round((($viajes_stats['total_viajes'] - $viajes_anterior) / $viajes_anterior) * 100, 1);
    }

    // Formatear ganancias para mostrar
    $ganancias_formateadas = floatval($ganancias_stats['ganancias_empresa'] ?? 0);
    if ($ganancias_formateadas >= 1000000) {
        $ganancias_display = '$' . round($ganancias_formateadas / 1000000, 1) . 'M';
    } elseif ($ganancias_formateadas >= 1000) {
        $ganancias_display = '$' . round($ganancias_formateadas / 1000, 0) . 'k';
    } else {
        $ganancias_display = '$' . number_format($ganancias_formateadas, 0);
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'empresa' => [
                'id' => $empresa['id'],
                'nombre' => $empresa['nombre'],
                'comision_porcentaje' => $comision_empresa,
            ],
            'periodo' => [
                'tipo' => $periodo,
                'fecha_inicio' => $fecha_inicio,
                'fecha_fin' => $fecha_fin,
            ],
            'viajes' => [
                'hoy' => intval($viajes_stats['total_viajes']),
                'completados' => intval($viajes_stats['viajes_completados']),
                'activos' => intval($viajes_stats['viajes_activos']),
                'cancelados' => intval($viajes_stats['viajes_cancelados']),
                'cambio_porcentaje' => $cambio_viajes,
            ],
            'conductores' => [
                'total' => intval($conductores_stats['total']),
                'activos' => intval($conductores_stats['activos']),
                'inactivos' => intval($conductores_stats['inactivos']),
            ],
            'ganancias' => [
                'total' => round($ganancias_formateadas, 2),
                'display' => $ganancias_display,
                'ingresos_brutos' => round(floatval($ganancias_stats['ingresos_totales'] ?? 0), 2),
                'viajes_pagados' => intval($ganancias_stats['viajes_pagados']),
            ],
            'solicitudes_pendientes' => intval($solicitudes_pendientes),
            'calificacion' => [
                'promedio' => round(floatval($rating_stats['promedio']), 1),
                'total' => intval($rating_stats['total_calificaciones']),
            ],
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
