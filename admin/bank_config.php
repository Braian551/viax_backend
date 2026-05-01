<?php
/**
 * API: Configuración de cuenta bancaria del administrador
 * Endpoint: GET/POST admin/bank_config.php
 * 
 * GET: Obtener configuración actual de cuenta bancaria
 * POST: Actualizar configuración de cuenta bancaria
 * 
 * Esta cuenta es la que las empresas usarán para hacer transferencias.
 */

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=UTF-8');
$viaxOrigin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
$viaxAllowedOrigins = ['https://viaxcol.online', 'https://www.viaxcol.online'];
if ($viaxOrigin !== '' && in_array($viaxOrigin, $viaxAllowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $viaxOrigin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/SensitiveDataCrypto.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    // ─── GET: Obtener configuración ───
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $adminId = isset($_GET['admin_id']) ? intval($_GET['admin_id']) : 0;

        $stmt = $db->prepare("SELECT * FROM admin_configuracion_banco WHERE admin_id = :admin_id LIMIT 1");
        $stmt->execute([':admin_id' => $adminId > 0 ? $adminId : 0]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);

        // Si no hay registro para ese admin, buscar cualquiera
        if (!$config) {
            $stmt = $db->query("SELECT * FROM admin_configuracion_banco LIMIT 1");
            $config = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        $metodoRecaudo = 'cuenta_bancaria';
        if ($config) {
            $numeroCuentaPlano = decryptSensitiveData($config['numero_cuenta'] ?? null);
            $tipoCuenta = strtolower(trim((string)($config['tipo_cuenta'] ?? '')));
            $bancoNombre = strtolower(trim((string)($config['banco_nombre'] ?? '')));
            if ($tipoCuenta === 'nequi' || $bancoNombre === 'nequi') {
                $metodoRecaudo = 'nequi';
            }
            $config['numero_cuenta'] = $numeroCuentaPlano;
            $config['numero_cuenta_masked'] = maskSensitiveAccount($numeroCuentaPlano);
            $config['metodo_recaudo'] = $metodoRecaudo;
            $config['configurada'] = true;
        }

        echo json_encode([
            'success' => true,
            'data' => $config ?: [
                'configurada' => false,
                'metodo_recaudo' => 'cuenta_bancaria',
                'banco_nombre' => null,
                'tipo_cuenta' => null,
                'numero_cuenta' => null,
                'titular_cuenta' => null,
                'documento_titular' => null,
            ],
        ]);
        exit();
    }

    // ─── POST: Actualizar configuración ───
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método no permitido');
    }

    $input = json_decode(file_get_contents('php://input'), true);

    $adminId = intval($input['admin_id'] ?? 0);
    $bancoCodigo = trim($input['banco_codigo'] ?? '');
    $bancoNombre = trim($input['banco_nombre'] ?? '');
    $tipoCuenta = trim($input['tipo_cuenta'] ?? '');
    $numeroCuenta = trim($input['numero_cuenta'] ?? '');
    $titularCuenta = trim($input['titular_cuenta'] ?? '');
    $documentoTitular = trim($input['documento_titular'] ?? '');
    $referencia = trim($input['referencia_transferencia'] ?? '');
    $metodoRecaudo = trim($input['metodo_recaudo'] ?? 'cuenta_bancaria');

    if ($adminId <= 0) {
        throw new Exception('admin_id es requerido');
    }

    if (!in_array($metodoRecaudo, ['cuenta_bancaria', 'nequi'], true)) {
        $metodoRecaudo = 'cuenta_bancaria';
    }

    // Compatibilidad: si llega tipo_cuenta=banco, normalizar.
    if (strtolower($tipoCuenta) === 'banco') {
        $tipoCuenta = '';
    }

    if ($metodoRecaudo === 'nequi') {
        if ($numeroCuenta === '' || $titularCuenta === '') {
            throw new Exception('Número de Nequi y titular son requeridos');
        }
        if ($bancoNombre === '') {
            $bancoNombre = 'Nequi';
        }
        if ($tipoCuenta === '') {
            $tipoCuenta = 'nequi';
        }
    } else {
        if ($bancoNombre === '' || $numeroCuenta === '' || $titularCuenta === '') {
            throw new Exception('Banco, número de cuenta y titular son requeridos');
        }
        if ($tipoCuenta === '') {
            throw new Exception('Tipo de cuenta es requerido');
        }
    }

    // Upsert
    $stmt = $db->prepare("INSERT INTO admin_configuracion_banco
        (admin_id, banco_codigo, banco_nombre, tipo_cuenta, numero_cuenta,
         titular_cuenta, documento_titular, referencia_transferencia, actualizado_en)
        VALUES
        (:admin_id, :banco_codigo, :banco_nombre, :tipo_cuenta, :numero_cuenta,
         :titular_cuenta, :documento_titular, :referencia, NOW())
        ON CONFLICT (admin_id) DO UPDATE SET
            banco_codigo = EXCLUDED.banco_codigo,
            banco_nombre = EXCLUDED.banco_nombre,
            tipo_cuenta = EXCLUDED.tipo_cuenta,
            numero_cuenta = EXCLUDED.numero_cuenta,
            titular_cuenta = EXCLUDED.titular_cuenta,
            documento_titular = EXCLUDED.documento_titular,
            referencia_transferencia = EXCLUDED.referencia_transferencia,
            actualizado_en = NOW()");

    $stmt->execute([
        ':admin_id' => $adminId,
        ':banco_codigo' => $bancoCodigo ?: null,
        ':banco_nombre' => $bancoNombre,
        ':tipo_cuenta' => $tipoCuenta,
        ':numero_cuenta' => encryptSensitiveData($numeroCuenta),
        ':titular_cuenta' => $titularCuenta,
        ':documento_titular' => $documentoTitular ?: null,
        ':referencia' => $referencia ?: null,
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Configuración bancaria actualizada correctamente',
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
