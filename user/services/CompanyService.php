<?php
require_once __DIR__ . '/../../config/database.php';

class CompanyService {
    private $db;
    private $conn;
    private $baseUrl;

    public function __construct() {
        $this->db = new Database();
        $this->conn = $this->db->getConnection();
        $this->baseUrl = $this->getBaseUrl();
    }
    
    /**
     * Obtiene la URL base del servidor
     */
    private function getBaseUrl() {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return "$protocol://$host";
    }
    
    /**
     * Convierte una URL de logo almacenada en R2 a una URL accesible via proxy
     * Las URLs pueden estar en formato:
     * - Relativo: "empresas/2024/01/logo_xxx.jpg"
     * - R2 directo: "https://pub-xxx.r2.dev/empresas/..."
     * - Ya procesada: "https://servidor/backend/r2_proxy.php?key=..."
     */
    private function convertLogoUrl($logoUrl) {
        if (empty($logoUrl)) {
            return null;
        }
        
        // Si ya es una URL del proxy, retornar como está
        if (strpos($logoUrl, 'r2_proxy.php') !== false) {
            return $logoUrl;
        }
        
        // Si es una URL de R2 directa, extraer la key
        if (strpos($logoUrl, 'r2.dev/') !== false) {
            $parts = explode('r2.dev/', $logoUrl);
            $logoUrl = end($parts);
        }
        
        // Si es una URL completa de otro dominio, retornar como está
        if (strpos($logoUrl, 'http://') === 0 || strpos($logoUrl, 'https://') === 0) {
            return $logoUrl;
        }
        
        // Convertir path relativo a URL del proxy (Laragon)
        return $this->baseUrl . "/viax/backend/r2_proxy.php?key=" . urlencode($logoUrl);
    }

    /**
     * Obtiene empresas, vehículos disponibles y tarifas para un municipio
     */
    public function getCompaniesByMunicipality($municipio, $latitud, $longitud, $distanciaKm, $duracionMinutos, $radioKm = 10, $search = '') {
        $empresas = $this->findOperatingCompanies($municipio, $search);
        
        if (empty($empresas)) {
            return [
                'success' => true,
                'mensaje' => 'No hay empresas operando en este municipio',
                'empresa_recomendada_id' => null,
                'vehiculos_disponibles' => [],
                'empresas' => []
            ];
        }

        $empresaIds = array_column($empresas, 'id');
        $tiposPorEmpresa = $this->getVehicleTypesByCompanies($empresaIds);
        $conductoresData = $this->getNearbyDriversByCompanies($empresaIds, $latitud, $longitud, $radioKm);
        $tarifasData = $this->getPricingByCompanies($empresaIds);

        return $this->buildResponse(
            $municipio, 
            $empresas, 
            $tiposPorEmpresa, 
            $conductoresData, 
            $tarifasData, 
            $distanciaKm, 
            $duracionMinutos
        );
    }

    private function findOperatingCompanies($municipio, $search = '') {
        $searchQuery = "";
        if (!empty($search)) {
            $searchQuery = " AND (e.nombre ILIKE :search OR e.id::text ILIKE :search) ";
        }

        // Normalizar municipio para búsqueda (quitar tildes y convertir a minúsculas)
        $municipioNormalizado = $this->normalizeMunicipio($municipio);
        
        // Log para debug
        error_log("CompanyService: Buscando empresas para municipio: '$municipio' (normalizado: '$municipioNormalizado')");

        // Buscar por empresas_contacto.municipio O empresas_configuracion.zona_operacion
        // Usamos ILIKE para búsqueda parcial y tolerancia a tildes
        $query = "
            SELECT DISTINCT
                e.id,
                e.nombre,
                e.logo_url,
                e.verificada,
                ec.municipio,
                COALESCE(ecf.zona_operacion, ARRAY[]::TEXT[]) as zona_operacion,
                (
                    SELECT COALESCE(AVG(cal.calificacion), 0)
                    FROM calificaciones cal
                    JOIN usuarios u ON cal.usuario_calificado_id = u.id
                    WHERE u.empresa_id = e.id
                ) as calificacion_promedio
            FROM empresas_transporte e
            LEFT JOIN empresas_contacto ec ON e.id = ec.empresa_id
            LEFT JOIN empresas_configuracion ecf ON e.id = ecf.empresa_id
            WHERE e.estado = 'activo'
            AND e.verificada = true
            AND (
                -- Comparación exacta case-insensitive
                LOWER(ec.municipio) = LOWER(:municipio)
                -- O comparación parcial con ILIKE
                OR ec.municipio ILIKE :municipio_like
                -- O normalizado sin tildes
                OR LOWER(unaccent(ec.municipio)) = LOWER(unaccent(:municipio))
                -- O el municipio está en la zona de operación
                OR LOWER(:municipio) = ANY(SELECT LOWER(unnest(ecf.zona_operacion)))
                -- O búsqueda parcial en zona de operación
                OR EXISTS (
                    SELECT 1 FROM unnest(ecf.zona_operacion) AS zona 
                    WHERE LOWER(zona) LIKE LOWER(:municipio_partial)
                    OR LOWER(unaccent(zona)) LIKE LOWER(unaccent(:municipio_partial))
                )
            )
            $searchQuery
        ";
        
        $stmt = $this->conn->prepare($query);
        $municipioLike = '%' . $municipio . '%';
        $municipioPartial = '%' . $municipioNormalizado . '%';
        
        $stmt->bindParam(':municipio', $municipio);
        $stmt->bindParam(':municipio_like', $municipioLike);
        $stmt->bindParam(':municipio_partial', $municipioPartial);
        
        if (!empty($search)) {
            $searchTerm = "%$search%";
            $stmt->bindParam(':search', $searchTerm);
        }
        
        try {
            $stmt->execute();
            $empresas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // Si falla (ej: unaccent no instalado), usar consulta simplificada
            error_log("CompanyService: Error en consulta avanzada: " . $e->getMessage());
            $empresas = $this->findOperatingCompaniesSimple($municipio, $search);
        }

        error_log("CompanyService: Empresas encontradas con match exacto: " . count($empresas));

        // Si no hay match exacto, buscar empresas que cubran "toda la región" o tengan cobertura amplia
        if (empty($empresas)) {
            error_log("CompanyService: No hay match exacto, buscando empresas con cobertura regional...");
            
            $queryRegional = "
                SELECT DISTINCT e.id, e.nombre, e.logo_url, e.verificada, ec.municipio,
                       COALESCE(ecf.zona_operacion, ARRAY[]::TEXT[]) as zona_operacion,
                       (
                           SELECT COALESCE(AVG(cal.calificacion), 0)
                           FROM calificaciones cal
                           JOIN usuarios u ON cal.usuario_calificado_id = u.id
                           WHERE u.empresa_id = e.id
                       ) as calificacion_promedio
                FROM empresas_transporte e
                LEFT JOIN empresas_contacto ec ON e.id = ec.empresa_id
                LEFT JOIN empresas_configuracion ecf ON e.id = ecf.empresa_id
                WHERE e.estado = 'activo' 
                AND e.verificada = true
                AND (
                    -- Empresas con zona de operación que incluya 'todos' o 'antioquia' o 'regional'
                    EXISTS (
                        SELECT 1 FROM unnest(ecf.zona_operacion) AS zona 
                        WHERE LOWER(zona) IN ('todos', 'all', 'antioquia', 'regional', 'toda la region')
                    )
                    -- O empresas en el mismo departamento (Antioquia)
                    OR LOWER(ec.departamento) = 'antioquia'
                )
                LIMIT 10
            ";
            $stmt = $this->conn->prepare($queryRegional);
            $stmt->execute();
            $empresas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            error_log("CompanyService: Empresas encontradas con cobertura regional: " . count($empresas));
        }

        // Solo usar fallback si está en modo desarrollo y no hay ninguna empresa
        if (empty($empresas) && $this->isDevMode()) {
            error_log("CompanyService: FALLBACK - Modo desarrollo, mostrando todas las empresas");
            $queryFallback = "
                SELECT e.id, e.nombre, e.logo_url, e.verificada, ec.municipio,
                       COALESCE(ecf.zona_operacion, ARRAY[]::TEXT[]) as zona_operacion,
                       (
                           SELECT COALESCE(AVG(cal.calificacion), 0)
                           FROM calificaciones cal
                           JOIN usuarios u ON cal.usuario_calificado_id = u.id
                           WHERE u.empresa_id = e.id
                       ) as calificacion_promedio
                FROM empresas_transporte e
                LEFT JOIN empresas_contacto ec ON e.id = ec.empresa_id
                LEFT JOIN empresas_configuracion ecf ON e.id = ecf.empresa_id
                WHERE e.estado = 'activo' AND e.verificada = true
                LIMIT 10
            ";
            $stmt = $this->conn->prepare($queryFallback);
            $stmt->execute();
            $empresas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        return $empresas;
    }
    
    /**
     * Búsqueda simplificada sin unaccent (fallback)
     */
    private function findOperatingCompaniesSimple($municipio, $search = '') {
        $searchQuery = "";
        if (!empty($search)) {
            $searchQuery = " AND (e.nombre ILIKE :search OR e.id::text ILIKE :search) ";
        }
        
        $query = "
            SELECT DISTINCT
                e.id, e.nombre, e.logo_url, e.verificada, ec.municipio,
                COALESCE(ecf.zona_operacion, ARRAY[]::TEXT[]) as zona_operacion,
                (
                    SELECT COALESCE(AVG(cal.calificacion), 0)
                    FROM calificaciones cal
                    JOIN usuarios u ON cal.usuario_calificado_id = u.id
                    WHERE u.empresa_id = e.id
                ) as calificacion_promedio
            FROM empresas_transporte e
            LEFT JOIN empresas_contacto ec ON e.id = ec.empresa_id
            LEFT JOIN empresas_configuracion ecf ON e.id = ecf.empresa_id
            WHERE e.estado = 'activo'
            AND e.verificada = true
            AND (
                LOWER(ec.municipio) = LOWER(:municipio)
                OR ec.municipio ILIKE :municipio_like
                OR LOWER(:municipio) = ANY(SELECT LOWER(unnest(ecf.zona_operacion)))
            )
            $searchQuery
        ";
        
        $stmt = $this->conn->prepare($query);
        $municipioLike = '%' . $municipio . '%';
        $stmt->bindParam(':municipio', $municipio);
        $stmt->bindParam(':municipio_like', $municipioLike);
        
        if (!empty($search)) {
            $searchTerm = "%$search%";
            $stmt->bindParam(':search', $searchTerm);
        }
        
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Normaliza el nombre del municipio (quita tildes, espacios extra, etc.)
     */
    private function normalizeMunicipio($municipio) {
        // Quitar tildes manualmente
        $tildes = ['á'=>'a', 'é'=>'e', 'í'=>'i', 'ó'=>'o', 'ú'=>'u', 
                   'Á'=>'A', 'É'=>'E', 'Í'=>'I', 'Ó'=>'O', 'Ú'=>'U',
                   'ñ'=>'n', 'Ñ'=>'N'];
        $normalizado = strtr($municipio, $tildes);
        $normalizado = trim($normalizado);
        return $normalizado;
    }
    
    /**
     * Verifica si estamos en modo desarrollo
     */
    private function isDevMode() {
        $env = getenv('APP_ENV');
        if ($env === 'development' || $env === 'dev') {
            return true;
        }
        // Solo verificar constante si está definida
        if (defined('APP_ENV')) {
            return constant('APP_ENV') === 'development';
        }
        return false;
    }

    private function getVehicleTypesByCompanies($empresaIds) {
        if (empty($empresaIds)) return [];
        
        $placeholders = implode(',', array_fill(0, count($empresaIds), '?'));
        $query = "
            SELECT 
                etv.empresa_id,
                etv.tipo_vehiculo_codigo,
                ctv.nombre as tipo_nombre,
                ctv.orden
            FROM empresa_tipos_vehiculo etv
            INNER JOIN catalogo_tipos_vehiculo ctv ON etv.tipo_vehiculo_codigo = ctv.codigo
            WHERE etv.empresa_id IN ($placeholders)
            AND etv.activo = true
            ORDER BY ctv.orden
        ";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute($empresaIds);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $tiposPorEmpresa = [];
        foreach ($results as $row) {
            $tiposPorEmpresa[$row['empresa_id']][] = $row['tipo_vehiculo_codigo'];
        }
        return $tiposPorEmpresa;
    }

    private function getNearbyDriversByCompanies($empresaIds, $lat, $lon, $radioKm) {
        if (empty($empresaIds)) return [];

        $placeholders = implode(',', array_fill(0, count($empresaIds), '?'));
        
        // Complex query to aggregate driver stats
        $query = "
            SELECT 
                u.empresa_id,
                dc.vehiculo_tipo as tipo_vehiculo,
                COUNT(*) as total_conductores,
                MIN(
                    6371 * acos(
                        cos(radians(?)) * cos(radians(dc.latitud_actual)) *
                        cos(radians(dc.longitud_actual) - radians(?)) +
                        sin(radians(?)) * sin(radians(dc.latitud_actual))
                    )
                ) as conductor_mas_cercano_km
            FROM usuarios u
            INNER JOIN detalles_conductor dc ON u.id = dc.usuario_id
            WHERE u.tipo_usuario = 'conductor'
            AND u.es_activo = 1
            AND u.empresa_id IN ($placeholders)
            AND dc.disponible = 1
            AND dc.estado_verificacion = 'aprobado'
            AND dc.latitud_actual IS NOT NULL
            AND dc.longitud_actual IS NOT NULL
            AND (6371 * acos(
                cos(radians(?)) * cos(radians(dc.latitud_actual)) *
                cos(radians(dc.longitud_actual) - radians(?)) +
                sin(radians(?)) * sin(radians(dc.latitud_actual))
            )) <= ?
            GROUP BY u.empresa_id, dc.vehiculo_tipo
        ";

        $params = [$lat, $lon, $lat, ...$empresaIds, $lat, $lon, $lat, $radioKm];
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $index = [];
        foreach ($results as $row) {
            $key = $row['empresa_id'] . '_' . $row['tipo_vehiculo'];
            $index[$key] = $row;
        }
        return $index;
    }

    private function getPricingByCompanies($empresaIds) {
        if (empty($empresaIds)) return ['index' => [], 'global' => []];

        $placeholders = implode(',', array_fill(0, count($empresaIds), '?'));
        
        $query = "
            SELECT * FROM configuracion_precios 
            WHERE activo = 1
            AND (empresa_id IN ($placeholders) OR empresa_id IS NULL)
            ORDER BY empresa_id DESC NULLS LAST
        ";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute($empresaIds);
        $rates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $index = [];
        $global = [];
        
        foreach ($rates as $rate) {
            if ($rate['empresa_id']) {
                $index[$rate['empresa_id'] . '_' . $rate['tipo_vehiculo']] = $rate;
            } else {
                $global[$rate['tipo_vehiculo']] = $rate;
            }
        }
        
        return ['index' => $index, 'global' => $global];
    }

    private function buildResponse($municipio, $empresas, $tiposPorEmpresa, $conductoresIndex, $tarifasData, $distanciaKm, $duracionMinutos) {
        $vehiculosDisponibles = [];
        $empresasEnriquecidas = [];
        $empresaRecomendadaId = null;
        $mejorDistancia = PHP_FLOAT_MAX;
        $horaActual = date('H:i:s');

        $tarifasIndex = $tarifasData['index'];
        $tarifasGlobales = $tarifasData['global'];

        foreach ($empresas as $empresa) {
            $empresaId = $empresa['id'];
            $tiposEmpresa = $tiposPorEmpresa[$empresaId] ?? [];
            $totalConductoresEmpresa = 0;
            $distanciaMinimaEmpresa = PHP_FLOAT_MAX;

            foreach ($tiposEmpresa as $tipoVehiculo) {
                // Key for lookup
                $key = $empresaId . '_' . $tipoVehiculo;
                
                // Get driver data (can be null if no drivers nearby)
                $conductorData = $conductoresIndex[$key] ?? null;
                
                // Get pricing
                $tarifa = $tarifasIndex[$key] ?? $tarifasGlobales[$tipoVehiculo] ?? null;
                if (!$tarifa) continue; // Skip if no pricing found

                // Calculate price
                $precio = $this->calculatePrice($tarifa, $distanciaKm, $duracionMinutos, $horaActual);
                
                // Track driver stats (if drivers exist)
                $hasConductor = $conductorData !== null;
                $numConductores = $hasConductor ? intval($conductorData['total_conductores']) : 0;
                $distanciaConductor = $hasConductor ? floatval($conductorData['conductor_mas_cercano_km']) : null;
                
                $totalConductoresEmpresa += $numConductores;
                if ($hasConductor && $distanciaConductor < $distanciaMinimaEmpresa) {
                    $distanciaMinimaEmpresa = $distanciaConductor;
                }

                // Add to available vehicles list
                if (!isset($vehiculosDisponibles[$tipoVehiculo])) {
                    $vehiculosDisponibles[$tipoVehiculo] = [
                        'tipo' => $tipoVehiculo,
                        'nombre' => $this->getVehicleTypeName($tipoVehiculo),
                        'empresas' => []
                    ];
                }

                $vehiculosDisponibles[$tipoVehiculo]['empresas'][] = [
                    'id' => $empresaId,
                    'nombre' => $empresa['nombre'],
                    'logo_url' => $this->convertLogoUrl($empresa['logo_url']),
                    'conductores' => $numConductores,
                    'distancia_conductor_km' => $distanciaConductor !== null ? round($distanciaConductor, 2) : null,
                    'tarifa_total' => $precio['total'],
                    'tarifa_base' => $precio['tarifa_base'],
                    'costo_distancia' => $precio['costo_distancia'],
                    'costo_tiempo' => $precio['costo_tiempo'],
                    'recargo_precio' => $precio['recargo_precio'],
                    'periodo' => $precio['periodo'],
                    'periodo' => $precio['periodo'],
                    'recargo_porcentaje' => $precio['recargo_porcentaje'],
                    'calificacion' => isset($empresa['calificacion_promedio']) ? floatval($empresa['calificacion_promedio']) : 0.0
                ];
            }

            // Prepare company info
            $empresasEnriquecidas[] = [
                'id' => $empresaId,
                'nombre' => $empresa['nombre'],
                'logo_url' => $this->convertLogoUrl($empresa['logo_url']),
                'municipio' => $empresa['municipio'],
                'tipos_vehiculo' => $tiposEmpresa,
                'conductores_cercanos' => $totalConductoresEmpresa,
                'distancia_promedio_km' => $distanciaMinimaEmpresa < PHP_FLOAT_MAX ? round($distanciaMinimaEmpresa, 2) : null
            ];

            // Update recommended company (nearest driver logic)
            if ($distanciaMinimaEmpresa < $mejorDistancia && $totalConductoresEmpresa > 0) {
                $mejorDistancia = $distanciaMinimaEmpresa;
                $empresaRecomendadaId = $empresaId;
            }
        }

        // Sort companies inside each vehicle type by distance (nearest first, nulls last)
        foreach ($vehiculosDisponibles as &$vehiculo) {
            usort($vehiculo['empresas'], function($a, $b) {
                // Put companies with no drivers (null distance) at the end
                if ($a['distancia_conductor_km'] === null && $b['distancia_conductor_km'] === null) return 0;
                if ($a['distancia_conductor_km'] === null) return 1;
                if ($b['distancia_conductor_km'] === null) return -1;
                return $a['distancia_conductor_km'] <=> $b['distancia_conductor_km'];
            });
        }
        
        // Count total drivers across all companies
        $totalConductoresCerca = 0;
        foreach ($empresasEnriquecidas as $emp) {
            $totalConductoresCerca += $emp['conductores_cercanos'];
        }
        
        // Sort vehicle types by catalog order
        $vehiculosDisponibles = array_values($vehiculosDisponibles);
        $ordenVehiculos = ['moto' => 1, 'auto' => 2, 'motocarro' => 3, 'taxi' => 4];
        usort($vehiculosDisponibles, function($a, $b) use ($ordenVehiculos) {
            return ($ordenVehiculos[$a['tipo']] ?? 99) <=> ($ordenVehiculos[$b['tipo']] ?? 99);
        });

        return [
            'success' => true,
            'municipio' => $municipio,
            'empresa_recomendada_id' => $empresaRecomendadaId,
            'total_empresas' => count($empresasEnriquecidas),
            'total_tipos_vehiculo' => count($vehiculosDisponibles),
            'total_conductores_cerca' => $totalConductoresCerca,
            'vehiculos_disponibles' => $vehiculosDisponibles,
            'empresas' => $empresasEnriquecidas
        ];
    }

    private function calculatePrice($tarifa, $distanciaKm, $duracionMinutos, $horaActual) {
        $tarifaBase = floatval($tarifa['tarifa_base']);
        $precioDistancia = $distanciaKm * floatval($tarifa['costo_por_km']);
        $precioTiempo = $duracionMinutos * floatval($tarifa['costo_por_minuto']);
        
        $subtotal = $tarifaBase + $precioDistancia + $precioTiempo;
        
        $periodo = 'normal';
        $recargoPorcentaje = 0.0;
        
        // Logic for peak/night hours could be extracted further, keeping it simple here
        $h_pico_ini_m = $tarifa['hora_pico_inicio_manana'] ?? '07:00:00';
        $h_pico_fin_m = $tarifa['hora_pico_fin_manana'] ?? '09:00:00';
        $h_pico_ini_t = $tarifa['hora_pico_inicio_tarde'] ?? '17:00:00';
        $h_pico_fin_t = $tarifa['hora_pico_fin_tarde'] ?? '19:00:00';
        $h_noc_ini = $tarifa['hora_nocturna_inicio'] ?? '22:00:00';
        $h_noc_fin = $tarifa['hora_nocturna_fin'] ?? '06:00:00';
        
        if (($horaActual >= $h_pico_ini_m && $horaActual <= $h_pico_fin_m) || 
            ($horaActual >= $h_pico_ini_t && $horaActual <= $h_pico_fin_t)) {
            $periodo = 'hora_pico';
            $recargoPorcentaje = floatval($tarifa['recargo_hora_pico']);
        } elseif ($horaActual >= $h_noc_ini || $horaActual <= $h_noc_fin) {
            $periodo = 'nocturno';
            $recargoPorcentaje = floatval($tarifa['recargo_nocturno']);
        }
        
        $recargoPrecio = $subtotal * ($recargoPorcentaje / 100);
        $total = $subtotal + $recargoPrecio;
        $tarifaMinima = floatval($tarifa['tarifa_minima']);
        
        if ($total < $tarifaMinima) $total = $tarifaMinima;
        
        return [
            'tarifa_base' => round($tarifaBase, 0),
            'costo_distancia' => round($precioDistancia, 0),
            'costo_tiempo' => round($precioTiempo, 0),
            'subtotal' => round($subtotal, 2),
            'recargo_porcentaje' => round($recargoPorcentaje, 2),
            'recargo_precio' => round($recargoPrecio, 0),
            'total' => round($total, 0),
            'periodo' => $periodo
        ];
    }

    private function getVehicleTypeName($tipo) {
        $nombres = [
            'moto' => 'Moto',
            'auto' => 'Auto',
            'motocarro' => 'Motocarro',
            'taxi' => 'Taxi'
        ];
        return $nombres[$tipo] ?? ucfirst($tipo);
    }
}
?>
