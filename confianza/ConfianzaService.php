<?php
/**
 * Sistema de Conductores de Confianza - Servicio Principal
 * 
 * Este archivo contiene la lógica centralizada para:
 * - Calcular el ConfianzaScore
 * - Actualizar historial de confianza
 * - Obtener conductores priorizados
 * 
 * @package Viax\Confianza
 * @version 1.0.0
 */

require_once __DIR__ . '/../config/database.php';

class ConfianzaService {
    
    private $db;
    
    // Pesos para el cálculo del ConfianzaScore
    const PESO_VIAJES_REPETIDOS = 30;      // 30% - Número de viajes entre usuario-conductor
    const PESO_CALIFICACION_CONDUCTOR = 25; // 25% - Calificación promedio del conductor
    const PESO_CALIFICACION_USUARIO = 15;   // 15% - Calificación que el usuario da normalmente
    const PESO_PROXIMIDAD_ZONA = 20;        // 20% - Cercanía a zona frecuente del usuario
    const PESO_POPULARIDAD_VECINOS = 10;    // 10% - Popularidad entre usuarios cercanos
    
    // Bonus especiales
    const BONUS_FAVORITO = 100;             // Puntos extra por ser favorito
    const BONUS_VIAJE_RECIENTE = 5;         // Bonus si hubo viaje en últimos 7 días
    
    // Configuración
    const RADIO_VECINOS_KM = 2.0;           // Radio para buscar vecinos
    const DIAS_VIAJE_RECIENTE = 7;          // Días para considerar viaje reciente
    
    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
    }
    
    /**
     * Calcula el ConfianzaScore entre un usuario y un conductor
     * 
     * @param int $usuarioId ID del cliente
     * @param int $conductorId ID del conductor
     * @param float|null $usuarioLat Latitud actual del usuario (para proximidad)
     * @param float|null $usuarioLng Longitud actual del usuario
     * @return float Score de confianza (0-200, donde >100 incluye bonus favorito)
     */
    public function calcularConfianzaScore($usuarioId, $conductorId, $usuarioLat = null, $usuarioLng = null) {
        $score = 0;
        
        // 1. Obtener historial de confianza existente
        $historial = $this->obtenerHistorial($usuarioId, $conductorId);
        
        // 2. Calcular componente de viajes repetidos (0-30 puntos)
        $scoreViajes = $this->calcularScoreViajes($historial);
        $score += $scoreViajes;
        
        // 3. Calcular componente de calificación del conductor (0-25 puntos)
        $scoreCalifConductor = $this->calcularScoreCalificacionConductor($conductorId);
        $score += $scoreCalifConductor;
        
        // 4. Calcular componente de calificación del usuario (0-15 puntos)
        $scoreCalifUsuario = $this->calcularScoreCalificacionUsuario($usuarioId, $conductorId, $historial);
        $score += $scoreCalifUsuario;
        
        // 5. Calcular proximidad a zona frecuente (0-20 puntos)
        if ($usuarioLat !== null && $usuarioLng !== null && $historial) {
            $scoreProximidad = $this->calcularScoreProximidad(
                $usuarioLat, 
                $usuarioLng, 
                $historial['zona_frecuente_lat'],
                $historial['zona_frecuente_lng']
            );
            $score += $scoreProximidad;
        }
        
        // 6. Calcular popularidad entre vecinos (0-10 puntos)
        if ($usuarioLat !== null && $usuarioLng !== null) {
            $scorePopularidad = $this->calcularScorePopularidadVecinos($conductorId, $usuarioLat, $usuarioLng);
            $score += $scorePopularidad;
        }
        
        // 7. Bonus por ser favorito
        if ($this->esFavorito($usuarioId, $conductorId)) {
            $score += self::BONUS_FAVORITO;
        }
        
        // 8. Bonus por viaje reciente
        if ($historial && $this->tieneViajeReciente($historial)) {
            $score += self::BONUS_VIAJE_RECIENTE;
        }
        
        return round($score, 2);
    }
    
    /**
     * Obtiene el historial de confianza entre usuario y conductor
     */
    private function obtenerHistorial($usuarioId, $conductorId) {
        $stmt = $this->db->prepare("
            SELECT * FROM historial_confianza 
            WHERE usuario_id = ? AND conductor_id = ?
        ");
        $stmt->execute([$usuarioId, $conductorId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Calcula score basado en viajes repetidos (0-30 puntos)
     * Fórmula: min(30, viajes_completados * 3)
     */
    private function calcularScoreViajes($historial) {
        if (!$historial) return 0;
        
        $viajesCompletados = (int)($historial['viajes_completados'] ?? 0);
        
        // Cada viaje completado suma 3 puntos, máximo 30
        return min(self::PESO_VIAJES_REPETIDOS, $viajesCompletados * 3);
    }
    
    /**
     * Calcula score basado en calificación promedio del conductor (0-25 puntos)
     * Fórmula: (calificacion_promedio / 5) * 25
     */
    private function calcularScoreCalificacionConductor($conductorId) {
        $stmt = $this->db->prepare("
            SELECT calificacion_promedio FROM detalles_conductor 
            WHERE usuario_id = ?
        ");
        $stmt->execute([$conductorId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) return 0;
        
        $calificacion = floatval($result['calificacion_promedio'] ?? 0);
        
        // Normalizar a 0-25 puntos
        return ($calificacion / 5) * self::PESO_CALIFICACION_CONDUCTOR;
    }
    
    /**
     * Calcula score basado en calificaciones que el usuario da a este conductor (0-15 puntos)
     */
    private function calcularScoreCalificacionUsuario($usuarioId, $conductorId, $historial) {
        if (!$historial || !$historial['total_calificaciones']) return 0;
        
        $promedioCalif = $historial['suma_calificaciones_conductor'] / $historial['total_calificaciones'];
        
        // Normalizar a 0-15 puntos
        return ($promedioCalif / 5) * self::PESO_CALIFICACION_USUARIO;
    }
    
    /**
     * Calcula score por proximidad a zona frecuente (0-20 puntos)
     * Entre más cerca de la zona frecuente, más puntos
     */
    private function calcularScoreProximidad($lat, $lng, $zonaLat, $zonaLng) {
        if ($zonaLat === null || $zonaLng === null) return 0;
        
        $distancia = $this->calcularDistanciaKm($lat, $lng, $zonaLat, $zonaLng);
        
        // Si está a menos de 1km, puntos máximos
        // Decrece linealmente hasta 5km
        if ($distancia <= 1) {
            return self::PESO_PROXIMIDAD_ZONA;
        } elseif ($distancia >= 5) {
            return 0;
        } else {
            return self::PESO_PROXIMIDAD_ZONA * (1 - ($distancia - 1) / 4);
        }
    }
    
    /**
     * Calcula score por popularidad entre usuarios cercanos (0-10 puntos)
     */
    private function calcularScorePopularidadVecinos($conductorId, $usuarioLat, $usuarioLng) {
        // Buscar usuarios cercanos y cuántos han usado a este conductor
        $stmt = $this->db->prepare("
            SELECT COUNT(DISTINCT hc.usuario_id) as vecinos_que_usan
            FROM historial_confianza hc
            INNER JOIN ubicaciones_usuario uu ON hc.usuario_id = uu.usuario_id AND uu.es_principal = 1
            WHERE hc.conductor_id = ?
            AND hc.viajes_completados > 0
            AND (6371 * acos(
                cos(radians(?)) * cos(radians(uu.latitud)) *
                cos(radians(uu.longitud) - radians(?)) +
                sin(radians(?)) * sin(radians(uu.latitud))
            )) <= ?
        ");
        
        $stmt->execute([
            $conductorId,
            $usuarioLat,
            $usuarioLng,
            $usuarioLat,
            self::RADIO_VECINOS_KM
        ]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $vecinosQueUsan = (int)($result['vecinos_que_usan'] ?? 0);
        
        // Cada vecino suma 2 puntos, máximo 10
        return min(self::PESO_POPULARIDAD_VECINOS, $vecinosQueUsan * 2);
    }
    
    /**
     * Verifica si el conductor es favorito del usuario
     */
    public function esFavorito($usuarioId, $conductorId) {
        $stmt = $this->db->prepare("
            SELECT es_favorito FROM conductores_favoritos 
            WHERE usuario_id = ? AND conductor_id = ? AND es_favorito = 1
        ");
        $stmt->execute([$usuarioId, $conductorId]);
        return $stmt->fetch() !== false;
    }
    
    /**
     * Verifica si hubo viaje reciente
     */
    private function tieneViajeReciente($historial) {
        if (!$historial || !$historial['ultimo_viaje_fecha']) return false;
        
        $ultimoViaje = strtotime($historial['ultimo_viaje_fecha']);
        $diasPasados = (time() - $ultimoViaje) / (60 * 60 * 24);
        
        return $diasPasados <= self::DIAS_VIAJE_RECIENTE;
    }
    
    /**
     * Fórmula de Haversine para calcular distancia en km
     */
    private function calcularDistanciaKm($lat1, $lng1, $lat2, $lng2) {
        $earthRadius = 6371;
        
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        
        $a = sin($dLat/2) * sin($dLat/2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLng/2) * sin($dLng/2);
        
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        
        return $earthRadius * $c;
    }
    
    /**
     * Marca o desmarca un conductor como favorito
     */
    public function toggleFavorito($usuarioId, $conductorId) {
        // Verificar si ya existe el registro
        $stmt = $this->db->prepare("
            SELECT id, es_favorito FROM conductores_favoritos 
            WHERE usuario_id = ? AND conductor_id = ?
        ");
        $stmt->execute([$usuarioId, $conductorId]);
        $existente = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existente) {
            // Toggle el estado
            $nuevoEstado = $existente['es_favorito'] ? 0 : 1;
            $stmt = $this->db->prepare("
                UPDATE conductores_favoritos 
                SET es_favorito = ?,
                    fecha_desmarcado = CASE WHEN ? = 0 THEN NOW() ELSE NULL END,
                    fecha_marcado = CASE WHEN ? = 1 THEN NOW() ELSE fecha_marcado END
                WHERE id = ?
            ");
            $stmt->execute([$nuevoEstado, $nuevoEstado, $nuevoEstado, $existente['id']]);
            
            return ['es_favorito' => (bool)$nuevoEstado];
        } else {
            // Crear nuevo registro como favorito
            $stmt = $this->db->prepare("
                INSERT INTO conductores_favoritos (usuario_id, conductor_id, es_favorito, fecha_marcado)
                VALUES (?, ?, 1, NOW())
            ");
            $stmt->execute([$usuarioId, $conductorId]);
            
            return ['es_favorito' => true];
        }
    }
    
    /**
     * Actualiza el historial de confianza después de un viaje
     */
    public function actualizarHistorialDespuesDeViaje($solicitudId, $estado) {
        // Obtener info del viaje
        $stmt = $this->db->prepare("
            SELECT 
                ss.cliente_id as usuario_id,
                ac.conductor_id,
                ss.latitud_recogida,
                ss.longitud_recogida,
                ss.estado
            FROM solicitudes_servicio ss
            INNER JOIN asignaciones_conductor ac ON ss.id = ac.solicitud_id
            WHERE ss.id = ?
        ");
        $stmt->execute([$solicitudId]);
        $viaje = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$viaje) return false;
        
        $usuarioId = $viaje['usuario_id'];
        $conductorId = $viaje['conductor_id'];
        
        // Verificar si existe historial
        $stmt = $this->db->prepare("
            SELECT id, total_viajes, viajes_completados, viajes_cancelados,
                   zona_frecuente_lat, zona_frecuente_lng
            FROM historial_confianza 
            WHERE usuario_id = ? AND conductor_id = ?
        ");
        $stmt->execute([$usuarioId, $conductorId]);
        $historial = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($historial) {
            // Actualizar existente
            $totalViajes = $historial['total_viajes'] + 1;
            $viajesCompletados = $historial['viajes_completados'];
            $viajesCancelados = $historial['viajes_cancelados'];
            
            if ($estado === 'completada') {
                $viajesCompletados++;
            } elseif ($estado === 'cancelada') {
                $viajesCancelados++;
            }
            
            // Actualizar zona frecuente (promedio ponderado)
            $n = $historial['total_viajes'];
            $nuevaLat = (($historial['zona_frecuente_lat'] ?? 0) * $n + $viaje['latitud_recogida']) / ($n + 1);
            $nuevaLng = (($historial['zona_frecuente_lng'] ?? 0) * $n + $viaje['longitud_recogida']) / ($n + 1);
            
            // Recalcular score
            $nuevoScore = $this->calcularConfianzaScore($usuarioId, $conductorId, $nuevaLat, $nuevaLng);
            
            $stmt = $this->db->prepare("
                UPDATE historial_confianza 
                SET total_viajes = ?,
                    viajes_completados = ?,
                    viajes_cancelados = ?,
                    ultimo_viaje_fecha = NOW(),
                    zona_frecuente_lat = ?,
                    zona_frecuente_lng = ?,
                    score_confianza = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $totalViajes,
                $viajesCompletados,
                $viajesCancelados,
                $nuevaLat,
                $nuevaLng,
                $nuevoScore,
                $historial['id']
            ]);
        } else {
            // Crear nuevo historial
            $viajesCompletados = $estado === 'completada' ? 1 : 0;
            $viajesCancelados = $estado === 'cancelada' ? 1 : 0;
            
            $stmt = $this->db->prepare("
                INSERT INTO historial_confianza (
                    usuario_id, conductor_id, total_viajes, viajes_completados, viajes_cancelados,
                    ultimo_viaje_fecha, zona_frecuente_lat, zona_frecuente_lng, score_confianza
                ) VALUES (?, ?, 1, ?, ?, NOW(), ?, ?, 0)
            ");
            $stmt->execute([
                $usuarioId,
                $conductorId,
                $viajesCompletados,
                $viajesCancelados,
                $viaje['latitud_recogida'],
                $viaje['longitud_recogida']
            ]);
        }
        
        return true;
    }
    
    /**
     * Actualiza el historial después de una calificación
     */
    public function actualizarHistorialDespuesDeCalificacion($solicitudId, $calificadorId, $calificadoId, $calificacion) {
        // Determinar quién es usuario y quién es conductor
        $stmt = $this->db->prepare("
            SELECT ss.cliente_id, ac.conductor_id
            FROM solicitudes_servicio ss
            INNER JOIN asignaciones_conductor ac ON ss.id = ac.solicitud_id
            WHERE ss.id = ?
        ");
        $stmt->execute([$solicitudId]);
        $viaje = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$viaje) return false;
        
        $usuarioId = $viaje['cliente_id'];
        $conductorId = $viaje['conductor_id'];
        
        // Verificar si existe historial
        $stmt = $this->db->prepare("
            SELECT id, suma_calificaciones_conductor, suma_calificaciones_usuario, total_calificaciones
            FROM historial_confianza 
            WHERE usuario_id = ? AND conductor_id = ?
        ");
        $stmt->execute([$usuarioId, $conductorId]);
        $historial = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($historial) {
            $sumaCalifConductor = $historial['suma_calificaciones_conductor'];
            $sumaCalifUsuario = $historial['suma_calificaciones_usuario'];
            $totalCalif = $historial['total_calificaciones'] + 1;
            
            // Determinar si el usuario calificó al conductor o viceversa
            if ($calificadorId == $usuarioId) {
                // Usuario calificó al conductor
                $sumaCalifConductor += $calificacion;
            } else {
                // Conductor calificó al usuario
                $sumaCalifUsuario += $calificacion;
            }
            
            $stmt = $this->db->prepare("
                UPDATE historial_confianza 
                SET suma_calificaciones_conductor = ?,
                    suma_calificaciones_usuario = ?,
                    total_calificaciones = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $sumaCalifConductor,
                $sumaCalifUsuario,
                $totalCalif,
                $historial['id']
            ]);
            
            // Recalcular score
            $nuevoScore = $this->calcularConfianzaScore($usuarioId, $conductorId);
            $stmt = $this->db->prepare("UPDATE historial_confianza SET score_confianza = ? WHERE id = ?");
            $stmt->execute([$nuevoScore, $historial['id']]);
        }
        
        return true;
    }
    
    /**
     * Obtiene conductores favoritos del usuario
     */
    public function obtenerFavoritos($usuarioId) {
        $stmt = $this->db->prepare("
            SELECT 
                cf.conductor_id,
                u.nombre,
                u.apellido,
                u.foto_perfil,
                dc.vehiculo_tipo,
                dc.vehiculo_marca,
                dc.vehiculo_modelo,
                dc.vehiculo_placa,
                dc.calificacion_promedio,
                dc.total_viajes,
                COALESCE(hc.viajes_completados, 0) as viajes_contigo,
                COALESCE(hc.score_confianza, 0) as score_confianza,
                cf.fecha_marcado
            FROM conductores_favoritos cf
            INNER JOIN usuarios u ON cf.conductor_id = u.id
            INNER JOIN detalles_conductor dc ON u.id = dc.usuario_id
            LEFT JOIN historial_confianza hc ON cf.usuario_id = hc.usuario_id AND cf.conductor_id = hc.conductor_id
            WHERE cf.usuario_id = ?
            AND cf.es_favorito = 1
            AND dc.estado_verificacion = 'aprobado'
            ORDER BY hc.score_confianza DESC, dc.calificacion_promedio DESC
        ");
        $stmt->execute([$usuarioId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obtiene conductores ordenados por confianza para un usuario específico
     */
    public function obtenerConductoresPorConfianza($usuarioId, $lat, $lng, $radioKm = 5.0, $limit = 20) {
        $stmt = $this->db->prepare("
            SELECT 
                u.id as conductor_id,
                u.nombre,
                u.apellido,
                u.telefono,
                u.foto_perfil,
                dc.vehiculo_tipo,
                dc.vehiculo_marca,
                dc.vehiculo_modelo,
                dc.vehiculo_placa,
                dc.vehiculo_color,
                dc.calificacion_promedio,
                dc.total_viajes,
                dc.latitud_actual,
                dc.longitud_actual,
                (6371 * acos(
                    cos(radians(?)) * cos(radians(dc.latitud_actual)) *
                    cos(radians(dc.longitud_actual) - radians(?)) +
                    sin(radians(?)) * sin(radians(dc.latitud_actual))
                )) AS distancia_km,
                COALESCE(hc.score_confianza, 0) as score_confianza,
                COALESCE(hc.viajes_completados, 0) as viajes_contigo,
                CASE WHEN cf.es_favorito = 1 THEN 1 ELSE 0 END as es_favorito,
                (COALESCE(hc.score_confianza, 0) + CASE WHEN cf.es_favorito = 1 THEN 100 ELSE 0 END) as score_total
            FROM usuarios u
            INNER JOIN detalles_conductor dc ON u.id = dc.usuario_id
            LEFT JOIN historial_confianza hc ON hc.usuario_id = ? AND hc.conductor_id = u.id
            LEFT JOIN conductores_favoritos cf ON cf.usuario_id = ? AND cf.conductor_id = u.id AND cf.es_favorito = 1
            WHERE u.tipo_usuario = 'conductor'
            AND u.es_activo = 1
            AND dc.disponible = 1
            AND dc.estado_verificacion = 'aprobado'
            AND dc.latitud_actual IS NOT NULL
            AND dc.longitud_actual IS NOT NULL
            AND (6371 * acos(
                cos(radians(?)) * cos(radians(dc.latitud_actual)) *
                cos(radians(dc.longitud_actual) - radians(?)) +
                sin(radians(?)) * sin(radians(dc.latitud_actual))
            )) <= ?
            ORDER BY 
                es_favorito DESC,           -- Primero favoritos
                score_total DESC,           -- Luego por score total
                distancia_km ASC            -- Finalmente por distancia
            LIMIT ?
        ");
        
        $stmt->execute([
            $lat, $lng, $lat,  // Para distancia
            $usuarioId,        // Para historial_confianza
            $usuarioId,        // Para conductores_favoritos
            $lat, $lng, $lat,  // Para filtro de radio
            $radioKm,
            $limit
        ]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
