-- =============================================
-- Migration 040: Location Sharing System
-- Real-time location sharing via unique tokens
-- =============================================

-- Table to store share sessions
CREATE TABLE IF NOT EXISTS location_shares (
    id SERIAL PRIMARY KEY,
    token VARCHAR(64) NOT NULL UNIQUE,
    user_id INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    solicitud_id INTEGER REFERENCES solicitudes(id) ON DELETE SET NULL,
    latitude DOUBLE PRECISION,
    longitude DOUBLE PRECISION,
    heading DOUBLE PRECISION DEFAULT 0,
    speed DOUBLE PRECISION DEFAULT 0,
    accuracy DOUBLE PRECISION DEFAULT 0,
    last_update TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    expires_at TIMESTAMP WITH TIME ZONE NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    -- Optional metadata
    sharer_name VARCHAR(255),
    vehicle_plate VARCHAR(20),
    destination_address TEXT,
    destination_lat DOUBLE PRECISION,
    destination_lng DOUBLE PRECISION
);

-- Indexes for fast lookup
CREATE INDEX IF NOT EXISTS idx_location_shares_token ON location_shares(token);
CREATE INDEX IF NOT EXISTS idx_location_shares_user_id ON location_shares(user_id);
CREATE INDEX IF NOT EXISTS idx_location_shares_active ON location_shares(is_active, expires_at);

-- Cleanup: auto-deactivate expired sessions (can be run via cron)
-- UPDATE location_shares SET is_active = false WHERE expires_at < NOW() AND is_active = true;
