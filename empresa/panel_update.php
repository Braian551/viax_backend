<?php
// Included by the session-checked company panel dispatcher.

require_once __DIR__ . '/services/EmpresaService.php';

try {
    $input = str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data')
        ? $_POST
        : getJsonInput();
    if (!is_array($input) || !$input) {
        sendJsonResponse(false, 'Envía los datos de la empresa.', [], 400);
    }
    foreach (['empresa_id', 'id'] as $field) {
        if (isset($input[$field]) && filter_var($input[$field], FILTER_VALIDATE_INT) !== $companyId) {
            sendJsonResponse(false, 'Acceso denegado.', [], 403);
        }
    }

    $service = new EmpresaService($db);
    if ($route === 'empresa/profile') {
        unset($input['logo_url']);
        if (isset($_FILES['logo'])) {
            $logo = $_FILES['logo'];
            $types = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
            $mime = $logo['error'] === UPLOAD_ERR_OK && $logo['size'] <= 4 * 1024 * 1024
                ? (new finfo(FILEINFO_MIME_TYPE))->file($logo['tmp_name']) : false;
            if (!$mime || !isset($types[$mime])) {
                sendJsonResponse(false, 'El logo debe ser PNG, JPG o WebP de hasta 4 MB.', [], 400);
            }
            $_FILES['logo']['name'] = 'logo.' . $types[$mime];
            $_FILES['logo']['type'] = $mime;
        }
        $result = $service->updateCompanyProfile($companyId, $input);
        sendJsonResponse(true, 'Perfil actualizado.', $result);
    }

    $municipios = $input['municipios_operacion'] ?? null;
    if ($municipios !== null && (!is_array($municipios) || !array_is_list($municipios) || count($municipios) > 100)) {
        sendJsonResponse(false, 'Municipios de operación inválidos.', [], 400);
    }
    $normalized = [];
    foreach ($municipios ?? [] as $location) {
        $department = is_array($location) && is_string($location['departamento'] ?? null) ? trim($location['departamento']) : '';
        $town = is_array($location) && is_string($location['municipio'] ?? null) ? trim($location['municipio']) : '';
        if ($department === '' || $town === '' || mb_strlen($department) > 100 || mb_strlen($town) > 100) {
            sendJsonResponse(false, 'Municipio o departamento inválido.', [], 400);
        }
        $key = mb_strtolower($department) . '|' . mb_strtolower($town);
        $principal = filter_var($location['es_principal'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $normalized[$key] = [$companyId, $department, $town, ($principal || ($normalized[$key][3] ?? 'false') === 'true') ? 'true' : 'false'];
    }

    $db->beginTransaction();
    $lock = $db->prepare('SELECT id FROM empresas_transporte WHERE id = ? FOR UPDATE');
    $lock->execute([$companyId]);
    if (!$lock->fetchColumn()) {
        throw new DomainException('Empresa no encontrada.');
    }
    if ($municipios !== null) {
        $db->prepare('DELETE FROM empresa_municipios_operacion WHERE empresa_id = ?')->execute([$companyId]);
        $insert = $db->prepare(
            'INSERT INTO empresa_municipios_operacion (empresa_id, departamento, municipio, es_principal) VALUES (?, ?, ?, ?)'
        );
        foreach ($normalized as $location) $insert->execute($location);
    }
    $result = $service->updateCompanySettings($companyId, $input);
    $db->commit();
    sendJsonResponse(true, 'Configuración actualizada.', $result);
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('VIAX company profile write: ' . get_class($error));
    sendJsonResponse(false, 'No pudimos guardar los datos de la empresa. Verifica los campos e intenta nuevamente.', [], 422);
}

