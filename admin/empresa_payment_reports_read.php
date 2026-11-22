<?php
/** Consulta de comprobantes de pago para administración. */

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=UTF-8');
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

require_once __DIR__ . '/../middleware/SessionAccess.php';
require_once __DIR__ . '/../utils/SensitiveDataCrypto.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    if (Request::method() !== 'GET') {
        header('Allow: GET');
        sendJsonResponse(false, 'Operación financiera no disponible.', [], 405);
    }
    SessionAccess::requireAdmin($db);

    // ─── GET: Listar reportes de empresa ───
    $empresaId = isset($_GET['empresa_id']) ? intval($_GET['empresa_id']) : 0;
    $estado = trim($_GET['estado'] ?? '');
    $limit = min(max(intval($_GET['limit'] ?? 25), 1), 100);

    $where = [];
    $params = [];

    if ($empresaId > 0) {
        $where[] = "r.empresa_id = :empresa_id";
        $params[':empresa_id'] = $empresaId;
    }

    if ($estado !== '') {
        $where[] = "r.estado = :estado";
        $params[':estado'] = $estado;
    }

    $whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

    $query = "SELECT r.*, et.nombre AS empresa_nombre, et.email AS empresa_email, et.logo_url
              FROM pagos_empresa_reportes r
              INNER JOIN empresas_transporte et ON et.id = r.empresa_id
              $whereClause
              ORDER BY r.created_at DESC
              LIMIT :limit";

    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $rows = array_map(function ($row) {
        $row['comprobante_url'] = !empty($row['comprobante_ruta'])
            ? '/api/admin/financial_document.php?tipo=comprobante&id=' . (int)$row['id']
            : null;
        $row['comprobante_es_pdf'] = str_ends_with(strtolower((string)$row['comprobante_ruta']), '.pdf');
        unset($row['comprobante_ruta']);
        $numeroPlano = decryptSensitiveData($row['numero_cuenta_destino'] ?? null);
        $row['numero_cuenta_destino_masked'] = maskSensitiveAccount($numeroPlano);
        $row['numero_cuenta_destino'] = $row['numero_cuenta_destino_masked'];
        return $row;
    }, $rows);

    // Resumen de estados
    $stmtResumen = $db->query("SELECT
        COUNT(*) FILTER (WHERE estado = 'pendiente_revision') AS pendientes,
        COUNT(*) FILTER (WHERE estado = 'comprobante_aprobado') AS aprobados,
        COUNT(*) FILTER (WHERE estado = 'pagado_confirmado') AS confirmados,
        COUNT(*) FILTER (WHERE estado = 'rechazado') AS rechazados,
        COALESCE(SUM(monto_reportado) FILTER (WHERE estado = 'pendiente_revision'), 0) AS monto_pendiente,
        COALESCE(SUM(monto_reportado) FILTER (WHERE estado = 'pagado_confirmado'), 0) AS monto_confirmado
        FROM pagos_empresa_reportes");
    $resumen = $stmtResumen->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => $rows,
        'resumen' => $resumen,
    ]);

} catch (Exception $e) {
    sendJsonResponse(false, 'No se pudo cargar la información de pagos.', [], 500);
}
