-- Índices de optimización para despacho y lectura de viajes.
-- Migración idempotente para PostgreSQL.

CREATE INDEX IF NOT EXISTS drivers_location_idx
ON detalles_conductor (latitud_actual, longitud_actual);

CREATE INDEX IF NOT EXISTS trips_status_idx
ON solicitudes_servicio (estado);

CREATE INDEX IF NOT EXISTS trip_requests_created_idx
ON solicitudes_servicio (fecha_creacion DESC);
