-- Migration 037b: Funciones para Concurrencia
-- Funciones PL/pgSQL que deben ejecutarse como bloques separados

-- FUNCTION 1: increment_solicitud_version
CREATE OR REPLACE FUNCTION increment_solicitud_version()
RETURNS TRIGGER AS $FUNC$
BEGIN
    NEW.version = COALESCE(OLD.version, 0) + 1;
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$FUNC$ LANGUAGE plpgsql;

-- FUNCTION 2: acquire_lock
CREATE OR REPLACE FUNCTION acquire_lock(
    p_resource_type VARCHAR,
    p_resource_id INTEGER,
    p_lock_holder VARCHAR,
    p_duration_seconds INTEGER DEFAULT 30
)
RETURNS BOOLEAN AS $FUNC$
DECLARE
    v_acquired BOOLEAN;
BEGIN
    DELETE FROM distributed_locks WHERE expires_at < NOW();
    INSERT INTO distributed_locks (resource_type, resource_id, lock_holder, expires_at)
    VALUES (p_resource_type, p_resource_id, p_lock_holder, NOW() + (p_duration_seconds || ' seconds')::INTERVAL)
    ON CONFLICT (resource_type, resource_id) DO NOTHING;
    SELECT EXISTS(
        SELECT 1 FROM distributed_locks 
        WHERE resource_type = p_resource_type 
        AND resource_id = p_resource_id 
        AND lock_holder = p_lock_holder
    ) INTO v_acquired;
    RETURN v_acquired;
END;
$FUNC$ LANGUAGE plpgsql;

-- FUNCTION 3: release_lock
CREATE OR REPLACE FUNCTION release_lock(
    p_resource_type VARCHAR,
    p_resource_id INTEGER,
    p_lock_holder VARCHAR
)
RETURNS BOOLEAN AS $FUNC$
BEGIN
    DELETE FROM distributed_locks 
    WHERE resource_type = p_resource_type 
    AND resource_id = p_resource_id 
    AND lock_holder = p_lock_holder;
    RETURN FOUND;
END;
$FUNC$ LANGUAGE plpgsql;
