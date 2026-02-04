<?php
/**
 * Company Reports API
 * Endpoint para reportes avanzados de empresa
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $pdo = $database->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error de conexión a la base de datos: ' . $e->getMessage()]);
    exit;
}

$action = $_GET['action'] ?? 'overview';
$empresaId = $_GET['empresa_id'] ?? null;

if (!$empresaId) {
    echo json_encode(['success' => false, 'message' => 'empresa_id es requerido']);
    exit;
}

switch ($action) {
    case 'overview':
        getReportsOverview($pdo, $empresaId);
        break;
    case 'trips':
        getTripsReport($pdo, $empresaId);
        break;
    case 'drivers':
        getDriversReport($pdo, $empresaId);
        break;
    case 'earnings':
        getEarningsReport($pdo, $empresaId);
        break;
    case 'vehicle_types':
        getVehicleTypesReport($pdo, $empresaId);
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Acción no válida']);
}

/**
 * Obtener resumen general de reportes
 */
function getReportsOverview($pdo, $empresaId) {
    $periodo = $_GET['periodo'] ?? '7d'; // 7d, 30d, 90d, all
    
    $dateFilter = getDateFilter($periodo);
    
    try {
        // Estadísticas generales de viajes
        $tripStats = getTripStats($pdo, $empresaId, $dateFilter);
        
        // Estadísticas de ganancias
        $earningsStats = getEarningsStats($pdo, $empresaId, $periodo);
        
        // Estadísticas de conductores
        $driverStats = getDriverStats($pdo, $empresaId);
        
        // Tendencias (comparación con periodo anterior)
        $trends = calculateTrends($pdo, $empresaId, $periodo);
        
        // Datos para gráficos
        $chartData = getChartData($pdo, $empresaId, $periodo);
        
        // Top conductores
        $topDrivers = getTopDrivers($pdo, $empresaId, $dateFilter);
        
        // Distribución por tipo de vehículo
        $vehicleDistribution = getVehicleDistribution($pdo, $empresaId, $dateFilter);
        
        // Horas pico
        $peakHours = getPeakHours($pdo, $empresaId, $dateFilter);
        
        echo json_encode([
            'success' => true,
            'data' => [
                'periodo' => $periodo,
                'trip_stats' => $tripStats,
                'earnings_stats' => $earningsStats,
                'driver_stats' => $driverStats,
                'trends' => $trends,
                'chart_data' => $chartData,
                'top_drivers' => $topDrivers,
                'vehicle_distribution' => $vehicleDistribution,
                'peak_hours' => $peakHours,
            ]
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
}

/**
 * Obtener filtro de fecha según periodo
 */
function getDateFilter($periodo) {
    switch ($periodo) {
        case '7d':
            return "AND s.solicitado_en >= NOW() - INTERVAL '7 days'";
        case '30d':
            return "AND s.solicitado_en >= NOW() - INTERVAL '30 days'";
        case '90d':
            return "AND s.solicitado_en >= NOW() - INTERVAL '90 days'";
        case '1y':
            return "AND s.solicitado_en >= NOW() - INTERVAL '1 year'";
        case 'all':
        default:
            return "";
    }
}

/**
 * Estadísticas de viajes
 */
function getTripStats($pdo, $empresaId, $dateFilter) {
    $sql = "SELECT 
                COUNT(*) as total_viajes,
                COUNT(CASE WHEN s.estado IN ('completada', 'entregado') THEN 1 END) as completados,
                COUNT(CASE WHEN s.estado IN ('cancelado', 'cancelada') THEN 1 END) as cancelados,
                COUNT(CASE WHEN s.estado IN ('pendiente', 'aceptada', 'en_camino', 'en_progreso') THEN 1 END) as en_progreso,
                COALESCE(AVG(s.distancia_estimada), 0) as distancia_promedio,
                COALESCE(SUM(s.distancia_estimada), 0) as distancia_total,
                COALESCE(AVG(EXTRACT(EPOCH FROM (s.completado_en - s.aceptado_en))/60), 0) as duracion_promedio
            FROM solicitudes_servicio s
            INNER JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
            INNER JOIN usuarios u ON ac.conductor_id = u.id
            WHERE u.empresa_id = :empresa_id
            $dateFilter";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['empresa_id' => $empresaId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $tasa_completados = $result['total_viajes'] > 0 
        ? round(($result['completados'] / $result['total_viajes']) * 100, 1) 
        : 0;
    
    return [
        'total' => (int)$result['total_viajes'],
        'completados' => (int)$result['completados'],
        'cancelados' => (int)$result['cancelados'],
        'en_progreso' => (int)$result['en_progreso'],
        'tasa_completados' => $tasa_completados,
        'distancia_promedio' => round($result['distancia_promedio'], 2),
        'distancia_total' => round($result['distancia_total'], 2),
        'duracion_promedio' => round($result['duracion_promedio'], 0),
    ];
}

/**
 * Estadísticas de ganancias
 */
function getEarningsStats($pdo, $empresaId, $periodo) {
    $dateFilter = getDateFilter($periodo);
    
    // 1. GMV (Volumen Bruto) - Base: Viajes completados
    $sql = "SELECT 
                COALESCE(SUM(s.precio_final), 0) as ingresos_totales,
                COALESCE(AVG(s.precio_final), 0) as ingreso_promedio,
                COALESCE(MAX(s.precio_final), 0) as ingreso_maximo,
                COALESCE(MIN(CASE WHEN s.precio_final > 0 THEN s.precio_final END), 0) as ingreso_minimo
            FROM solicitudes_servicio s
            INNER JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
            INNER JOIN usuarios u ON ac.conductor_id = u.id
            WHERE u.empresa_id = :empresa_id
            AND s.estado IN ('completada', 'entregado')
            $dateFilter";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['empresa_id' => $empresaId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 2. Ganancia Real (Comisiones Cobradas) - Base: Pagos registrados
    $pagoFilter = "";
    switch ($periodo) {
        case '7d': $pagoFilter = "AND pc.fecha_pago >= NOW() - INTERVAL '7 days'"; break;
        case '30d': $pagoFilter = "AND pc.fecha_pago >= NOW() - INTERVAL '30 days'"; break;
        case '90d': $pagoFilter = "AND pc.fecha_pago >= NOW() - INTERVAL '90 days'"; break;
        case '1y': $pagoFilter = "AND pc.fecha_pago >= NOW() - INTERVAL '1 year'"; break;
    }
    
    $sqlRealized = "SELECT COALESCE(SUM(pc.monto), 0) as ganancia_real
                    FROM pagos_comision pc
                    INNER JOIN usuarios u ON pc.conductor_id = u.id
                    WHERE u.empresa_id = :empresa_id
                    $pagoFilter";
                    
    $stmtRealized = $pdo->prepare($sqlRealized);
    $stmtRealized->execute(['empresa_id' => $empresaId]);
    $realized = $stmtRealized->fetchColumn();
    
    // Estimación de comisiones (teórica, basada en GMV)
    $comisionTeorica = calcularComisionEmpresa($pdo, $empresaId, $result['ingresos_totales']);
    
    return [
        'ingresos_totales' => round($result['ingresos_totales'], 2), // GMV
        'ingreso_promedio' => round($result['ingreso_promedio'], 2),
        'ingreso_maximo' => round($result['ingreso_maximo'], 2),
        'ingreso_minimo' => round($result['ingreso_minimo'], 2),
        'comision_empresa' => round($realized, 2), // Usamos REALIZADA para mostrar en métricas
        'ganancia_neta' => round($realized, 2),    // REALIZADA
        'comision_teorica' => round($comisionTeorica, 2) // Para referencia interna si se necesita
    ];
}

/**
 * Calcular comisión promedio de la empresa
 */
function calcularComisionEmpresa($pdo, $empresaId, $ingresosTotales) {
    // Obtener comisión promedio configurada
    $sql = "SELECT COALESCE(AVG(comision_plataforma), 10) as comision_promedio 
            FROM configuracion_precios 
            WHERE empresa_id = :empresa_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['empresa_id' => $empresaId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $comisionPorcentaje = $result['comision_promedio'] ?? 10;
    return ($ingresosTotales * $comisionPorcentaje) / 100;
}

/**
 * Estadísticas de conductores
 */
function getDriverStats($pdo, $empresaId) {
    $sql = "SELECT 
                COUNT(*) as total,
                COUNT(CASE WHEN es_activo = 1 THEN 1 END) as activos,
                COUNT(CASE WHEN es_activo = 0 THEN 1 END) as inactivos
            FROM usuarios 
            WHERE empresa_id = :empresa_id 
            AND tipo_usuario = 'conductor'";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['empresa_id' => $empresaId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return [
        'total' => (int)$result['total'],
        'activos' => (int)$result['activos'],
        'pendientes' => 0,
        'inactivos' => (int)$result['inactivos'],
    ];
}

/**
 * Calcular tendencias comparando con periodo anterior
 */
function calculateTrends($pdo, $empresaId, $periodo) {
    $days = match($periodo) {
        '7d' => 7,
        '30d' => 30,
        '90d' => 90,
        '1y' => 365,
        default => 30,
    };
    
    // Periodo actual
    $sqlActual = "SELECT 
                    COUNT(*) as viajes,
                    COALESCE(SUM(CASE WHEN s.estado IN ('completada', 'entregado') THEN s.precio_final END), 0) as ingresos
                FROM solicitudes_servicio s
                INNER JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
                INNER JOIN usuarios u ON ac.conductor_id = u.id
                WHERE u.empresa_id = :empresa_id
                AND s.solicitado_en >= NOW() - INTERVAL '$days days'";
    
    $stmt = $pdo->prepare($sqlActual);
    $stmt->execute(['empresa_id' => $empresaId]);
    $actual = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Periodo anterior
    $sqlAnterior = "SELECT 
                    COUNT(*) as viajes,
                    COALESCE(SUM(CASE WHEN s.estado IN ('completada', 'entregado') THEN s.precio_final END), 0) as ingresos
                FROM solicitudes_servicio s
                INNER JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
                INNER JOIN usuarios u ON ac.conductor_id = u.id
                WHERE u.empresa_id = :empresa_id
                AND s.solicitado_en >= NOW() - INTERVAL '" . ($days * 2) . " days'
                AND s.solicitado_en < NOW() - INTERVAL '$days days'";
    
    $stmt = $pdo->prepare($sqlAnterior);
    $stmt->execute(['empresa_id' => $empresaId]);
    $anterior = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Calcular porcentajes de cambio
    $viajesChange = $anterior['viajes'] > 0 
        ? (($actual['viajes'] - $anterior['viajes']) / $anterior['viajes']) * 100 
        : ($actual['viajes'] > 0 ? 100 : 0);
    
    $ingresosChange = $anterior['ingresos'] > 0 
        ? (($actual['ingresos'] - $anterior['ingresos']) / $anterior['ingresos']) * 100 
        : ($actual['ingresos'] > 0 ? 100 : 0);
    
    return [
        'viajes' => [
            'actual' => (int)$actual['viajes'],
            'anterior' => (int)$anterior['viajes'],
            'cambio_porcentaje' => round($viajesChange, 1),
            'tendencia' => $viajesChange >= 0 ? 'up' : 'down',
        ],
        'ingresos' => [
            'actual' => round($actual['ingresos'], 2),
            'anterior' => round($anterior['ingresos'], 2),
            'cambio_porcentaje' => round($ingresosChange, 1),
            'tendencia' => $ingresosChange >= 0 ? 'up' : 'down',
        ],
    ];
}

/**
 * Datos para gráficos de línea/barras
 */
function getChartData($pdo, $empresaId, $periodo) {
    $groupBy = match($periodo) {
        '7d' => "DATE(s.solicitado_en)",
        '30d' => "DATE(s.solicitado_en)",
        '90d' => "DATE_TRUNC('week', s.solicitado_en)",
        '1y' => "DATE_TRUNC('month', s.solicitado_en)",
        default => "DATE(s.solicitado_en)",
    };
    
    $dateFilter = getDateFilter($periodo);
    
    $sql = "SELECT 
                $groupBy as fecha,
                COUNT(*) as viajes,
                COUNT(CASE WHEN s.estado IN ('completada', 'entregado') THEN 1 END) as completados,
                COALESCE(SUM(CASE WHEN s.estado IN ('completada', 'entregado') THEN s.precio_final END), 0) as ingresos
            FROM solicitudes_servicio s
            INNER JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
            INNER JOIN usuarios u ON ac.conductor_id = u.id
            WHERE u.empresa_id = :empresa_id
            $dateFilter
            GROUP BY $groupBy
            ORDER BY fecha ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['empresa_id' => $empresaId]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $labels = [];
    $viajes = [];
    $ingresos = [];
    
    foreach ($results as $row) {
        $date = new DateTime($row['fecha']);
        $labels[] = $date->format($periodo === '1y' ? 'M Y' : ($periodo === '90d' ? 'd M' : 'd/m'));
        $viajes[] = (int)$row['completados'];
        $ingresos[] = round((float)$row['ingresos'], 2);
    }
    
    return [
        'labels' => $labels,
        'viajes' => $viajes,
        'ingresos' => $ingresos,
    ];
}

/**
 * Top conductores por viajes/ingresos
 */
function getTopDrivers($pdo, $empresaId, $dateFilter, $limit = 5) {
    $sql = "SELECT 
                u.id,
                u.nombre,
                u.foto_perfil,
                COUNT(s.id) as total_viajes,
                COALESCE(SUM(CASE WHEN s.estado IN ('completada', 'entregado') THEN s.precio_final END), 0) as ingresos,
                COALESCE(AVG(r.calificacion), 0) as rating
            FROM usuarios u
            LEFT JOIN asignaciones_conductor ac ON u.id = ac.conductor_id
            LEFT JOIN solicitudes_servicio s ON ac.solicitud_id = s.id $dateFilter
            LEFT JOIN calificaciones r ON r.usuario_calificado_id = u.id
            WHERE u.empresa_id = :empresa_id
            AND u.tipo_usuario = 'conductor'
            GROUP BY u.id, u.nombre, u.foto_perfil
            ORDER BY total_viajes DESC
            LIMIT :limit";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue('empresa_id', $empresaId);
    $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return array_map(function($row) {
        return [
            'id' => (int)$row['id'],
            'nombre' => $row['nombre'],
            'foto_perfil' => $row['foto_perfil'],
            'total_viajes' => (int)$row['total_viajes'],
            'ingresos' => round((float)$row['ingresos'], 2),
            'rating' => round((float)$row['rating'], 1),
        ];
    }, $results);
}

/**
 * Distribución por tipo de vehículo
 */
function getVehicleDistribution($pdo, $empresaId, $dateFilter) {
    $sql = "SELECT 
                COALESCE(s.tipo_servicio, 'otro') as tipo,
                COUNT(*) as viajes,
                COALESCE(SUM(CASE WHEN s.estado IN ('completada', 'entregado') THEN s.precio_final END), 0) as ingresos
            FROM solicitudes_servicio s
            INNER JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
            INNER JOIN usuarios u ON ac.conductor_id = u.id
            WHERE u.empresa_id = :empresa_id
            $dateFilter
            GROUP BY s.tipo_servicio
            ORDER BY viajes DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['empresa_id' => $empresaId]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $vehicleNames = [
        'moto' => 'Moto',
        'auto' => 'Auto',
        'camioneta' => 'Camioneta',
        'camion_pequeño' => 'Camión Pequeño',
        'camion_grande' => 'Camión Grande',
        'mudanza' => 'Mudanza',
    ];
    
    return array_map(function($row) use ($vehicleNames) {
        return [
            'tipo' => $row['tipo'],
            'nombre' => $vehicleNames[$row['tipo']] ?? ucfirst($row['tipo']),
            'viajes' => (int)$row['viajes'],
            'ingresos' => round((float)$row['ingresos'], 2),
        ];
    }, $results);
}

/**
 * Horas pico de demanda
 */
function getPeakHours($pdo, $empresaId, $dateFilter) {
    $sql = "SELECT 
                EXTRACT(HOUR FROM s.solicitado_en) as hora,
                COUNT(*) as viajes
            FROM solicitudes_servicio s
            INNER JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
            INNER JOIN usuarios u ON ac.conductor_id = u.id
            WHERE u.empresa_id = :empresa_id
            $dateFilter
            GROUP BY EXTRACT(HOUR FROM s.solicitado_en)
            ORDER BY viajes DESC
            LIMIT 24";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['empresa_id' => $empresaId]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Crear array de 24 horas
    $hours = array_fill(0, 24, 0);
    foreach ($results as $row) {
        $hours[(int)$row['hora']] = (int)$row['viajes'];
    }
    
    return $hours;
}

/**
 * Reporte detallado de viajes
 */
function getTripsReport($pdo, $empresaId) {
    $periodo = $_GET['periodo'] ?? '30d';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(50, max(10, (int)($_GET['per_page'] ?? 20)));
    $offset = ($page - 1) * $perPage;
    
    $dateFilter = str_replace('AND s.solicitado_en', 'AND fecha_solicitud', getDateFilter($periodo));
    // The $dateFilter variable is not used in the SQL queries below,
    // so it can be removed or simplified if it was intended for other use.
    // For now, we'll just remove the problematic str_replace.
    // $dateFilter = getDateFilter($periodo); 
    
    // Total para paginación
    $countSql = "SELECT COUNT(*) FROM solicitudes_servicio s
                 INNER JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
                 INNER JOIN usuarios u ON ac.conductor_id = u.id
                 WHERE u.empresa_id = :empresa_id
                 " . getDateFilter($periodo);
    $stmt = $pdo->prepare($countSql);
    $stmt->execute(['empresa_id' => $empresaId]);
    $total = $stmt->fetchColumn();
    
    // Obtener viajes
    $sql = "SELECT 
                s.id,
                s.solicitado_en,
                s.estado,
                s.tipo_servicio,
                s.direccion_recogida,
                s.direccion_destino,
                s.distancia_estimada,
                s.precio_final,
                u.nombre as conductor_nombre
            FROM solicitudes_servicio s
            INNER JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
            INNER JOIN usuarios u ON ac.conductor_id = u.id
            WHERE u.empresa_id = :empresa_id
            " . getDateFilter($periodo) . "
            ORDER BY s.solicitado_en DESC
            LIMIT :limit OFFSET :offset";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue('empresa_id', $empresaId);
    $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $viajes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'viajes' => $viajes,
            'pagination' => [
                'total' => (int)$total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => ceil($total / $perPage),
            ],
        ],
    ]);
}

/**
 * Reporte detallado de conductores
 */
function getDriversReport($pdo, $empresaId) {
    $periodo = $_GET['periodo'] ?? '30d';
    $dateFilter = getDateFilter($periodo);
    
    $sql = "SELECT 
                u.id,
                u.nombre,
                u.email,
                u.telefono,
                u.foto_perfil,
                u.es_activo as estado,
                u.fecha_registro,
                COUNT(s.id) as total_viajes,
                COUNT(CASE WHEN s.estado IN ('completada', 'entregado') THEN 1 END) as viajes_completados,
                COUNT(CASE WHEN s.estado IN ('cancelada', 'cancelado') THEN 1 END) as viajes_cancelados,
                COALESCE(SUM(CASE WHEN s.estado IN ('completada', 'entregado') THEN s.precio_final END), 0) as ingresos,
                COALESCE(AVG(r.calificacion), 0) as rating,
                COUNT(r.id) as total_ratings
            FROM usuarios u
            LEFT JOIN asignaciones_conductor ac ON u.id = ac.conductor_id
            LEFT JOIN solicitudes_servicio s ON ac.solicitud_id = s.id $dateFilter
            LEFT JOIN calificaciones r ON r.usuario_calificado_id = u.id
            WHERE u.empresa_id = :empresa_id
            AND u.tipo_usuario = 'conductor'
            GROUP BY u.id
            ORDER BY ingresos DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['empresa_id' => $empresaId]);
    $conductores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'conductores' => array_map(function($c) {
                return [
                    'id' => (int)$c['id'],
                    'nombre' => $c['nombre'],
                    'email' => $c['email'],
                    'telefono' => $c['telefono'],
                    'foto_perfil' => $c['foto_perfil'],
                    'estado' => $c['estado'],
                    'fecha_registro' => $c['fecha_registro'],
                    'total_viajes' => (int)$c['total_viajes'],
                    'viajes_completados' => (int)$c['viajes_completados'],
                    'viajes_cancelados' => (int)$c['viajes_cancelados'],
                    'ingresos' => round((float)$c['ingresos'], 2),
                    'rating' => round((float)$c['rating'], 1),
                    'total_ratings' => (int)$c['total_ratings'],
                    'tasa_completados' => $c['total_viajes'] > 0 
                        ? round(($c['viajes_completados'] / $c['total_viajes']) * 100, 1) 
                        : 0,
                ];
            }, $conductores),
        ],
    ]);
}

/**
 * Reporte detallado de ganancias
 */
function getEarningsReport($pdo, $empresaId) {
    $periodo = $_GET['periodo'] ?? '30d';
    $dateFilter = getDateFilter($periodo);
    
    // Ganancias por día/semana/mes según periodo
    $groupBy = match($periodo) {
        '7d' => "DATE(s.solicitado_en)",
        '30d' => "DATE(s.solicitado_en)",
        '90d' => "DATE_TRUNC('week', s.solicitado_en)",
        '1y' => "DATE_TRUNC('month', s.solicitado_en)",
        default => "DATE(s.solicitado_en)",
    };
    
    $sql = "SELECT 
                $groupBy as fecha,
                COUNT(CASE WHEN s.estado IN ('completada', 'entregado') THEN 1 END) as viajes,
                COALESCE(SUM(CASE WHEN s.estado IN ('completada', 'entregado') THEN s.precio_final END), 0) as ingresos_brutos,
                COALESCE(AVG(CASE WHEN s.estado IN ('completada', 'entregado') THEN s.precio_final END), 0) as ingreso_promedio
            FROM solicitudes_servicio s
            INNER JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
            INNER JOIN usuarios u ON ac.conductor_id = u.id
            WHERE u.empresa_id = :empresa_id
            $dateFilter
            GROUP BY $groupBy
            ORDER BY fecha DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['empresa_id' => $empresaId]);
    $ganancias = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Totales
    $totales = getEarningsStats($pdo, $empresaId, $periodo);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'detalle' => array_map(function($g) {
                return [
                    'fecha' => $g['fecha'],
                    'viajes' => (int)$g['viajes'],
                    'ingresos_brutos' => round((float)$g['ingresos_brutos'], 2),
                    'ingreso_promedio' => round((float)$g['ingreso_promedio'], 2),
                ];
            }, $ganancias),
            'totales' => $totales,
        ],
    ]);
}

/**
 * Reporte por tipo de vehículo
 */
function getVehicleTypesReport($pdo, $empresaId) {
    $periodo = $_GET['periodo'] ?? '30d';
    $dateFilter = getDateFilter($periodo);
    
    $sql = "SELECT 
                COALESCE(s.tipo_servicio, 'otro') as tipo,
                COUNT(*) as total_viajes,
                COUNT(CASE WHEN s.estado IN ('completada', 'entregado') THEN 1 END) as completados,
                COUNT(CASE WHEN s.estado IN ('cancelada', 'cancelado') THEN 1 END) as cancelados,
                COALESCE(SUM(CASE WHEN s.estado IN ('completada', 'entregado') THEN s.precio_final END), 0) as ingresos,
                COALESCE(AVG(CASE WHEN s.estado IN ('completada', 'entregado') THEN s.precio_final END), 0) as ingreso_promedio,
                COALESCE(AVG(s.distancia_estimada), 0) as distancia_promedio
            FROM solicitudes_servicio s
            INNER JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
            INNER JOIN usuarios u ON ac.conductor_id = u.id
            WHERE u.empresa_id = :empresa_id
            $dateFilter
            GROUP BY s.tipo_servicio
            ORDER BY ingresos DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['empresa_id' => $empresaId]);
    $vehiculos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $vehicleNames = [
        'moto' => 'Moto',
        'auto' => 'Auto',
        'camioneta' => 'Camioneta',
        'camion_pequeño' => 'Camión Pequeño',
        'camion_grande' => 'Camión Grande',
        'mudanza' => 'Mudanza',
    ];
    
    echo json_encode([
        'success' => true,
        'data' => [
            'vehiculos' => array_map(function($v) use ($vehicleNames) {
                return [
                    'tipo' => $v['tipo'],
                    'nombre' => $vehicleNames[$v['tipo']] ?? ucfirst($v['tipo']),
                    'total_viajes' => (int)$v['total_viajes'],
                    'completados' => (int)$v['completados'],
                    'cancelados' => (int)$v['cancelados'],
                    'tasa_completados' => $v['total_viajes'] > 0 
                        ? round(($v['completados'] / $v['total_viajes']) * 100, 1) 
                        : 0,
                    'ingresos' => round((float)$v['ingresos'], 2),
                    'ingreso_promedio' => round((float)$v['ingreso_promedio'], 2),
                    'distancia_promedio' => round((float)$v['distancia_promedio'], 2),
                ];
            }, $vehiculos),
        ],
    ]);
}
