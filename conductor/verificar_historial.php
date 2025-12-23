<?php
/**
 * Script de Verificación: Historial de Viajes del Conductor
 * 
 * Este script verifica que el endpoint get_historial.php funcione correctamente
 */

echo "=================================================\n";
echo "   VERIFICACIÓN DE ENDPOINT: get_historial.php   \n";
echo "=================================================\n\n";

// Configuración
// LOCAL: 'http://localhost/ping_go/backend-deploy/conductor'
// PRODUCCIÓN: 'https://pinggo-backend-production.up.railway.app/conductor'
$base_url = 'http://localhost/ping_go/backend-deploy/conductor';
$conductor_id = 7;
$page = 1;
$limit = 20;

// Construir URL
$url = "$base_url/get_historial.php?conductor_id=$conductor_id&page=$page&limit=$limit";

echo "🔗 URL: $url\n\n";
echo "📡 Realizando petición...\n";

// Hacer petición
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json'
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "📊 Código HTTP: $http_code\n\n";

// Decodificar respuesta
$data = json_decode($response, true);

if ($http_code === 200) {
    echo "✅ ÉXITO: El endpoint funciona correctamente\n\n";
    
    if ($data['success']) {
        $total_viajes = count($data['viajes']);
        echo "📋 Resultados:\n";
        echo "   - Total de viajes: {$data['pagination']['total']}\n";
        echo "   - Viajes en esta página: $total_viajes\n";
        echo "   - Página: {$data['pagination']['page']}\n";
        echo "   - Total de páginas: {$data['pagination']['total_pages']}\n\n";
        
        if ($total_viajes > 0) {
            echo "🚗 Viajes encontrados:\n";
            foreach ($data['viajes'] as $index => $viaje) {
                $num = $index + 1;
                echo "\n   Viaje #$num:\n";
                echo "   - ID: {$viaje['id']}\n";
                echo "   - Cliente: {$viaje['cliente_nombre']} {$viaje['cliente_apellido']}\n";
                echo "   - Estado: {$viaje['estado']}\n";
                echo "   - Origen: {$viaje['origen']}\n";
                echo "   - Destino: {$viaje['destino']}\n";
                echo "   - Distancia: {$viaje['distancia_km']} km\n";
                echo "   - Duración: {$viaje['duracion_estimada']} min\n";
                if ($viaje['calificacion']) {
                    echo "   - Calificación: {$viaje['calificacion']}/5 ⭐\n";
                    if ($viaje['comentario']) {
                        echo "   - Comentario: {$viaje['comentario']}\n";
                    }
                }
                echo "   - Ganancia: \${$viaje['precio_final']}\n";
            }
        } else {
            echo "ℹ️  No hay viajes registrados para este conductor\n";
        }
    } else {
        echo "⚠️  La respuesta indica un problema:\n";
        echo "   Mensaje: {$data['message']}\n";
    }
} else {
    echo "❌ ERROR: El servidor respondió con código $http_code\n";
    echo "Respuesta: $response\n";
}

echo "\n=================================================\n";
echo "   Verificación completada\n";
echo "=================================================\n";
