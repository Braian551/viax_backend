'use strict';

/**
 * Redis Bridge — Suscriptor Pub/Sub que reenvía eventos al gateway WebSocket.
 *
 * Patrón de canales Redis (publicados por PHP backend):
 *   viax:user:{userId}        → eventos para pasajeros
 *   viax:driver:{driverId}    → eventos para conductores (ofertas, reasignaciones)
 *   viax:trip:{tripId}        → tracking + estado de viaje
 *   viax:request:{requestId}  → búsqueda de conductor + asignación
 *   viax:chat:{tripId}        → mensajes de chat en tiempo real
 *
 * El bridge hace pattern subscribe (PSUBSCRIBE) a `viax:*` y mapea
 * cada evento al canal WebSocket correspondiente para fan-out.
 *
 * Escalabilidad horizontal: N instancias del gateway pueden suscribirse
 * al mismo Redis. Cada una recibe todos los mensajes y los reenvía
 * solo a sus conexiones locales (uWS topics = fan-out eficiente).
 */

const Redis = require('ioredis');
const { logger } = require('./logger');
const { normalizeEvent } = require('./event-contract');

// Prefijo para canales Redis (evita colisión con otros sistemas)
const CHANNEL_PREFIX = 'viax:';

/**
 * Crea bridge Redis → WebSocket.
 * @param {object} redisConfig
 * @param {object} app - instancia uWebSockets App
 * @param {object} metrics - MetricsCollector
 */
async function createRedisBridge(redisConfig, app, metrics) {
  // Conexión dedicada para Pub/Sub (Redis requiere conexión separada)
  const subscriber = new Redis({
    host: redisConfig.host,
    port: redisConfig.port,
    password: redisConfig.password,
    maxRetriesPerRequest: null, // para pub/sub debe ser null
    retryStrategy(times) {
      const delay = Math.min(times * 300, 5000);
      logger.warn(`[REDIS-SUB] Reintentando conexión (#${times}) en ${delay}ms...`);
      return delay;
    },
    lazyConnect: false,
    enableReadyCheck: true,
  });

  subscriber.on('error', (err) => {
    logger.error('[REDIS-SUB] Error:', err.message);
    metrics.increment('redis.errors');
  });

  subscriber.on('reconnecting', () => {
    logger.warn('[REDIS-SUB] Reconectando...');
    metrics.increment('redis.reconnects');
  });

  // Suscripción explícita al canal de eventos formales (sin patrón global).
  await subscriber.subscribe(redisConfig.eventsChannel || 'viax:events');
  logger.info(`[REDIS-SUB] Suscrito a canal ${redisConfig.eventsChannel || 'viax:events'}`);

  subscriber.on('message', (channel, message) => {
    metrics.increment('redis.messages_received');

    if (channel !== (redisConfig.eventsChannel || 'viax:events')) {
      metrics.increment('redis.messages_ignored_channel');
      return;
    }

    let raw;
    try {
      raw = JSON.parse(message);
    } catch {
      logger.warn('[REDIS-SUB] Mensaje no JSON en canal de eventos');
      metrics.increment('redis.parse_errors');
      return;
    }

    const event = normalizeEvent(raw);
    if (!event) {
      metrics.increment('events.contract_invalid');
      return;
    }

    const channels = Array.isArray(event.channels) ? event.channels : [];
    if (channels.length === 0) {
      metrics.increment('events.no_channels');
      return;
    }

    for (const wsChannel of channels) {
      app.publish(wsChannel, event, false, false);
      metrics.increment('events.fanout');
    }

    const latencyMs = Math.max(0, Date.now() - (event.timestamp * 1000));
    metrics.gauge('latency.redis_to_gateway_ms', latencyMs);
  });

  return {
    subscriber,
    async close() {
      await subscriber.quit();
    },
  };
}

module.exports = { createRedisBridge, CHANNEL_PREFIX };
