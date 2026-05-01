'use strict';

/**
 * Gestor de conexiones WebSocket activas.
 * Provee operaciones de broadcast y limpieza.
 */

class ConnectionManager {
  /** @param {import('./metrics').MetricsCollector} metrics */
  constructor(metrics) {
    this._sockets = new Set();
    this._metrics = metrics;
  }

  get count() {
    return this._sockets.size;
  }

  add(ws) {
    this._sockets.add(ws);
    this._metrics.increment('connections.opened');
  }

  remove(ws) {
    this._sockets.delete(ws);
  }

  /**
   * Envía mensaje JSON a TODAS las conexiones activas.
   * Usado para shutdown/mantenimiento.
   */
  broadcastAll(data) {
    const payload = JSON.stringify(data);
    for (const ws of this._sockets) {
      try {
        ws.send(payload, false, false);
      } catch {
        // Socket ya cerrado, ignorar
      }
    }
  }
}

module.exports = { ConnectionManager };
