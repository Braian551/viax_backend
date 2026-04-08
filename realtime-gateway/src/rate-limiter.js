'use strict';

/**
 * Rate limiter por conexión basado en ventana deslizante.
 * Previene abuso de mensajes por un solo cliente.
 */

class RateLimiter {
  /**
   * @param {object} config - {maxMessages, windowMs}
   */
  constructor(config) {
    this._max = config.maxMessages;
    this._window = config.windowMs;
    this._buckets = new Map();

    // Limpieza periódica de buckets expirados (cada 30s)
    this._cleanupInterval = setInterval(() => this._cleanup(), 30_000);
  }

  /**
   * Verifica si el userId tiene permiso para enviar un mensaje.
   * @param {number} userId
   * @returns {boolean} true si permitido
   */
  allow(userId) {
    const now = Date.now();
    let bucket = this._buckets.get(userId);

    if (!bucket) {
      bucket = { count: 0, windowStart: now };
      this._buckets.set(userId, bucket);
    }

    // Ventana expirada → resetear
    if (now - bucket.windowStart > this._window) {
      bucket.count = 0;
      bucket.windowStart = now;
    }

    bucket.count++;
    return bucket.count <= this._max;
  }

  _cleanup() {
    const now = Date.now();
    for (const [key, bucket] of this._buckets) {
      if (now - bucket.windowStart > this._window * 2) {
        this._buckets.delete(key);
      }
    }
  }

  destroy() {
    clearInterval(this._cleanupInterval);
    this._buckets.clear();
  }
}

module.exports = { RateLimiter };
