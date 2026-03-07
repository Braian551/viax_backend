<?php
/**
 * Repositorio de viajes: acceso SQL encapsulado.
 */

class TripRepository
{
    public function __construct(private PDO $db)
    {
    }

    /** Query completo del viaje para respuesta al cliente. */
    public function getTripStatusById(int $solicitudId): ?array
    {
        $stmt = $this->db->prepare("SELECT
            s.id,
            s.uuid_solicitud,
            s.estado,
            s.tipo_servicio,
            s.latitud_recogida,
            s.longitud_recogida,
            s.direccion_recogida,
            s.latitud_destino,
            s.longitud_destino,
            s.direccion_destino,
            s.distancia_estimada,
            s.tiempo_estimado,
            s.fecha_creacion,
            s.aceptado_en,
            s.completado_en,
            s.distancia_recorrida,
            s.tiempo_transcurrido,
            s.precio_estimado,
            s.precio_final,
            s.precio_en_tracking,
            s.precio_ajustado_por_tracking,
            ac.conductor_id,
            u.nombre AS conductor_nombre,
            u.apellido AS conductor_apellido,
            u.telefono AS conductor_telefono,
            u.foto_perfil AS conductor_foto,
            dc.vehiculo_tipo,
            dc.vehiculo_marca,
            dc.vehiculo_modelo,
            dc.vehiculo_placa,
            dc.vehiculo_color,
            dc.calificacion_promedio AS conductor_calificacion,
            dc.latitud_actual AS conductor_latitud,
            dc.longitud_actual AS conductor_longitud,
            vrt.distancia_real_km AS tracking_distancia,
            vrt.tiempo_real_minutos AS tracking_tiempo,
            vrt.precio_final_aplicado AS tracking_precio,
            (SELECT vtr.velocidad
             FROM viaje_tracking_realtime vtr
             WHERE vtr.solicitud_id = s.id
             ORDER BY vtr.timestamp_servidor DESC
             LIMIT 1
            ) AS tracking_velocidad_kmh,
            EXTRACT(EPOCH FROM (s.completado_en - s.aceptado_en)) / 60 AS tiempo_calculado_min
        FROM solicitudes_servicio s
        LEFT JOIN asignaciones_conductor ac
            ON ac.solicitud_id = s.id
            AND ac.estado IN ('asignado','llegado','en_curso','completado')
        LEFT JOIN usuarios u ON u.id = ac.conductor_id
        LEFT JOIN detalles_conductor dc ON dc.usuario_id = ac.conductor_id
        LEFT JOIN viaje_resumen_tracking vrt ON vrt.solicitud_id = s.id
        WHERE s.id = ?");

        $stmt->execute([$solicitudId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
