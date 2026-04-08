'use strict';

/**
 * Configuración centralizada del Realtime Gateway.
 * Variables de entorno con defaults sensatos para desarrollo.
 */

const env = process.env.NODE_ENV || 'development';

module.exports = {
  env,

  realtimeEnabled: process.env.REALTIME_ENABLED !== 'false',

  port: parseInt(process.env.WS_PORT || '9100', 10),

  ssl: {
    enabled: process.env.WS_SSL_ENABLED === 'true',
    key: process.env.WS_SSL_KEY || '',
    cert: process.env.WS_SSL_CERT || '',
  },

  redis: {
    host: process.env.REDIS_HOST || '127.0.0.1',
    port: parseInt(process.env.REDIS_PORT || '6379', 10),
    password: process.env.REDIS_PASSWORD || undefined,
    // Prefijo para buscar sesiones de usuario en Redis
    sessionPrefix: 'user_session:',
    // TTL para cache local de sesiones validadas (ms)
    sessionCacheTTL: 30_000,
    // Canal único de entrada para eventos formales.
    eventsChannel: process.env.REDIS_EVENTS_CHANNEL || 'viax:events',
  },

  ws: {
    // Segundos sin actividad antes de cerrar conexión
    idleTimeout: 120,
    // Intervalo de heartbeat que se comunica al cliente (ms)
    heartbeatInterval: 25_000,
    // Máximo de suscripciones por conexión
    maxSubscriptions: 20,
    // Mensajes pendientes por conexión antes de aplicar drop/close.
    maxPendingMessages: parseInt(process.env.WS_MAX_PENDING_MESSAGES || '200', 10),
    // Política de descarte: oldest | newest
    dropPolicy: process.env.WS_DROP_POLICY || 'oldest',
    // Cliente lento si supera este buffer en bytes.
    slowClientBytes: parseInt(process.env.WS_SLOW_CLIENT_BYTES || '196608', 10),
    // Cerrar conexión si supera este buffer en bytes.
    hardCloseBytes: parseInt(process.env.WS_HARD_CLOSE_BYTES || '524288', 10),
    // Tamaño del replay por canal (últimos eventos en memoria).
    replayBufferPerChannel: parseInt(process.env.WS_REPLAY_BUFFER_PER_CHANNEL || '50', 10),
  },

  rateLimit: {
    // Mensajes por ventana permitidos
    maxMessages: 30,
    // Ventana en ms
    windowMs: 10_000,
  },

  logging: {
    level: process.env.LOG_LEVEL || (env === 'production' ? 'info' : 'debug'),
  },
};
