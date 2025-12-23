<?php
// Script de prueba para el endpoint de conductores documentos
// Ejecutar desde: http://localhost/pingo/backend/admin/test_conductores.php

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/config/database.php';

echo "<h2>Test del endpoint de conductores documentos</h2>";

$admin_id = 1; // Usar tu ID de admin

echo "<h3>1. Verificando conexión a la base de datos...</h3>";
if ($conn) {
    echo "✅ Conexión exitosa<br>";
} else {
    echo "❌ Error de conexión<br>";
    die();
}

echo "<h3>2. Verificando que eres administrador...</h3>";
$stmt = $conn->prepare("SELECT tipo_usuario FROM usuarios WHERE id = ? AND tipo_usuario = 'administrador'");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    echo "✅ Usuario es administrador<br>";
} else {
    echo "❌ Usuario NO es administrador<br>";
    die();
}

echo "<h3>3. Consultando conductores...</h3>";
$sql = "SELECT 
            dc.*,
            u.nombre,
            u.apellido,
            u.email,
            u.telefono
        FROM detalles_conductor dc
        INNER JOIN usuarios u ON dc.usuario_id = u.id
        WHERE u.tipo_usuario = 'conductor'
        LIMIT 5";

$result = $conn->query($sql);

if ($result) {
    echo "✅ Query ejecutado exitosamente<br>";
    echo "Total de conductores encontrados: " . $result->num_rows . "<br>";
    
    if ($result->num_rows > 0) {
        echo "<h3>4. Datos de conductores:</h3>";
        echo "<pre>";
        while ($row = $result->fetch_assoc()) {
            print_r([
                'usuario_id' => $row['usuario_id'],
                'nombre' => $row['nombre'],
                'apellido' => $row['apellido'],
                'email' => $row['email'],
                'licencia' => $row['licencia_conduccion'],
                'placa' => $row['vehiculo_placa'],
                'estado_verificacion' => $row['estado_verificacion'],
            ]);
            echo "\n---\n";
        }
        echo "</pre>";
    } else {
        echo "<p style='color:orange'>⚠️ No hay conductores registrados en la base de datos</p>";
    }
} else {
    echo "❌ Error en la query: " . $conn->error . "<br>";
}

echo "<h3>5. Probando el endpoint directamente...</h3>";
$test_url = "http://localhost/pingo/backend/admin/get_conductores_documentos.php?admin_id=$admin_id&page=1&per_page=20";
echo "URL: <a href='$test_url' target='_blank'>$test_url</a><br>";

echo "<h3>Resultado del endpoint:</h3>";
echo "<iframe src='$test_url' width='100%' height='400' style='border:1px solid #ccc'></iframe>";
?>
