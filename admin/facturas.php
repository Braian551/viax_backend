<?php
/**
 * API: Gestión de Facturas
 * Endpoint: GET admin/facturas.php
 * 
 * Las facturas son documentos permanentes que NUNCA se eliminan (requisito legal).
 * Pueden ser consultadas por admin (todas) o por empresa (las suyas).
 * 
 * Parámetros GET:
 *   - tipo: 'empresa_admin' | 'conductor_empresa' (filtro opcional)
 *   - empresa_id: Filtrar por empresa específica
 *   - fecha_desde: Filtro de fecha inicio (YYYY-MM-DD)
 *   - fecha_hasta: Filtro de fecha fin (YYYY-MM-DD)
 *   - limit: Máximo de resultados (default 50, max 200)
 *   - page: Página para paginación (default 1)
 */

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    $tipo = trim($_GET['tipo'] ?? '');
    $empresaId = isset($_GET['empresa_id']) ? intval($_GET['empresa_id']) : 0;
    $fechaDesde = trim($_GET['fecha_desde'] ?? '');
    $fechaHasta = trim($_GET['fecha_hasta'] ?? '');
    $limit = min(max(intval($_GET['limit'] ?? 50), 1), 200);
    $page = max(intval($_GET['page'] ?? 1), 1);
    $offset = ($page - 1) * $limit;

    $where = [];
    $params = [];

    if ($tipo !== '') {
        $where[] = "f.tipo = :tipo";
        $params[':tipo'] = $tipo;
    }

    if ($empresaId > 0) {
        // La empresa puede ser emisor o receptor
        $where[] = "(
            (f.receptor_tipo = 'empresa' AND f.receptor_id = :empresa_id)
            OR (f.emisor_tipo = 'empresa' AND f.emisor_id = :empresa_id2)
        )";
        $params[':empresa_id'] = $empresaId;
        $params[':empresa_id2'] = $empresaId;
    }

    if ($fechaDesde !== '') {
        $where[] = "DATE(f.fecha_emision) >= :fecha_desde";
        $params[':fecha_desde'] = $fechaDesde;
    }

    if ($fechaHasta !== '') {
        $where[] = "DATE(f.fecha_emision) <= :fecha_hasta";
        $params[':fecha_hasta'] = $fechaHasta;
    }

    $whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

    // Contar total para paginación
    $countQuery = "SELECT COUNT(*) FROM facturas f $whereClause";
    $stmtCount = $db->prepare($countQuery);
    foreach ($params as $key => $value) {
        $stmtCount->bindValue($key, $value);
    }
    $stmtCount->execute();
    $total = intval($stmtCount->fetchColumn());

    // Obtener facturas
    $query = "SELECT f.*
              FROM facturas f
              $whereClause
              ORDER BY f.fecha_emision DESC
              LIMIT :limit OFFSET :offset";

    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $facturas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Agregar URL del PDF si existe
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $facturas = array_map(function ($f) use ($protocol, $host) {
        $montoNormalizado = floatval($f['total'] ?? $f['subtotal'] ?? 0);
        $f['monto'] = $montoNormalizado;

        if (!empty($f['pdf_ruta'])) {
            $f['pdf_url'] = "$protocol://$host/r2_proxy.php?key=" . urlencode($f['pdf_ruta']);
        } else {
            $f['pdf_url'] = null;
        }
        return $f;
    }, $facturas);

    // Resumen
    $stmtResumen = $db->prepare("SELECT
        COUNT(*) AS total_facturas,
        COALESCE(SUM(total), 0) AS total_facturado,
        COUNT(*) FILTER (WHERE estado = 'pagada') AS facturas_pagadas,
        COUNT(*) FILTER (WHERE estado = 'emitida') AS facturas_pendientes
        FROM facturas f $whereClause");
    foreach ($params as $key => $value) {
        $stmtResumen->bindValue($key, $value);
    }
    $stmtResumen->execute();
    $resumen = $stmtResumen->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => $facturas,
        'resumen' => $resumen,
        'paginacion' => [
            'total' => $total,
            'pagina' => $page,
            'por_pagina' => $limit,
            'total_paginas' => ceil($total / $limit),
        ],
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error del servidor: ' . $e->getMessage()]);
}
