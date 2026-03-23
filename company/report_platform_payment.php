<?php
/**
 * API: Empresa reporta pago de deuda con comprobante
 * Endpoint: POST company/report_platform_payment.php
 * 
 * Permite a la empresa subir un comprobante de transferencia
 * para reportar el pago de su deuda con la plataforma (admin).
 * Usa R2 para almacenar los comprobantes.
 * 
 * Flujo: Empresa sube comprobante → Admin revisa → Admin aprueba/rechaza → Admin confirma
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';
require_once '../config/R2Service.php';
require_once '../utils/NotificationHelper.php';
require_once '../utils/Mailer.php';
require_once '../utils/SensitiveDataCrypto.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

try {
    $empresaId = isset($_POST['empresa_id']) ? intval($_POST['empresa_id']) : 0;
    $userId = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    $monto = isset($_POST['monto']) ? floatval($_POST['monto']) : 0;
    $observaciones = trim($_POST['observaciones'] ?? '');

    if ($empresaId <= 0 || $monto <= 0) {
        throw new Exception('Empresa y monto son requeridos');
    }

    if (!isset($_FILES['comprobante'])) {
        throw new Exception('Debes adjuntar un comprobante de pago');
    }

    $file = $_FILES['comprobante'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new Exception('Error al procesar archivo de comprobante');
    }

    $database = new Database();
    $db = $database->getConnection();

    // Verificar que la empresa existe y tiene deuda
    $stmtEmpresa = $db->prepare("SELECT id, nombre, email, saldo_pendiente FROM empresas_transporte WHERE id = :id LIMIT 1");
    $stmtEmpresa->execute([':id' => $empresaId]);
    $empresa = $stmtEmpresa->fetch(PDO::FETCH_ASSOC);

    if (!$empresa) {
        throw new Exception('Empresa no encontrada');
    }

    // Verificar que no hay un reporte pendiente
    $stmtPending = $db->prepare("SELECT id FROM pagos_empresa_reportes
                                 WHERE empresa_id = :empresa_id
                                   AND estado IN ('pendiente_revision', 'comprobante_aprobado')
                                 ORDER BY created_at DESC LIMIT 1");
    $stmtPending->execute([':empresa_id' => $empresaId]);
    if ($stmtPending->fetch()) {
        throw new Exception('Ya tienes un comprobante en revisión. Espera respuesta del administrador.');
    }

    // Verificar que existe configuración bancaria del admin
    $stmtAdminBank = $db->prepare("SELECT banco_nombre, tipo_cuenta, numero_cuenta FROM admin_configuracion_banco LIMIT 1");
    $stmtAdminBank->execute();
    $adminBank = $stmtAdminBank->fetch(PDO::FETCH_ASSOC) ?: [];

    $adminNumeroCuentaPlano = decryptSensitiveData($adminBank['numero_cuenta'] ?? null);
    $hasAdminBank = !empty($adminBank['banco_nombre']) && !empty($adminNumeroCuentaPlano);

    // Validar archivo
    $allowedImageExt = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif', 'jfif'];
    $allowedPdfExt = ['pdf'];

    $originalName = $file['name'] ?? '';
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    $browserMime = strtolower(trim($file['type'] ?? 'application/octet-stream'));
    $detectedMime = null;
    if (!empty($file['tmp_name']) && function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detectedMime = strtolower(trim((string) finfo_file($finfo, $file['tmp_name'])));
            finfo_close($finfo);
        }
    }

    // Normalizar MIME
    $normalizeMime = static function (?string $mime): string {
        $mime = strtolower(trim((string) $mime));
        if ($mime === 'image/jpg' || $mime === 'image/pjpeg') return 'image/jpeg';
        if ($mime === 'image/x-png') return 'image/png';
        if ($mime === 'application/x-pdf') return 'application/pdf';
        return $mime;
    };

    $browserMime = $normalizeMime($browserMime);
    $detectedMime = $normalizeMime($detectedMime);

    $isImageByExt = in_array($ext, $allowedImageExt, true);
    $isPdfByExt = in_array($ext, $allowedPdfExt, true);
    $isImageByMime = str_starts_with($browserMime, 'image/') || str_starts_with((string) $detectedMime, 'image/');
    $isPdfByMime = $browserMime === 'application/pdf' || $detectedMime === 'application/pdf';

    if ((!$isImageByExt && !$isPdfByExt) && (!$isImageByMime && !$isPdfByMime)) {
        throw new Exception('Formato no permitido. Usa JPG, PNG, WEBP o PDF');
    }

    if (($isPdfByExt || $isPdfByMime) && !$isPdfByExt) {
        $ext = 'pdf';
    } elseif (($isImageByExt || $isImageByMime) && !$isImageByExt) {
        $ext = 'jpg';
    }

    $mime = $isPdfByExt ? 'application/pdf' : ($detectedMime ?: ($isPdfByMime ? 'application/pdf' : 'image/jpeg'));

    // Máximo 10MB
    $maxSize = 10 * 1024 * 1024;
    if (($file['size'] ?? 0) > $maxSize) {
        throw new Exception('El archivo supera 10MB');
    }

    if ($ext === '') {
        $ext = $mime === 'application/pdf' ? 'pdf' : 'jpg';
    }

    // Subir a R2 con ruta específica para pagos empresa→admin
    $filename = sprintf(
        'payments/empresa/%d/comprobante_%d.%s',
        $empresaId,
        time(),
        $ext
    );

    $r2 = new R2Service();
    $relativeUrl = $r2->uploadFile($file['tmp_name'], $filename, $mime);

    // Insertar reporte
    $stmtInsert = $db->prepare("INSERT INTO pagos_empresa_reportes
        (empresa_id, monto_reportado, estado, comprobante_ruta,
         banco_destino_nombre, numero_cuenta_destino, tipo_cuenta_destino,
         observaciones_empresa, created_at, updated_at)
        VALUES
        (:empresa_id, :monto, 'pendiente_revision', :comprobante_ruta,
         :banco_nombre, :numero_cuenta, :tipo_cuenta,
         :observaciones, NOW(), NOW())
        RETURNING id");

    $stmtInsert->execute([
        ':empresa_id' => $empresaId,
        ':monto' => $monto,
        ':comprobante_ruta' => $relativeUrl,
        ':banco_nombre' => $adminBank['banco_nombre'] ?? null,
        ':numero_cuenta' => $adminNumeroCuentaPlano ? encryptSensitiveData($adminNumeroCuentaPlano) : null,
        ':tipo_cuenta' => $adminBank['tipo_cuenta'] ?? null,
        ':observaciones' => $observaciones ?: null,
    ]);

    $reportId = intval($stmtInsert->fetchColumn());

    // Notificar a todos los administradores
    $stmtAdmins = $db->prepare("SELECT id FROM usuarios WHERE tipo_usuario IN ('admin', 'administrador')");
    $stmtAdmins->execute();
    $adminUsers = $stmtAdmins->fetchAll(PDO::FETCH_COLUMN);

    foreach ($adminUsers as $adminUserId) {
        NotificationHelper::crear(
            intval($adminUserId),
            'empresa_payment_submitted',
            'Nuevo comprobante de empresa',
            'La empresa ' . ($empresa['nombre'] ?? '') . ' envió un comprobante de pago por $' . number_format($monto, 0, ',', '.') . ' COP.',
            'pago_empresa_reporte',
            $reportId,
            ['reporte_id' => $reportId, 'empresa_id' => $empresaId, 'monto' => $monto]
        );
    }

    // Enviar email a administradores
    $stmtAdminEmails = $db->prepare("SELECT email, nombre FROM usuarios WHERE tipo_usuario IN ('admin', 'administrador') AND email IS NOT NULL AND email <> ''");
    $stmtAdminEmails->execute();
    $adminEmails = $stmtAdminEmails->fetchAll(PDO::FETCH_ASSOC);

    foreach ($adminEmails as $admin) {
        Mailer::sendEmail(
            $admin['email'],
            $admin['nombre'] ?? 'Administrador',
            'Nuevo comprobante de pago - ' . ($empresa['nombre'] ?? 'Empresa'),
            'La empresa ' . ($empresa['nombre'] ?? '') . ' ha reportado un pago de $' . number_format($monto, 0, ',', '.') . ' COP. Revisa el comprobante desde el panel de Comisiones Empresariales.'
        );
    }

    echo json_encode([
        'success' => true,
        'message' => 'Comprobante enviado correctamente',
        'reporte_id' => $reportId,
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
