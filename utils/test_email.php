<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/Mailer.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    $conductor_id = 277;
    $empresa_id = 1;

    echo "=== TEST EMAIL SENDING ===\n";

    // Fetch conductor data
    $stmt = $db->prepare("
        SELECT u.email, u.nombre, u.apellido, dc.licencia_conduccion, dc.vehiculo_placa 
        FROM usuarios u
        LEFT JOIN detalles_conductor dc ON u.id = dc.usuario_id
        WHERE u.id = :id
    ");
    $stmt->execute([':id' => $conductor_id]);
    $conductorData = $stmt->fetch(PDO::FETCH_ASSOC);

    echo "Conductor Data:\n";
    print_r($conductorData);

    // Fetch company data with CORRECT alias
    $stmtEmp = $db->prepare("SELECT nombre AS nombre_empresa, logo_url FROM empresas_transporte WHERE id = :id");
    $stmtEmp->execute([':id' => $empresa_id]);
    $empresaData = $stmtEmp->fetch(PDO::FETCH_ASSOC);

    echo "Empresa Data:\n";
    print_r($empresaData);

    if ($conductorData && $empresaData) {
        $nombreCompleto = trim($conductorData['nombre'] . ' ' . $conductorData['apellido']);
        $documentos = [
            'licencia' => $conductorData['licencia_conduccion'] ?: 'Verificada',
            'placa' => $conductorData['vehiculo_placa'] ?: 'Verificada'
        ];

        echo "Sending email to: {$conductorData['email']}\n";
        echo "Name: $nombreCompleto\n";
        echo "Documentos: "; print_r($documentos);
        echo "Empresa: "; print_r($empresaData);

        $result = Mailer::sendConductorApprovedEmail(
            $conductorData['email'], 
            $nombreCompleto, 
            $documentos,
            $empresaData
        );

        echo "Email Result: " . ($result ? 'SUCCESS' : 'FAILED') . "\n";
    } else {
        echo "ERROR: Could not fetch conductor or empresa data\n";
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
?>
