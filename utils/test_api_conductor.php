<?php
// Mock $_GET and $_SERVER
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['empresa_id'] = 1;
$_GET['user_id'] = 1; 
$_GET['search'] = 'Oscar';

// Buffer output
ob_start();
require_once __DIR__ . '/../company/conductores_documentos.php';
$output = ob_get_clean();

$data = json_decode($output, true);
if ($data && $data['success']) {
    echo "API SUCCESS\n";
    foreach ($data['conductores'] as $c) {
        echo "ID: {$c['usuario_id']} | Name: {$c['nombre_completo']} | VerifStat: {$c['estado_verificacion']} | SolStat: {$c['estado_solicitud']} | esSolPend: " . ($c['es_solicitud_pendiente'] ? 'YES' : 'NO') . "\n";
    }
} else {
    echo "API ERROR: " . ($data['message'] ?? 'Unknown error') . "\n";
    echo "RAW: " . substr($output, 0, 500) . "\n";
}
?>
