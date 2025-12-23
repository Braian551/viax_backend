<?php
$db = new PDO('pgsql:host=localhost;port=5432;dbname=viax', 'postgres', 'root');

$stmt = $db->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'detalles_conductor' AND column_name IN ('disponible', 'estado_verificacion')");
$result = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Columnas de detalles_conductor:\n";
print_r($result);
