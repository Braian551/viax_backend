<?php
/**
 * API: Configuracion fiscal del emisor principal
 * Endpoint: GET/POST admin/emisor_profile.php
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

const EMISOR_PRINCIPAL_EMAIL = 'braianoquen@gmail.com';

try {
    $database = new Database();
    $db = $database->getConnection();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $adminId = isset($_GET['admin_id']) ? intval($_GET['admin_id']) : 0;

        $sql = "SELECT aef.*, u.nombre, u.apellido, u.email AS user_email
                FROM admin_emisor_fiscal aef
                INNER JOIN usuarios u ON u.id = aef.admin_id
                WHERE aef.admin_id = :admin_id
                LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([':admin_id' => $adminId > 0 ? $adminId : 0]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$config) {
            $stmt = $db->prepare("SELECT aef.*, u.nombre, u.apellido, u.email AS user_email
                                  FROM admin_emisor_fiscal aef
                                  INNER JOIN usuarios u ON u.id = aef.admin_id
                                  WHERE LOWER(aef.email_emisor) = LOWER(:email)
                                  LIMIT 1");
            $stmt->execute([':email' => EMISOR_PRINCIPAL_EMAIL]);
            $config = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$config) {
            $stmt = $db->query("SELECT u.id AS admin_id,
                                       u.nombre,
                                       u.apellido,
                                       u.email AS user_email
                                FROM usuarios u
                                WHERE u.tipo_usuario IN ('admin', 'administrador')
                                ORDER BY (LOWER(u.email) = LOWER('" . EMISOR_PRINCIPAL_EMAIL . "')) DESC, u.id ASC
                                LIMIT 1");
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);

            $config = [
                'admin_id' => intval($admin['admin_id'] ?? 0),
                'email_emisor' => EMISOR_PRINCIPAL_EMAIL,
                'nombre_legal' => trim((($admin['nombre'] ?? '') . ' ' . ($admin['apellido'] ?? ''))),
                'tipo_documento' => 'cedula_ciudadania',
                'numero_documento' => '',
                'regimen_fiscal' => 'Responsable de IVA',
                'direccion_fiscal' => '',
                'ciudad' => 'Bogota D.C.',
                'departamento' => '',
                'pais' => 'Colombia',
                'telefono' => '',
                'user_email' => $admin['user_email'] ?? EMISOR_PRINCIPAL_EMAIL,
            ];
        }

        $config['email_emisor'] = EMISOR_PRINCIPAL_EMAIL;

        echo json_encode([
            'success' => true,
            'data' => $config,
        ]);
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Metodo no permitido');
    }

    $input = json_decode(file_get_contents('php://input'), true);

    $adminId = intval($input['admin_id'] ?? 0);
    $nombreLegal = trim($input['nombre_legal'] ?? '');
    $tipoDocumento = trim($input['tipo_documento'] ?? 'cedula_ciudadania');
    $numeroDocumento = trim($input['numero_documento'] ?? '');
    $regimenFiscal = trim($input['regimen_fiscal'] ?? 'Responsable de IVA');
    $direccionFiscal = trim($input['direccion_fiscal'] ?? '');
    $ciudad = trim($input['ciudad'] ?? '');
    $departamento = trim($input['departamento'] ?? '');
    $pais = trim($input['pais'] ?? 'Colombia');
    $telefono = trim($input['telefono'] ?? '');

    if ($adminId <= 0) {
        throw new Exception('admin_id es requerido');
    }
    if ($nombreLegal === '') {
        throw new Exception('nombre_legal es requerido');
    }
    if ($numeroDocumento === '') {
        throw new Exception('numero_documento es requerido');
    }

    $validTipoDocumento = [
        'cedula_ciudadania',
        'nit',
        'cedula_extranjeria',
        'pasaporte',
        'otro'
    ];

    if (!in_array($tipoDocumento, $validTipoDocumento, true)) {
        $tipoDocumento = 'cedula_ciudadania';
    }

    $stmtAdmin = $db->prepare("SELECT id FROM usuarios WHERE id = :id AND tipo_usuario IN ('admin', 'administrador') LIMIT 1");
    $stmtAdmin->execute([':id' => $adminId]);
    if (!$stmtAdmin->fetch(PDO::FETCH_ASSOC)) {
        throw new Exception('El admin_id no corresponde a un administrador valido');
    }

    $stmt = $db->prepare("INSERT INTO admin_emisor_fiscal
        (admin_id, email_emisor, nombre_legal, tipo_documento, numero_documento,
         regimen_fiscal, direccion_fiscal, ciudad, departamento, pais, telefono, actualizado_en)
        VALUES
        (:admin_id, :email_emisor, :nombre_legal, :tipo_documento, :numero_documento,
         :regimen_fiscal, :direccion_fiscal, :ciudad, :departamento, :pais, :telefono, NOW())
        ON CONFLICT (admin_id) DO UPDATE SET
            email_emisor = EXCLUDED.email_emisor,
            nombre_legal = EXCLUDED.nombre_legal,
            tipo_documento = EXCLUDED.tipo_documento,
            numero_documento = EXCLUDED.numero_documento,
            regimen_fiscal = EXCLUDED.regimen_fiscal,
            direccion_fiscal = EXCLUDED.direccion_fiscal,
            ciudad = EXCLUDED.ciudad,
            departamento = EXCLUDED.departamento,
            pais = EXCLUDED.pais,
            telefono = EXCLUDED.telefono,
            actualizado_en = NOW()");

    $stmt->execute([
        ':admin_id' => $adminId,
        ':email_emisor' => EMISOR_PRINCIPAL_EMAIL,
        ':nombre_legal' => $nombreLegal,
        ':tipo_documento' => $tipoDocumento,
        ':numero_documento' => $numeroDocumento,
        ':regimen_fiscal' => $regimenFiscal ?: null,
        ':direccion_fiscal' => $direccionFiscal ?: null,
        ':ciudad' => $ciudad ?: null,
        ':departamento' => $departamento ?: null,
        ':pais' => $pais ?: 'Colombia',
        ':telefono' => $telefono ?: null,
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Perfil fiscal del emisor actualizado correctamente',
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
