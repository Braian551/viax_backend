-- Snapshot live de ubicacion de conductores para consultas SQL por grid.
CREATE TABLE IF NOT EXISTS drivers_live_location (
    conductor_id BIGINT PRIMARY KEY,
    lat DOUBLE PRECISION NOT NULL,
    lng DOUBLE PRECISION NOT NULL,
    speed_kmh DOUBLE PRECISION,
    grid_id VARCHAR(32) NOT NULL,
    city_id BIGINT,
    source VARCHAR(32) DEFAULT 'realtime',
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_driver_grid
    ON drivers_live_location (grid_id, updated_at DESC);

CREATE INDEX IF NOT EXISTS idx_driver_city_grid
    ON drivers_live_location (city_id, grid_id, updated_at DESC);