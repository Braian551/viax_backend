<?php
/**
 * Company Reports PDF Generator
 * Genera reportes profesionales en formato PDF
 */

$viaxOrigin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
$viaxAllowedOrigins = ['https://viaxcol.online', 'https://www.viaxcol.online'];
if ($viaxOrigin !== '' && in_array($viaxOrigin, $viaxAllowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $viaxOrigin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/PdfGenerator.php';

$empresaId = $_GET['empresa_id'] ?? null;
$periodo = $_GET['periodo'] ?? '30d';

if (!$empresaId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'empresa_id es requerido']);
    exit;
}

try {
    $database = new Database();
    $pdo = $database->getConnection();

    // 1. Get Company Name
    $stmt = $pdo->prepare("SELECT nombre, logo_url FROM empresas_transporte WHERE id = :id");
    $stmt->execute(['id' => $empresaId]);
    $empresa = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $empresaNombre = $empresa['nombre'] ?? 'Empresa de Transporte';
    $empresaLogoUrl = $empresa['logo_url'] ?? null;

    // 2. Fetch Data (Mirroring reports.php logic)
    $dateFilter = getDateFilterLocal($periodo);
    
    // Stats
    $tripStats = getTripStatsLocal($pdo, $empresaId, $dateFilter);
    $earningsStats = getEarningsStatsLocal($pdo, $empresaId, $periodo);
    $topDrivers = getTopDriversLocal($pdo, $empresaId, $dateFilter);
    $vehicleDistribution = getVehicleDistributionLocal($pdo, $empresaId, $dateFilter);
    $recentTrips = getRecentTripsLocal($pdo, $empresaId, $periodo, 20);

    // 3. Generate PDF
    $pdfGen = new PdfGenerator();
    $data = [
        'empresa_nombre' => $empresaNombre,
        'periodo' => $periodo,
        'trip_stats' => $tripStats,
        'earnings_stats' => $earningsStats,
        'top_drivers' => $topDrivers,
        'vehicle_distribution' => $vehicleDistribution,
        'recent_trips' => $recentTrips,
        'company_logo_url' => $empresaLogoUrl,
        'generated_at' => date('Y-m-d H:i:s')
    ];

    $pdfPath = $pdfGen->generateActivityReport($data);

    // 4. Stream PDF to browser
    if (file_exists($pdfPath)) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="reporte_viax_' . $periodo . '.pdf"');
        readfile($pdfPath);
        @unlink($pdfPath);
        exit;
    } else {
        throw new Exception("No se pudo generar el archivo PDF");
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

// --- HELPER FUNCTIONS (Duplicated from reports.php for portability) ---

function getDateFilterLocal($periodo) {
    switch ($periodo) {
        case '7d': return "AND s.solicitado_en >= NOW() - INTERVAL '7 days'";
        case '30d': return "AND s.solicitado_en >= NOW() - INTERVAL '30 days'";
        case '90d': return "AND s.solicitado_en >= NOW() - INTERVAL '90 days'";
        case '1y': return "AND s.solicitado_en >= NOW() - INTERVAL '1 year'";
        default: return "";
    }
}

function getTripStatsLocal($pdo, $empresaId, $dateFilter) {
    $sql = "SELECT 
                COUNT(*) as total_viajes,
                COUNT(CASE WHEN s.estado IN ('completada', 'entregado') THEN 1 END) as completados
            FROM solicitudes_servicio s
            INNER JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
            INNER JOIN usuarios u ON ac.conductor_id = u.id
            WHERE u.empresa_id = :empresa_id
            $dateFilter";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['empresa_id' => $empresaId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $total = (int)$result['total_viajes'];
    $completados = (int)$result['completados'];
    $tasa = $total > 0 ? round(($completados / $total) * 100, 1) : 0;
    
    return ['total' => $total, 'tasa_completados' => $tasa];
}

function getEarningsStatsLocal($pdo, $empresaId, $periodo) {
    $dateFilter = getDateFilterLocal($periodo);
    $sql = "SELECT COALESCE(SUM(s.precio_final), 0) as ingresos 
            FROM solicitudes_servicio s
            INNER JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
            INNER JOIN usuarios u ON ac.conductor_id = u.id
            WHERE u.empresa_id = :empresa_id AND s.estado IN ('completada', 'entregado')
            $dateFilter";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['empresa_id' => $empresaId]);
    $ingresos = $stmt->fetchColumn();

    $pagoFilter = str_replace('s.solicitado_en', 'pc.fecha_pago', $dateFilter);
    $sqlReal = "SELECT COALESCE(SUM(pc.monto), 0) FROM pagos_comision pc
                INNER JOIN usuarios u ON pc.conductor_id = u.id
                WHERE u.empresa_id = :empresa_id $pagoFilter";
    $stmtReal = $pdo->prepare($sqlReal);
    $stmtReal->execute(['empresa_id' => $empresaId]);
    $realized = $stmtReal->fetchColumn();

    return ['ingresos_totales' => $ingresos, 'ganancia_neta' => $realized];
}

function getTopDriversLocal($pdo, $empresaId, $dateFilter) {
    $sql = "SELECT u.nombre, COUNT(s.id) as total_viajes, 
                   COALESCE(SUM(CASE WHEN s.estado IN ('completada', 'entregado') THEN s.precio_final END), 0) as ingresos
            FROM usuarios u
            INNER JOIN asignaciones_conductor ac ON u.id = ac.conductor_id
            INNER JOIN solicitudes_servicio s ON ac.solicitud_id = s.id $dateFilter
            WHERE u.empresa_id = :empresa_id AND u.tipo_usuario = 'conductor'
            GROUP BY u.id, u.nombre
            ORDER BY total_viajes DESC LIMIT 5";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['empresa_id' => $empresaId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getVehicleDistributionLocal($pdo, $empresaId, $dateFilter) {
    $sql = "SELECT COALESCE(NULLIF(TRIM(s.tipo_vehiculo), ''), NULLIF(TRIM(s.tipo_servicio), ''), 'otro') as tipo, COUNT(*) as viajes,
                   COALESCE(SUM(CASE WHEN s.estado IN ('completada', 'entregado') THEN s.precio_final END), 0) as ingresos
            FROM solicitudes_servicio s
            INNER JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
            INNER JOIN usuarios u ON ac.conductor_id = u.id
            WHERE u.empresa_id = :empresa_id $dateFilter
            GROUP BY COALESCE(NULLIF(TRIM(s.tipo_vehiculo), ''), NULLIF(TRIM(s.tipo_servicio), ''), 'otro') ORDER BY viajes DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['empresa_id' => $empresaId]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $vehicleNames = [
        'moto' => 'Moto', 'mototaxi' => 'Mototaxi', 'taxi' => 'Taxi', 'carro' => 'Carro',
        'auto' => 'Carro', 'camioneta' => 'Camioneta', 'camion_pequeño' => 'Camión Pequeño',
        'camion_grande' => 'Camión Grande', 'mudanza' => 'Mudanza', 'transporte' => 'Transporte General'
    ];

    return array_map(function($row) use ($vehicleNames) {
        return [
            'nombre' => $vehicleNames[$row['tipo']] ?? ucfirst($row['tipo']),
            'viajes' => (int)$row['viajes'],
            'ingresos' => round((float)$row['ingresos'], 2),
        ];
    }, $results);
}

function getRecentTripsLocal($pdo, $empresaId, $periodo, $limit = 20) {
    $dateFilter = getDateFilterLocal($periodo);
    $sql = "SELECT
                s.id,
                s.solicitado_en,
                s.estado,
                COALESCE(NULLIF(TRIM(s.tipo_vehiculo), ''), NULLIF(TRIM(s.tipo_servicio), ''), 'otro') as tipo_operacion,
                s.direccion_recogida,
                s.direccion_destino,
                s.precio_final,
                u.nombre as conductor_nombre
            FROM solicitudes_servicio s
            INNER JOIN asignaciones_conductor ac ON s.id = ac.solicitud_id
            INNER JOIN usuarios u ON ac.conductor_id = u.id
            WHERE u.empresa_id = :empresa_id $dateFilter
            ORDER BY s.solicitado_en DESC
            LIMIT :limit";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue('empresa_id', $empresaId);
    $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return array_map(function($row) {
        $tipoRaw = strtolower(trim((string)($row['tipo_operacion'] ?? '')));
        if ($tipoRaw === 'auto') {
            $tipoRaw = 'carro';
        }

        $statusRaw = strtolower(trim((string)($row['estado'] ?? '')));
        if ($statusRaw === 'entregado') {
            $statusRaw = 'completada';
        } elseif ($statusRaw === 'cancelado') {
            $statusRaw = 'cancelada';
        }

        return [
            'id' => (int)$row['id'],
            'fecha' => $row['solicitado_en'],
            'conductor' => $row['conductor_nombre'] ?? 'N/A',
            'tipo_operacion' => $tipoRaw,
            'tipo_operacion_nombre' => ucfirst($tipoRaw),
            'estado' => $statusRaw,
            'origen' => $row['direccion_recogida'] ?? '',
            'destino' => $row['direccion_destino'] ?? '',
            'valor' => round((float)($row['precio_final'] ?? 0), 2),
        ];
    }, $rows);
}
