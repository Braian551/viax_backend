<?php
require_once __DIR__ . '/config/database.php';

$db = (new Database())->getConnection();
$query = "SELECT id, nombre, logo_url FROM empresas_transporte WHERE estado = 'activo' ORDER BY nombre";
$stmt = $db->query($query);
$empresas = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "=== URLs de logos en BD ===\n\n";
foreach ($empresas as $emp) {
    echo "ID: " . $emp['id'] . " - " . $emp['nombre'] . "\n";
    echo "logo_url: " . ($emp['logo_url'] ?? 'NULL') . "\n\n";
}
