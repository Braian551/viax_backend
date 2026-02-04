<?php
/**
 * Dashboard Stats API
 * Retorna estadísticas generales del sistema para el panel de administrador
 */

// Configuración de errores y CORS
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/config.php';

try {
    // Verificar que sea un administrador
    $input = $_SERVER['REQUEST_METHOD'] === 'GET' ? $_GET : getJsonInput();
    
    // Log para debug
    error_log("Dashboard Stats - Input recibido: " . json_encode($input));
    
    if (empty($input['admin_id'])) {
        http_response_code(400);
        sendJsonResponse(false, 'ID de administrador requerido');
    }

    $database = new Database();
    $db = $database->getConnection();

    // Verificar que el usuario sea administrador
    $checkAdmin = "SELECT id, tipo_usuario, nombre, email, telefono FROM usuarios WHERE id = ? AND tipo_usuario = 'administrador'";
    $stmtCheck = $db->prepare($checkAdmin);
    $stmtCheck->execute([$input['admin_id']]);
    
    $adminData = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    
    if (!$adminData) {
        http_response_code(403);
        sendJsonResponse(false, 'Acceso denegado. Solo administradores pueden acceder.');
    }
    
    error_log("Dashboard Stats - Admin verificado: " . json_encode($adminData));

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
    
    error_log("Dashboard Stats - User Stats: " . json_encode($userStats));

    // Estadísticas de solicitudes
    $querySolicitudes = "SELECT 
        COUNT(*) as total_solicitudes,
        SUM(CASE WHEN estado = 'completado' THEN 1 ELSE 0 END) as completadas,
        SUM(CASE WHEN estado = 'cancelado' THEN 1 ELSE 0 END) as canceladas,
        SUM(CASE WHEN estado = 'en_proceso' THEN 1 ELSE 0 END) as en_proceso,
        SUM(CASE WHEN DATE(fecha_creacion) = CURRENT_DATE THEN 1 ELSE 0 END) as solicitudes_hoy
    FROM solicitudes_servicio";
    
    $stmtSolicitudes = $db->query($querySolicitudes);
    $solicitudStats = $stmtSolicitudes->fetch(PDO::FETCH_ASSOC);

    // Ingresos (de tabla transacciones si existe)
    $queryIngresos = "SELECT 
        COALESCE(SUM(CASE WHEN estado = 'completado' THEN monto_total ELSE 0 END), 0) as ingresos_totales,
        COALESCE(SUM(CASE WHEN estado = 'completado' AND DATE(fecha_creacion) = CURRENT_DATE THEN monto_total ELSE 0 END), 0) as ingresos_hoy
    FROM transacciones";
    
    try {
        $stmtIngresos = $db->query($queryIngresos);
        $ingresosStats = $stmtIngresos->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Si la tabla transacciones no existe aún
        $ingresosStats = ['ingresos_totales' => 0, 'ingresos_hoy' => 0];
    }

    // Reportes pendientes
    $queryReportes = "SELECT COUNT(*) as reportes_pendientes 
                      FROM reportes_usuarios 
                      WHERE estado = 'pendiente'";
    
    try {
        $stmtReportes = $db->query($queryReportes);
        $reportesStats = $stmtReportes->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $reportesStats = ['reportes_pendientes' => 0];
    }

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
    
    error_log("Dashboard Stats - Datos completos: " . json_encode($dashboardData));

    http_response_code(200);
    sendJsonResponse(true, 'Estadísticas obtenidas exitosamente', $dashboardData);

} catch (Exception $e) {
    error_log("Error en dashboard_stats: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    sendJsonResponse(false, 'Error al obtener estadísticas: ' . $e->getMessage());
}
