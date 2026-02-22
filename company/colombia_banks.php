<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

function fallbackBanks() {
    return [
        ['codigo' => 'BANCOLOMBIA', 'nombre' => 'Bancolombia'],
        ['codigo' => 'NEQUI', 'nombre' => 'Nequi'],
        ['codigo' => 'DAVIVIENDA', 'nombre' => 'Davivienda'],
        ['codigo' => 'DAVIPLATA', 'nombre' => 'Daviplata'],
        ['codigo' => 'BBVA', 'nombre' => 'BBVA Colombia'],
        ['codigo' => 'BANCO_BOGOTA', 'nombre' => 'Banco de Bogotá'],
        ['codigo' => 'BANCO_OCCIDENTE', 'nombre' => 'Banco de Occidente'],
        ['codigo' => 'BANCO_POPULAR', 'nombre' => 'Banco Popular'],
        ['codigo' => 'BANCO_AV_VILLAS', 'nombre' => 'Banco AV Villas'],
        ['codigo' => 'BANCO_CAJA_SOCIAL', 'nombre' => 'Banco Caja Social'],
        ['codigo' => 'BANCO_AGRARIO', 'nombre' => 'Banco Agrario'],
        ['codigo' => 'SCOTIABANK_COLPATRIA', 'nombre' => 'Scotiabank Colpatria'],
        ['codigo' => 'BANCO_FALABELLA', 'nombre' => 'Banco Falabella'],
        ['codigo' => 'BANCO_PICHINCHA', 'nombre' => 'Banco Pichincha'],
        ['codigo' => 'BANCOLOMBIA_AHORROS_A_LA_MANO', 'nombre' => 'Bancolombia A la Mano'],
    ];
}

function normalizeBanks($items) {
    $mapped = [];

    foreach ($items as $item) {
        $name = $item['name'] ?? $item['nombre'] ?? $item['bankName'] ?? null;
        $code = $item['id'] ?? $item['codigo'] ?? null;

        if (!$name) {
            continue;
        }

        $codigo = $code ? strtoupper(preg_replace('/[^A-Za-z0-9_]+/', '_', (string)$code)) : strtoupper(preg_replace('/[^A-Za-z0-9_]+/', '_', $name));
        $mapped[] = [
            'codigo' => $codigo,
            'nombre' => trim((string)$name),
        ];
    }

    usort($mapped, function($a, $b) {
        return strcmp($a['nombre'], $b['nombre']);
    });

    return array_values(array_unique($mapped, SORT_REGULAR));
}

$apiCandidates = [
    'https://api-colombia.com/api/v1/bank',
    'https://api-colombia.com/api/v1/Bank',
];

foreach ($apiCandidates as $url) {
    try {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300 && $body) {
            $decoded = json_decode($body, true);
            if (is_array($decoded) && !empty($decoded)) {
                $banks = normalizeBanks($decoded);
                if (!empty($banks)) {
                    echo json_encode(['success' => true, 'data' => $banks, 'source' => 'api_colombia']);
                    exit();
                }
            }
        }
    } catch (Throwable $e) {
        error_log('colombia_banks.php API error: ' . $e->getMessage());
    }
}

echo json_encode([
    'success' => true,
    'data' => fallbackBanks(),
    'source' => 'fallback',
]);
