-- Migration 037: Sistema de Concurrencia y Latencia
-- Agrega campos y estructuras para manejo robusto de concurrencia en solicitudes de viaje
-- 
-- Características:
-- 1. Columna version para optimistic locking en solicitudes
-- 2. Tabla de bloqueos distribuidos para operaciones críticas
-- 3. Índices optimizados para consultas concurrentes
-- 4. Tabla de cola de operaciones para sincronización

-- ============================================
-- 1. OPTIMISTIC LOCKING EN SOLICITUDES
-- ============================================

-- Agregar columna de versión para optimistic locking
ALTER TABLE solicitudes_servicio 
ADD COLUMN IF NOT EXISTS version INTEGER DEFAULT 1;

-- Agregar columna de lock timestamp para detectar operaciones en progreso
ALTER TABLE solicitudes_servicio 
ADD COLUMN IF NOT EXISTS locked_at TIMESTAMP DEFAULT NULL;

-- Agregar columna de lock holder para identificar quién tiene el lock
ALTER TABLE solicitudes_servicio 
ADD COLUMN IF NOT EXISTS locked_by VARCHAR(100) DEFAULT NULL;

-- Agregar columna para tracking de última sincronización
ALTER TABLE solicitudes_servicio 
ADD COLUMN IF NOT EXISTS last_sync_at TIMESTAMP DEFAULT NULL;

-- Agregar columna para idempotency key (evitar operaciones duplicadas)
ALTER TABLE solicitudes_servicio 
ADD COLUMN IF NOT EXISTS last_operation_key VARCHAR(64) DEFAULT NULL;

-- ============================================
-- 2. TABLA DE BLOQUEOS DISTRIBUIDOS
-- ============================================

CREATE TABLE IF NOT EXISTS distributed_locks (
    id SERIAL PRIMARY KEY,
    resource_type VARCHAR(50) NOT NULL,
    resource_id INTEGER NOT NULL,
    lock_holder VARCHAR(100) NOT NULL,
    acquired_at TIMESTAMP DEFAULT NOW(),
    expires_at TIMESTAMP NOT NULL,
    lock_reason VARCHAR(255),
    UNIQUE(resource_type, resource_id)
);

-- Índice para limpieza de locks expirados
CREATE INDEX IF NOT EXISTS idx_locks_expires_at ON distributed_locks(expires_at);

-- ============================================
-- 3. TABLA DE OPERACIONES PENDIENTES
-- ============================================

CREATE TABLE IF NOT EXISTS pending_operations (
    id SERIAL PRIMARY KEY,
    operation_type VARCHAR(50) NOT NULL,
    solicitud_id INTEGER,
    conductor_id INTEGER,
    cliente_id INTEGER,
    payload JSONB NOT NULL,
    idempotency_key VARCHAR(64) UNIQUE NOT NULL,
    status VARCHAR(20) DEFAULT 'pending',
    attempts INTEGER DEFAULT 0,
    max_attempts INTEGER DEFAULT 5,
    last_attempt_at TIMESTAMP,
    completed_at TIMESTAMP,
    error_message TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Índices para consultas eficientes
CREATE INDEX IF NOT EXISTS idx_pending_ops_status ON pending_operations(status);
CREATE INDEX IF NOT EXISTS idx_pending_ops_solicitud ON pending_operations(solicitud_id);
CREATE INDEX IF NOT EXISTS idx_pending_ops_conductor ON pending_operations(conductor_id);
CREATE INDEX IF NOT EXISTS idx_pending_ops_created ON pending_operations(created_at);

-- ============================================
-- 4. TABLA DE LOG DE SINCRONIZACIÓN
-- ============================================

CREATE TABLE IF NOT EXISTS sync_log (
    id SERIAL PRIMARY KEY,
    solicitud_id INTEGER,
    operation VARCHAR(50) NOT NULL,
    client_version INTEGER,
    server_version INTEGER,
    client_timestamp TIMESTAMP,
    server_timestamp TIMESTAMP DEFAULT NOW(),
    was_conflict BOOLEAN DEFAULT FALSE,
    resolution VARCHAR(50),
    details JSONB
);

-- Índice para consultas de conflictos
CREATE INDEX IF NOT EXISTS idx_sync_log_conflicts ON sync_log(solicitud_id, was_conflict);

-- ============================================
-- 5. ÍNDICES OPTIMIZADOS PARA CONCURRENCIA
-- ============================================

-- Índice para búsqueda rápida de solicitudes pendientes por zona
CREATE INDEX IF NOT EXISTS idx_solicitudes_estado_location 
ON solicitudes_servicio(estado, latitud_recogida, longitud_recogida) 
WHERE estado = 'pendiente';

-- Índice parcial para solicitudes activas (no finalizadas)
CREATE INDEX IF NOT EXISTS idx_solicitudes_activas 
ON solicitudes_servicio(id, estado, version) 
WHERE estado NOT IN ('completada', 'cancelada', 'cancelada_por_usuario', 'cancelada_por_conductor');

-- Índice para búsqueda rápida de asignaciones activas
CREATE INDEX IF NOT EXISTS idx_asignaciones_activas 
ON asignaciones_conductor(solicitud_id, conductor_id, estado)
WHERE estado IN ('asignado', 'llegado', 'en_curso');
