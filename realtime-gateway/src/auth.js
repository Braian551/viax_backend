'use strict';

/**
 * Módulo de autenticación — Valida tokens de sesión contra Redis.
 *
 * Reutiliza el esquema de sesión existente del backend PHP:
 *   Redis key: user_session:{accessToken} → JSON con user_id, expires_at, revoked
 *
 * Incluye cache local en memoria con TTL corto para evitar consultas
 * Redis repetitivas en reconexiones rápidas.
 */

const Redis = require('ioredis');
const { logger } = require('./logger');

/**
 * Crea middleware de autenticación con conexión Redis propia.
 * @param {object} redisConfig - {host, port, password, sessionPrefix, sessionCacheTTL}
 */
function createAuthMiddleware(redisConfig) {
  const redis = new Redis({
    host: redisConfig.host,
    port: redisConfig.port,
    password: redisConfig.password,
    maxRetriesPerRequest: 3,
    retryStrategy(times) {
      if (times > 5) return null; // dejar de reintentar
      return Math.min(times * 200, 2000);
    },
    lazyConnect: true,
    enableReadyCheck: true,
  });

  redis.connect().catch((err) => {
    logger.error('[AUTH-REDIS] Error de conexión:', err.message);
  });

  redis.on('error', (err) => {
    logger.error('[AUTH-REDIS] Error:', err.message);
  });

  // Cache local de sesiones validadas para evitar Redis en cada reconexión
  const sessionCache = new Map();

  // Limpieza periódica del cache (cada 60s)
  setInterval(() => {
    const now = Date.now();
    for (const [key, entry] of sessionCache) {
      if (now - entry.cachedAt > redisConfig.sessionCacheTTL) {
        sessionCache.delete(key);
      }
    }
  }, 60_000);

  return {
    /**
     * Valida un token de acceso.
     * @param {string} token
     * @returns {Promise<object|null>} Sesión validada o null
     */
    async validateToken(token) {
      // Plausibilidad básica
      if (!token || token.length < 16 || token.length > 512) {
        return null;
      }

      // Verificar cache local
      const cached = sessionCache.get(token);
      if (cached && (Date.now() - cached.cachedAt < redisConfig.sessionCacheTTL)) {
        return cached.session;
      }

      try {
        const key = `${redisConfig.sessionPrefix}${token}`;
        const raw = await redis.get(key);

        if (!raw) {
          logger.debug(`[AUTH] Token no encontrado en Redis`);
          return null;
        }

        const session = JSON.parse(raw);

        const expiresAtMs = getExpiresAtMs(session);

        // Verificar expiración
        if (expiresAtMs) {
          if (Date.now() > expiresAtMs) {
            logger.debug(`[AUTH] Token expirado para user:${session.user_id}`);
            return null;
          }
        }

        // Verificar revocación
        if (session.revoked) {
          logger.debug(`[AUTH] Token revocado para user:${session.user_id}`);
          return null;
        }

        const hydrated = {
          ...session,
          expires_at_ms: expiresAtMs,
        };

        // Guardar en cache local
        sessionCache.set(token, { session: hydrated, cachedAt: Date.now() });

        return hydrated;
      } catch (err) {
        logger.error('[AUTH] Error validando token:', err.message);
        return null;
      }
    },

    async validateSessionStillActive(token) {
      // Forzar lectura de Redis para capturar revocación/expiración en caliente.
      sessionCache.delete(token);
      return this.validateToken(token);
    },

    /**
     * Invalida cache local para un token específico.
     */
    invalidateCache(token) {
      sessionCache.delete(token);
    },

    /**
     * Cierra conexión Redis.
     */
    async close() {
      await redis.quit();
    },
  };
}

function getExpiresAtMs(session) {
  if (!session || !session.expires_at) return null;

  if (typeof session.expires_at === 'number') {
    return session.expires_at > 1_000_000_000_000
      ? session.expires_at
      : session.expires_at * 1000;
  }

  const parsed = new Date(session.expires_at).getTime();
  return Number.isFinite(parsed) ? parsed : null;
}

module.exports = { createAuthMiddleware };
