<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

$catalog = require __DIR__ . '/data/vehicle_catalog_co.php';

$action = isset($_GET['action']) ? trim((string)$_GET['action']) : 'brands';
$vehicleType = isset($_GET['vehicle_type']) ? normalizeType((string)$_GET['vehicle_type']) : 'moto';
$brand = isset($_GET['brand']) ? normalizeText((string)$_GET['brand']) : '';
$year = isset($_GET['year']) ? intval($_GET['year']) : 0;
$query = isset($_GET['q']) ? normalizeText((string)$_GET['q']) : '';

if (!isset($catalog[$vehicleType])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Tipo de vehículo no soportado',
        'supported' => array_keys($catalog),
    ]);
    exit();
}

try {
    if ($action === 'brands') {
        $items = getBrands($catalog, $vehicleType);
        echo json_encode([
            'success' => true,
            'message' => 'Marcas obtenidas',
            'data' => formatOptions($items),
        ]);
        exit();
    }

    if ($action === 'models') {
        if ($brand === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Parámetro brand requerido']);
            exit();
        }

        $items = getModels($catalog, $vehicleType, $brand, $year, $query);
        echo json_encode([
            'success' => true,
            'message' => 'Modelos obtenidos',
            'data' => formatOptions($items),
        ]);
        exit();
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Acción no soportada. Use brands o models']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error obteniendo catálogo de vehículos',
        'error' => $e->getMessage(),
    ]);
}

function getBrands(array $catalog, string $vehicleType): array
{
    $localBrands = $catalog[$vehicleType]['brands'] ?? [];
    return uniqueSorted($localBrands);
}

function getModels(array $catalog, string $vehicleType, string $brand, int $year, string $query = ''): array
{
    $localModels = [];
    if (isset($catalog[$vehicleType]['models'][$brand]) && is_array($catalog[$vehicleType]['models'][$brand])) {
        $localModels = $catalog[$vehicleType]['models'][$brand];
    }

    if (!in_array($vehicleType, ['carro', 'taxi', 'moto'], true)) {
        $base = uniqueSorted($localModels);
        if ($query !== '') {
            $base = filterAndRankModels($base, $query, $brand);
        }
        return array_slice($base, 0, 180);
    }

    $apiVehicleType = mapVehicleTypeForVpic($vehicleType);
    $remoteModels = [];

    if ($year > 0) {
        $url = 'https://vpic.nhtsa.dot.gov/api/vehicles/GetModelsForMakeYear/make/' . rawurlencode($brand)
            . '/modelyear/' . $year
            . '/vehicleType/' . rawurlencode($apiVehicleType)
            . '?format=json';
        $remote = fetchJson($url);
        if (is_array($remote) && isset($remote['Results']) && is_array($remote['Results'])) {
            foreach ($remote['Results'] as $row) {
                if (!is_array($row) || empty($row['Model_Name'])) {
                    continue;
                }
                $remoteModels[] = normalizeText((string)$row['Model_Name']);
            }
        }
    }

    if (empty($remoteModels)) {
        $url = 'https://vpic.nhtsa.dot.gov/api/vehicles/GetModelsForMake/' . rawurlencode($brand) . '?format=json';
        $remote = fetchJson($url);
        if (is_array($remote) && isset($remote['Results']) && is_array($remote['Results'])) {
            foreach ($remote['Results'] as $row) {
                if (!is_array($row) || empty($row['Model_Name'])) {
                    continue;
                }
                $remoteModels[] = normalizeText((string)$row['Model_Name']);
            }
        }
    }

    $merged = uniqueSorted(array_merge($localModels, $remoteModels));
    if ($query !== '') {
        $merged = filterAndRankModels($merged, $query, $brand);
        return array_slice($merged, 0, 180);
    }

    return array_slice($merged, 0, 500);
}

function filterAndRankModels(array $models, string $query, string $brand): array
{
    $normalizedQuery = normalizeText($query);
    if ($normalizedQuery === '') {
        return $models;
    }

    $compactQuery = compactText($normalizedQuery);
    $normalizedBrand = normalizeText($brand);
    $tokens = array_values(array_filter(explode(' ', $normalizedQuery), static fn($t) => $t !== ''));

    $scored = [];
    foreach ($models as $model) {
        $normalizedModel = normalizeText((string)$model);
        if ($normalizedModel === '') {
            continue;
        }

        $compactModel = compactText($normalizedModel);
        $searchSpace = trim($normalizedBrand . ' ' . $normalizedModel);
        $compactSearch = compactText($searchSpace);

        $score = 0;

        if ($compactQuery !== '' && str_contains($compactModel, $compactQuery)) {
            $score += 260;
        }
        if ($compactQuery !== '' && str_contains($compactSearch, $compactQuery)) {
            $score += 180;
        }

        foreach ($tokens as $token) {
            $compactToken = compactText($token);
            if ($token !== '' && str_contains($searchSpace, $token)) {
                $score += 35;
            }
            if ($compactToken !== '' && str_contains($compactSearch, $compactToken)) {
                $score += 25;
            }
        }

        if ($compactQuery !== '' && str_starts_with($compactModel, $compactQuery)) {
            $score += 75;
        }

        if ($score > 0) {
            $scored[] = [
                'model' => $normalizedModel,
                'score' => $score,
            ];
        }
    }

    if (empty($scored)) {
        return [];
    }

    usort($scored, static function (array $a, array $b): int {
        if ($a['score'] === $b['score']) {
            return strcmp($a['model'], $b['model']);
        }
        return $b['score'] <=> $a['score'];
    });

    $result = [];
    $seen = [];
    foreach ($scored as $row) {
        $model = $row['model'];
        if (!isset($seen[$model])) {
            $seen[$model] = true;
            $result[] = $model;
        }
    }

    return $result;
}

function formatOptions(array $values): array
{
    $result = [];
    foreach ($values as $value) {
        $id = normalizeText((string)$value);
        if ($id === '') {
            continue;
        }
        $result[] = [
            'id' => $id,
            'name' => toTitleCase($id),
        ];
    }
    return $result;
}

function mapVehicleTypeForVpic(string $vehicleType): string
{
    if ($vehicleType === 'moto') {
        return 'motorcycle';
    }
    return 'car';
}

function normalizeType(string $type): string
{
    $v = mb_strtolower(trim($type), 'UTF-8');
    return match ($v) {
        'auto' => 'carro',
        'mototaxi' => 'moto',
        default => $v,
    };
}

function normalizeText(string $text): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    if ($text === false) {
        $text = trim($text);
    }

    $text = strtoupper($text);
    $text = preg_replace('/[^A-Z0-9 ]+/', ' ', $text) ?? '';
    $text = preg_replace('/\s+/', ' ', $text) ?? '';

    return trim($text);
}

function compactText(string $text): string
{
    return str_replace(' ', '', normalizeText($text));
}

function toTitleCase(string $text): string
{
    return mb_convert_case(mb_strtolower($text, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
}

function uniqueSorted(array $items): array
{
    $seen = [];
    foreach ($items as $item) {
        $normalized = normalizeText((string)$item);
        if ($normalized !== '') {
            $seen[$normalized] = true;
        }
    }

    $values = array_keys($seen);
    sort($values, SORT_NATURAL);
    return $values;
}

function fetchJson(string $url): ?array
{
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 5,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\nUser-Agent: Viax-Backend/1.0\r\n",
        ],
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || $raw === '') {
        return null;
    }

    $json = json_decode($raw, true);
    return is_array($json) ? $json : null;
}
