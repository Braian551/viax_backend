-- Sistema de bloqueo entre usuarios (cliente/conductor)

CREATE TABLE IF NOT EXISTS blocked_users (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    blocked_user_id BIGINT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    reason VARCHAR(255) NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    blocked_at TIMESTAMP NOT NULL DEFAULT NOW(),
    unblocked_at TIMESTAMP NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    CONSTRAINT chk_blocked_users_not_self CHECK (user_id <> blocked_user_id)
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_blocked_users_pair
    ON blocked_users(user_id, blocked_user_id);

CREATE INDEX IF NOT EXISTS idx_blocked_users_user_active
    ON blocked_users(user_id, active);

CREATE INDEX IF NOT EXISTS idx_blocked_users_blocked_user_active
    ON blocked_users(blocked_user_id, active);
