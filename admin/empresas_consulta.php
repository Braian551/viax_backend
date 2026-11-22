<?php

require_once __DIR__ . '/../middleware/SessionAccess.php';

try {
    $db = (new Database())->getConnection();
    SessionAccess::requireAdmin($db);
    if (Request::method() !== 'GET' || ($_GET['action'] ?? 'list') !== 'list') {
        header('Allow: GET');
        sendJsonResponse(false, 'La gestión de empresas está disponible en modo consulta.', [], 405);
    }
    $page = max(1, min(10000, (int)($_GET['page'] ?? 1)));
    $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
    $params = [];
    $filters = [];
    if (is_string($_GET['estado'] ?? null) && $_GET['estado'] !== '') {
        $filters[] = 'estado = ?';
        $params[] = $_GET['estado'];
    }
    if (is_string($_GET['search'] ?? null) && trim($_GET['search']) !== '') {
        $filters[] = '(nombre ILIKE ? OR nit ILIKE ? OR razon_social ILIKE ?)';
        $term = '%' . mb_substr(trim($_GET['search']), 0, 100) . '%';
        array_push($params, $term, $term, $term);
    }
    $where = $filters ? ' WHERE ' . implode(' AND ', $filters) : '';
    $totalQuery = $db->prepare('SELECT COUNT(*) FROM empresas_transporte' . $where);
    $totalQuery->execute($params);
    $total = (int)$totalQuery->fetchColumn();
    $offset = ($page - 1) * $limit;
    $rows = $db->prepare(
        "SELECT id, nombre, nit, razon_social, municipio, departamento, telefono,
                representante_nombre, logo_url, estado
           FROM empresas_transporte $where
          ORDER BY creado_en DESC, id DESC LIMIT $limit OFFSET $offset"
    );
    $rows->execute($params);
    $companies = $rows->fetchAll(PDO::FETCH_ASSOC);
    foreach ($companies as &$company) {
        $logo = $company['logo_url'];
        if (is_string($logo) && $logo !== '' && !str_starts_with($logo, 'https://')) {
            $company['logo_url'] = '/api/r2_proxy.php?key=' . rawurlencode($logo);
        }
    }
    unset($company);
    echo json_encode([
        'success' => true,
        'empresas' => $companies,
        'can_manage' => false,
        'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'total_pages' => (int)ceil($total / $limit)],
    ]);
} catch (Throwable $error) {
    error_log('VIAX company listing: ' . get_class($error));
    sendJsonResponse(false, 'No se pudieron cargar las empresas.', [], 500);
}
