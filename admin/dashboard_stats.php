<?php
/**
 * Dashboard Stats API
 * Retorna estadísticas generales del sistema para el panel de administrador
 */

// Configuración de errores y CORS
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

$viaxOrigin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
$viaxAllowedOrigins = ['https://viaxcol.online', 'https://www.viaxcol.online'];
if ($viaxOrigin !== '' && in_array($viaxOrigin, $viaxAllowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $viaxOrigin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../middleware/SessionAccess.php';

try {
    if (!in_array(Request::method(), ['GET', 'POST'], true)) {
        header('Allow: GET, POST');
        sendJsonResponse(false, 'Método no permitido.', [], 405);
    }
    $db = (new Database())->getConnection();
    $adminData = SessionAccess::requireAdmin($db);

    // === ESTADÍSTICAS GENERALES ===
    
    // Contar usuarios por tipo
    $queryUsers = "SELECT 
        COUNT(*) as total_usuarios,
        SUM(CASE WHEN tipo_usuario = 'cliente' THEN 1 ELSE 0 END) as total_clientes,
        SUM(CASE WHEN tipo_usuario = 'conductor' THEN 1 ELSE 0 END) as total_conductores,
        SUM(CASE WHEN tipo_usuario = 'administrador' THEN 1 ELSE 0 END) as total_administradores,
        SUM(CASE WHEN es_activo = 1 THEN 1 ELSE 0 END) as usuarios_activos,
        SUM(CASE WHEN DATE(fecha_registro) = CURRENT_DATE THEN 1 ELSE 0 END) as registros_hoy
    FROM usuarios";
    
    $stmtUsers = $db->query($queryUsers);
    $userStats = $stmtUsers->fetch(PDO::FETCH_ASSOC);
    


    // Estadísticas de solicitudes
    $querySolicitudes = "SELECT 
        COUNT(*) as total_solicitudes,
        SUM(CASE WHEN estado IN ('completada', 'completado') THEN 1 ELSE 0 END) as completadas,
        SUM(CASE WHEN estado IN ('cancelada', 'cancelado') THEN 1 ELSE 0 END) as canceladas,
        SUM(CASE WHEN estado = 'en_proceso' THEN 1 ELSE 0 END) as en_proceso,
        SUM(CASE WHEN DATE(fecha_creacion) = CURRENT_DATE THEN 1 ELSE 0 END) as solicitudes_hoy
    FROM solicitudes_servicio";
    
    $stmtSolicitudes = $db->query($querySolicitudes);
    $solicitudStats = $stmtSolicitudes->fetch(PDO::FETCH_ASSOC);

    // Ingresos de plataforma desde pagos empresariales confirmados.
    $queryIngresos = "SELECT 
        COALESCE(SUM(CASE WHEN tipo = 'pago' THEN monto ELSE 0 END), 0) AS ingresos_totales,
        COALESCE(SUM(CASE WHEN tipo = 'pago' AND DATE(creado_en) = :hoy THEN monto ELSE 0 END), 0) AS ingresos_hoy
    FROM pagos_empresas";
    $stmtIngresos = $db->prepare($queryIngresos);
    $stmtIngresos->execute([':hoy' => date('Y-m-d')]);
    $ingresosStats = $stmtIngresos->fetch(PDO::FETCH_ASSOC) ?: ['ingresos_totales' => 0, 'ingresos_hoy' => 0];

    // Reportes pendientes (usuarios + comprobantes de pago de empresa).
    $queryReportes = "SELECT
        (
            COALESCE((SELECT COUNT(*) FROM reportes_usuarios WHERE estado = 'pendiente'), 0)
            +
            COALESCE((SELECT COUNT(*) FROM pagos_empresa_reportes WHERE estado = 'pendiente_revision'), 0)
        ) AS reportes_pendientes";
    $stmtReportes = $db->query($queryReportes);
    $reportesStats = $stmtReportes->fetch(PDO::FETCH_ASSOC) ?: ['reportes_pendientes' => 0];

    // Últimas actividades (logs de auditoría)
    $queryActividades = "SELECT 
        l.id,
        l.accion,
        l.descripcion,
        l.fecha_creacion,
        u.nombre,
        u.apellido,
        u.email
    FROM logs_auditoria l
    LEFT JOIN usuarios u ON l.usuario_id = u.id
    ORDER BY l.fecha_creacion DESC
    LIMIT 10";
    
    try {
        $stmtActividades = $db->query($queryActividades);
        $actividades = $stmtActividades->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $actividades = [];
    }

    // Grafica de registros últimos 7 días
    $queryRegistrosGrafica = "SELECT 
        DATE(fecha_registro) as fecha,
        COUNT(*) as cantidad
    FROM usuarios
    WHERE fecha_registro >= CURRENT_DATE - INTERVAL '7 days'
    GROUP BY DATE(fecha_registro)
    ORDER BY fecha ASC";
    
    $stmtGrafica = $db->query($queryRegistrosGrafica);
    $registrosGrafica = $stmtGrafica->fetchAll(PDO::FETCH_ASSOC);

    // Consolidar respuesta
    $dashboardData = [
        'admin' => $adminData,  // Agregar datos del admin
        'usuarios' => $userStats,
        'solicitudes' => $solicitudStats,
        'ingresos' => $ingresosStats,
        'reportes' => $reportesStats,
        'actividades_recientes' => $actividades,
        'registros_ultimos_7_dias' => $registrosGrafica,
        'fecha_actualizacion' => date('Y-m-d H:i:s')
    ];
    


    http_response_code(200);
    sendJsonResponse(true, 'Estadísticas obtenidas exitosamente', $dashboardData);

} catch (Exception $e) {
    error_log("Error en dashboard_stats: " . $e->getMessage());
    sendJsonResponse(false, 'No se pudieron cargar las estadísticas.', [], 500);
}
