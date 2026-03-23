-- Migración idempotente: historial de búsquedas recientes de usuario.

CREATE TABLE IF NOT EXISTS recent_searches (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    place_name VARCHAR(255) NOT NULL,
    place_address TEXT NOT NULL,
    place_lat DOUBLE PRECISION NOT NULL,
    place_lng DOUBLE PRECISION NOT NULL,
    place_id VARCHAR(255),
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_recent_searches_user_created
    ON recent_searches (user_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_recent_searches_place_id
    ON recent_searches (place_id);
