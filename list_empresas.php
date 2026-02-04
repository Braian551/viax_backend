<?php
require_once 'config/config.php';
$db = (new Database())->getConnection();
$stmt = $db->query('SELECT id, nombre, estado FROM empresas_transporte ORDER BY id');
foreach ($stmt as $row) {
    echo "{$row['id']} - {$row['nombre']} ({$row['estado']})\n";
}
