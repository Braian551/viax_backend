<?php

require_once __DIR__ . '/../middleware/SessionAccess.php';
require_once __DIR__ . '/../config/R2Service.php';

try {
    $db = (new Database())->getConnection();
    SessionAccess::requireAdmin($db);
    if (Request::method() !== 'GET') {
        header('Allow: GET');
        sendJsonResponse(false, 'Método no permitido.', [], 405);
    }

    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $kind = $_GET['tipo'] ?? '';
    $sql = match ($kind) {
        'factura' => 'SELECT pdf_ruta FROM facturas WHERE id = ?',
        'comprobante' => 'SELECT comprobante_ruta FROM pagos_empresa_reportes WHERE id = ?',
        default => null,
    };
    if (!$id || $sql === null) {
        sendJsonResponse(false, 'Documento no válido.', [], 400);
    }
    $stmt = $db->prepare($sql);
    $stmt->execute([$id]);
    $key = $stmt->fetchColumn();
    if (!is_string($key) || !preg_match('~^[A-Za-z0-9_./-]{1,512}$~D', $key)
        || str_starts_with($key, '/') || in_array('..', explode('/', $key), true)) {
        sendJsonResponse(false, 'Documento no disponible.', [], 404);
    }
    $file = (new R2Service())->getFile($key);
    if (!$file || empty($file['content'])) {
        sendJsonResponse(false, 'Documento no disponible.', [], 404);
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($file['content']);
    $allowed = $kind === 'factura' ? ['application/pdf'] : ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($mime, $allowed, true)) {
        sendJsonResponse(false, 'Documento no disponible.', [], 404);
    }
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline');
    echo $file['content'];
} catch (Throwable $error) {
    error_log('VIAX financial document: ' . get_class($error));
    sendJsonResponse(false, 'No se pudo cargar el documento.', [], 503);
}
