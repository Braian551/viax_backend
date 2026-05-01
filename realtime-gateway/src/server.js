'use strict';

const http = require('http');
const url = require('url');
let uWS = null;
try {
  // Opcional: si existe binario nativo, se puede usar en futuras iteraciones.
  uWS = require('uWebSockets.js');
} catch (_) {
  uWS = null;
}
const WebSocket = require('ws');

const config = require('./config');
const { createAuthMiddleware } = require('./auth');
const { createRedisBridge } = require('./redis-bridge');
const { ConnectionManager } = require('./connection-manager');
const { MetricsCollector } = require('./metrics');
const { RateLimiter } = require('./rate-limiter');
const { logger } = require('./logger');
const { normalizeEvent } = require('./event-contract');

const metrics = new MetricsCollector();
const connections = new ConnectionManager(metrics);
const rateLimiter = new RateLimiter(config.rateLimit);
const auth = createAuthMiddleware(config.redis);

const channelSubscribers = new Map();
const recentEventsByChannel = new Map();
const seenEventIds = new Map();

function now() {
  return Math.floor(Date.now() / 1000);
}

function rememberEvent(channel, event) {
  const dedupeKey = `${channel}:${event.event_id}`;
  if (seenEventIds.has(dedupeKey)) {
    metrics.increment('events.dedup_dropped');
    return;
  }
  seenEventIds.set(dedupeKey, Date.now());

  let list = recentEventsByChannel.get(channel);
  if (!list) {
    list = [];
    recentEventsByChannel.set(channel, list);
  }
  list.push(event);
  if (list.length > config.ws.replayBufferPerChannel) {
    list.shift();
  }
}

function pruneDedupCache() {
  const threshold = Date.now() - 120_000;
  for (const [key, value] of seenEventIds) {
    if (value < threshold) {
      seenEventIds.delete(key);
    }
  }
}

function subscribeSocketToChannel(ws, channel) {
  if (!channelSubscribers.has(channel)) {
    channelSubscribers.set(channel, new Set());
  }
  channelSubscribers.get(channel).add(ws);
  ws.userData.subscriptions.add(channel);
}

function unsubscribeSocketFromChannel(ws, channel) {
  const set = channelSubscribers.get(channel);
  if (set) {
    set.delete(ws);
    if (set.size === 0) {
      channelSubscribers.delete(channel);
    }
  }
  ws.userData.subscriptions.delete(channel);
}

function cleanupSocketSubscriptions(ws) {
  for (const channel of Array.from(ws.userData.subscriptions)) {
    unsubscribeSocketFromChannel(ws, channel);
  }
}

function publish(channel, payload) {
  const set = channelSubscribers.get(channel);
  if (!set || set.size === 0) {
    return false;
  }

  for (const ws of set) {
    queueEvent(ws, payload);
  }
  return true;
}

function validateChannel(channel, userId) {
  if (!channel || typeof channel !== 'string') return null;
  const match = channel.match(/^(user|driver|trip|request|chat):(\d+)$/);
  if (!match) return null;

  const [, prefix, id] = match;
  if (prefix === 'user' && Number.parseInt(id, 10) !== userId) {
    return null;
  }
  return channel;
}

function pushPending(userData, payload) {
  if (userData.pendingMessages.length >= config.ws.maxPendingMessages) {
    if (config.ws.dropPolicy === 'newest') {
      userData.droppedMessages += 1;
      metrics.increment('backpressure.drop_newest');
      return;
    }
    userData.pendingMessages.shift();
    userData.droppedMessages += 1;
    metrics.increment('backpressure.drop_oldest');
  }
  userData.pendingMessages.push(payload);
}

function flushPendingQueue(ws) {
  const queue = ws.userData.pendingMessages;
  if (!queue || queue.length === 0) return;

  let flushed = 0;
  while (queue.length > 0) {
    if (ws.bufferedAmount > config.ws.slowClientBytes) {
      break;
    }

    try {
      ws.send(queue[0]);
      queue.shift();
      flushed += 1;
    } catch {
      break;
    }
  }

  if (flushed > 0) {
    metrics.increment('backpressure.flushed', flushed);
  }
}

function queueEvent(ws, data) {
  const event = normalizeEvent(data);
  if (!event) {
    metrics.increment('events.contract_invalid_outbound');
    return false;
  }

  const channel = `${event.entity}:${event.entity_id}`;
  rememberEvent(channel, event);

  if (ws.readyState !== WebSocket.OPEN) {
    pushPending(ws.userData, JSON.stringify(event));
    return false;
  }

  if (ws.bufferedAmount > config.ws.hardCloseBytes) {
    metrics.increment('backpressure.closed_hard_limit');
    try {
      ws.close(1011, 'slow_client_hard_limit');
    } catch (_) {}
    return false;
  }

  if (ws.bufferedAmount > config.ws.slowClientBytes) {
    ws.userData.slowClientDetected = true;
    metrics.increment('backpressure.slow_client_detected');
  }

  try {
    ws.send(JSON.stringify(event));
    metrics.increment('events.sent');
    return true;
  } catch {
    pushPending(ws.userData, JSON.stringify(event));
    metrics.increment('backpressure.queued');
    return false;
  }
}

function replayRecentEvents(ws, channel, lastEventTs) {
  const list = recentEventsByChannel.get(channel);
  if (!list || list.length === 0) return;

  const minTs = Number.isFinite(lastEventTs) ? Math.trunc(lastEventTs) : 0;
  let replayed = 0;
  for (const event of list) {
    if (minTs > 0 && event.timestamp <= minTs) continue;
    if (queueEvent(ws, event)) replayed += 1;
  }
  if (replayed > 0) {
    metrics.increment('events.replayed', replayed);
  }
}

function closeIfSessionExpired(ws) {
  if (ws.userData.sessionExpiresAtMs && Date.now() > ws.userData.sessionExpiresAtMs) {
    metrics.increment('auth.session_expired_close');
    try {
      ws.close(4401, 'session_expired');
    } catch (_) {}
    return true;
  }
  return false;
}

function handleClientMessage(ws, text) {
  if (closeIfSessionExpired(ws)) return;

  if (!rateLimiter.allow(ws.userData.userId)) {
    queueEvent(ws, {
      type: 'error',
      version: 1,
      entity: 'user',
      entity_id: String(ws.userData.userId),
      timestamp: now(),
      payload: { code: 'RATE_LIMITED', message: 'Demasiadas solicitudes' },
    });
    metrics.increment('messages.rate_limited');
    return;
  }

  let data;
  try {
    data = JSON.parse(text);
  } catch (_) {
    queueEvent(ws, {
      type: 'error',
      version: 1,
      entity: 'user',
      entity_id: String(ws.userData.userId),
      timestamp: now(),
      payload: { code: 'INVALID_JSON', message: 'Mensaje inválido' },
    });
    return;
  }

  const type = data?.type;
  const payload = data?.payload || {};

  if (type === 'subscribe') {
    const channel = validateChannel(payload.channel, ws.userData.userId);
    if (!channel) {
      queueEvent(ws, {
        type: 'error',
        version: 1,
        entity: 'user',
        entity_id: String(ws.userData.userId),
        timestamp: now(),
        payload: { code: 'INVALID_CHANNEL', message: 'Canal no válido' },
      });
      return;
    }

    if (ws.userData.subscriptions.size >= config.ws.maxSubscriptions) {
      queueEvent(ws, {
        type: 'error',
        version: 1,
        entity: 'user',
        entity_id: String(ws.userData.userId),
        timestamp: now(),
        payload: { code: 'MAX_SUBSCRIPTIONS', message: 'Límite de suscripciones alcanzado' },
      });
      return;
    }

    subscribeSocketToChannel(ws, channel);
    queueEvent(ws, {
      type: 'subscribed',
      version: 1,
      entity: 'user',
      entity_id: String(ws.userData.userId),
      timestamp: now(),
      payload: { channel },
    });

    replayRecentEvents(ws, channel, payload.last_event_ts);
    metrics.increment('subscriptions.added');
    return;
  }

  if (type === 'unsubscribe') {
    if (typeof payload.channel === 'string') {
      unsubscribeSocketFromChannel(ws, payload.channel);
      queueEvent(ws, {
        type: 'unsubscribed',
        version: 1,
        entity: 'user',
        entity_id: String(ws.userData.userId),
        timestamp: now(),
        payload: { channel: payload.channel },
      });
      metrics.increment('subscriptions.removed');
    }
    return;
  }

  if (type === 'ping') {
    ws.userData.lastPong = Date.now();
    queueEvent(ws, {
      type: 'pong',
      version: 1,
      entity: 'user',
      entity_id: String(ws.userData.userId),
      timestamp: now(),
      payload: {},
    });
  }
}

const httpServer = http.createServer((req, res) => {
  if (req.url === '/health') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({
      status: 'ok',
      connections: connections.count,
      uptime: process.uptime(),
      memory: process.memoryUsage().rss,
      timestamp: new Date().toISOString(),
      engine: uWS ? 'uwebsockets' : 'ws',
    }));
    return;
  }

  if (req.url === '/metrics') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify(metrics.snapshot()));
    return;
  }

  res.writeHead(404, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify({ error: 'not_found' }));
});

const wss = new WebSocket.Server({ noServer: true, perMessageDeflate: false });

httpServer.on('upgrade', async (req, socket, head) => {
  if (!config.realtimeEnabled) {
    socket.write('HTTP/1.1 503 Service Unavailable\\r\\n\\r\\n');
    socket.destroy();
    return;
  }

  const parsed = url.parse(req.url, true);
  if (parsed.pathname !== '/ws' && parsed.pathname !== '/ws/') {
    socket.destroy();
    return;
  }

  const token = parsed.query?.token;
  if (!token || typeof token !== 'string') {
    socket.write('HTTP/1.1 401 Unauthorized\\r\\n\\r\\n');
    socket.destroy();
    metrics.increment('auth.rejected_no_token');
    return;
  }

  try {
    const session = await auth.validateToken(token);
    if (!session) {
      socket.write('HTTP/1.1 401 Unauthorized\\r\\n\\r\\n');
      socket.destroy();
      metrics.increment('auth.rejected_invalid');
      return;
    }

    wss.handleUpgrade(req, socket, head, (ws) => {
      ws.userData = {
        userId: session.user_id,
        email: session.email,
        sessionToken: token,
        sessionExpiresAtMs: session.expires_at_ms || null,
        connectedAt: Date.now(),
        subscriptions: new Set(),
        pendingMessages: [],
        droppedMessages: 0,
        slowClientDetected: false,
        lastPong: Date.now(),
      };
      wss.emit('connection', ws, req);
      metrics.increment('auth.success');
    });
  } catch (err) {
    logger.error('[AUTH] Error validando token:', err.message);
    socket.write('HTTP/1.1 500 Internal Server Error\\r\\n\\r\\n');
    socket.destroy();
    metrics.increment('auth.error');
  }
});

wss.on('connection', (ws) => {
  const userId = ws.userData.userId;
  connections.add(ws);
  subscribeSocketToChannel(ws, `user:${userId}`);

  queueEvent(ws, {
    type: 'connection.established',
    version: 1,
    entity: 'user',
    entity_id: String(userId),
    timestamp: now(),
    payload: {
      user_id: userId,
      server_time: new Date().toISOString(),
      heartbeat_interval: config.ws.heartbeatInterval,
    },
  });

  metrics.gauge('connections.active', connections.count);

  ws.on('message', (raw) => {
    const text = typeof raw === 'string' ? raw : raw.toString('utf-8');
    handleClientMessage(ws, text);
    metrics.increment('messages.received');
  });

  ws.on('close', () => {
    cleanupSocketSubscriptions(ws);
    connections.remove(ws);
    metrics.gauge('connections.active', connections.count);
    metrics.increment('connections.closed');
  });

  ws.on('error', () => {
    metrics.increment('connections.errors');
  });
});

async function start() {
  if (!config.realtimeEnabled) {
    logger.warn('[GATEWAY] realtime_enabled=false, gateway no iniciará listeners');
    return;
  }

  const publisher = { publish };
  await createRedisBridge(config.redis, publisher, metrics);

  setInterval(pruneDedupCache, 30_000);

  setInterval(async () => {
    const sockets = connections._sockets ? Array.from(connections._sockets) : [];
    for (const ws of sockets) {
      try {
        if (closeIfSessionExpired(ws)) continue;

        const fresh = await auth.validateSessionStillActive(ws.userData.sessionToken);
        if (!fresh) {
          metrics.increment('auth.session_revoked_close');
          ws.close(4401, 'session_invalid');
          continue;
        }

        if (fresh.expires_at_ms) {
          ws.userData.sessionExpiresAtMs = fresh.expires_at_ms;
        }

        flushPendingQueue(ws);
      } catch (err) {
        logger.warn('[AUTH] Falló revalidación de sesión:', err.message);
      }
    }
  }, 60_000);

  httpServer.listen(config.port, () => {
    logger.info(`[GATEWAY] Realtime Gateway escuchando en puerto ${config.port} (engine=ws${uWS ? '+uWS-optional' : ''})`);
  });
}

function shutdown(signal) {
  logger.info(`[GATEWAY] Recibido ${signal}, cerrando conexiones...`);
  connections.broadcastAll({
    type: 'server.shutdown',
    version: 1,
    entity: 'server',
    entity_id: 'gateway',
    timestamp: now(),
    payload: { reason: 'server_restart', reconnect_delay_ms: 3000 },
  });

  setTimeout(() => {
    try {
      rateLimiter.destroy();
    } catch (_) {}
    auth.close().catch(() => {});
    httpServer.close(() => process.exit(0));
  }, 1000);
}

process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('SIGINT', () => shutdown('SIGINT'));

process.on('uncaughtException', (err) => {
  logger.error('[FATAL] Excepción no manejada:', err);
  metrics.increment('errors.uncaught');
});

process.on('unhandledRejection', (err) => {
  logger.error('[FATAL] Promise rechazada:', err);
  metrics.increment('errors.unhandled_rejection');
});

start().catch((err) => {
  logger.error('[GATEWAY] Error al iniciar:', err);
  process.exit(1);
});
