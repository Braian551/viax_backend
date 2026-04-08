'use strict';

/**
 * Colector de métricas en memoria para observabilidad.
 * Expone contadores y gauges via /metrics endpoint.
 */

class MetricsCollector {
  constructor() {
    this._counters = {};
    this._gauges = {};
    this._startedAt = Date.now();
  }

  increment(name, value = 1) {
    this._counters[name] = (this._counters[name] || 0) + value;
  }

  gauge(name, value) {
    this._gauges[name] = value;
  }

  snapshot() {
    return {
      uptime_seconds: Math.floor((Date.now() - this._startedAt) / 1000),
      counters: { ...this._counters },
      gauges: { ...this._gauges },
      timestamp: new Date().toISOString(),
    };
  }

  /**
   * Resumen rápido para logs periódicos.
   */
  summary() {
    return {
      connections: this._gauges['connections.active'] || 0,
      events_fanout: this._counters['events.fanout'] || 0,
      redis_messages: this._counters['redis.messages_received'] || 0,
      auth_success: this._counters['auth.success'] || 0,
      errors: (this._counters['errors.uncaught'] || 0) +
              (this._counters['errors.unhandled_rejection'] || 0),
    };
  }
}

module.exports = { MetricsCollector };
