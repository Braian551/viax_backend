<?php
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

try {
    $conductorId = isset($_POST['conductor_id']) ? intval($_POST['conductor_id']) : 0;
    $monto = isset($_POST['monto']) ? floatval($_POST['monto']) : 0;
    $observaciones = trim($_POST['observaciones'] ?? '');

    if ($conductorId <= 0 || $monto <= 0) {
        throw new Exception('Conductor y monto son requeridos');
    }

    if (!isset($_FILES['comprobante'])) {
        throw new Exception('Debes adjuntar un comprobante');
    }

    $file = $_FILES['comprobante'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new Exception('Error al procesar archivo de comprobante');
    }

    $database = new Database();
    $db = $database->getConnection();

    $stmtConductor = $db->prepare("SELECT id, nombre, apellido, email, empresa_id FROM usuarios WHERE id = :id AND tipo_usuario = 'conductor' LIMIT 1");
    $stmtConductor->execute([':id' => $conductorId]);
    $conductor = $stmtConductor->fetch(PDO::FETCH_ASSOC);

    if (!$conductor) {
        throw new Exception('Conductor no encontrado');
    }

    $empresaId = intval($conductor['empresa_id'] ?? 0);
    if ($empresaId <= 0) {
        throw new Exception('El conductor no está vinculado a empresa');
    }

    $stmtConfig = $db->prepare("SELECT banco_nombre, tipo_cuenta, numero_cuenta FROM empresas_configuracion WHERE empresa_id = :empresa_id LIMIT 1");
    $stmtConfig->execute([':empresa_id' => $empresaId]);
    $config = $stmtConfig->fetch(PDO::FETCH_ASSOC) ?: [];

    $hasTransferAccount = !empty($config['banco_nombre']) && !empty($config['tipo_cuenta']) && !empty($config['numero_cuenta']);
    if (!$hasTransferAccount) {
        throw new Exception('La empresa aún no ha configurado cuenta bancaria para transferencias');
    }

    $stmtPending = $db->prepare("SELECT id, estado FROM pagos_comision_reportes
                                 WHERE conductor_id = :conductor_id
                                   AND estado IN ('pendiente_revision', 'comprobante_aprobado')
                                 ORDER BY created_at DESC
                                 LIMIT 1");
    $stmtPending->execute([':conductor_id' => $conductorId]);
    if ($stmtPending->fetch()) {
        throw new Exception('Ya tienes un comprobante en revisión. Espera respuesta de la empresa.');
    }

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

    $normalizeMime = static function (?string $mime): string {
        $mime = strtolower(trim((string) $mime));
        if ($mime === 'image/jpg' || $mime === 'image/pjpeg') {
            return 'image/jpeg';
        }
        if ($mime === 'image/x-png') {
            return 'image/png';
        }
        if ($mime === 'application/x-pdf') {
            return 'application/pdf';
        }
        return $mime;
    };

    $browserMime = $normalizeMime($browserMime);
    $detectedMime = $normalizeMime($detectedMime);

    $isImageByExt = in_array($ext, $allowedImageExt, true);
    $isPdfByExt = in_array($ext, $allowedPdfExt, true);

    $isImageByMime = str_starts_with($browserMime, 'image/') || str_starts_with((string) $detectedMime, 'image/');
    $isPdfByMime = $browserMime === 'application/pdf' || $detectedMime === 'application/pdf';

    if ((!$isImageByExt && !$isPdfByExt) && (!$isImageByMime && !$isPdfByMime)) {
        throw new Exception('Formato no permitido. Usa JPG, JPEG, PNG, WEBP, HEIC, HEIF, JFIF o PDF');
    }

    if (($isPdfByExt || $isPdfByMime) && !$isPdfByExt) {
        $ext = 'pdf';
    } elseif (($isImageByExt || $isImageByMime) && !$isImageByExt) {
        $ext = 'jpg';
    }

    $mime = $isPdfByExt ? 'application/pdf' : ($detectedMime ?: ($isPdfByMime ? 'application/pdf' : 'image/jpeg'));

    $maxSize = 10 * 1024 * 1024;
    if (($file['size'] ?? 0) > $maxSize) {
        throw new Exception('El archivo supera 10MB');
    }

    if ($ext === '') {
        $ext = $mime === 'application/pdf' ? 'pdf' : 'jpg';
    }

    $filename = sprintf(
        'payments/debt/%d/%d/comprobante_%d.%s',
        $empresaId,
        $conductorId,
        time(),
        $ext
    );

    $r2 = new R2Service();
    $relativeUrl = $r2->uploadFile($file['tmp_name'], $filename, $mime);

    $stmtInsert = $db->prepare("INSERT INTO pagos_comision_reportes
        (conductor_id, empresa_id, monto_reportado, estado, comprobante_ruta,
         banco_destino_nombre, numero_cuenta_destino, tipo_cuenta_destino,
         observaciones_conductor, created_at, updated_at)
        VALUES
        (:conductor_id, :empresa_id, :monto_reportado, 'pendiente_revision', :comprobante_ruta,
         :banco_nombre, :numero_cuenta, :tipo_cuenta,
         :observaciones, NOW(), NOW())
        RETURNING id");

    $stmtInsert->execute([
        ':conductor_id' => $conductorId,
        ':empresa_id' => $empresaId,
        ':monto_reportado' => $monto,
        ':comprobante_ruta' => $relativeUrl,
        ':banco_nombre' => $config['banco_nombre'],
        ':numero_cuenta' => $config['numero_cuenta'],
        ':tipo_cuenta' => $config['tipo_cuenta'],
        ':observaciones' => $observaciones ?: null,
    ]);

    $reportId = intval($stmtInsert->fetchColumn());

    $stmtCompanyUsers = $db->prepare("SELECT id FROM usuarios WHERE empresa_id = :empresa_id AND tipo_usuario = 'empresa'");
    $stmtCompanyUsers->execute([':empresa_id' => $empresaId]);
    $companyUsers = $stmtCompanyUsers->fetchAll(PDO::FETCH_COLUMN);

    foreach ($companyUsers as $companyUserId) {
        NotificationHelper::crear(
            intval($companyUserId),
            'debt_payment_submitted',
            'Nuevo comprobante de deuda',
            trim(($conductor['nombre'] ?? '') . ' ' . ($conductor['apellido'] ?? '')) . ' envió un comprobante de pago de comisión.',
            'pago_comision_reporte',
            $reportId,
            ['reporte_id' => $reportId, 'conductor_id' => $conductorId, 'monto' => $monto]
        );
    }

    $stmtEmpresa = $db->prepare("SELECT nombre, email FROM empresas_transporte WHERE id = :id LIMIT 1");
    $stmtEmpresa->execute([':id' => $empresaId]);
    $empresa = $stmtEmpresa->fetch(PDO::FETCH_ASSOC) ?: [];

    if (!empty($empresa['email'])) {
        Mailer::sendEmail(
            $empresa['email'],
            $empresa['nombre'] ?? 'Empresa',
            'Nuevo comprobante de pago de comisión',
            'Un conductor ha reportado un pago de comisión. Revisa y decide si apruebas el comprobante desde el panel de Comisiones.'
        );
    }

    echo json_encode([
        'success' => true,
        'message' => 'Comprobante enviado correctamente. Queda pendiente de revisión por la empresa.',
        'data' => [
            'reporte_id' => $reportId,
            'estado' => 'pendiente_revision',
            'comprobante_ruta' => $relativeUrl,
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
