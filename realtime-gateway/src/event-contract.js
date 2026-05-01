'use strict';

const ALLOWED_TYPES = new Set([
  'trip.status_changed',
  'trip.location_updated',
  'trip.assigned',
  'trip.cancelled',
  'request.new',
  'chat.message',
  // Compatibilidad controlada para transición.
  'request.status_changed',
  'connection.established',
  'server.shutdown',
  'error',
  'pong',
  'subscribed',
  'unsubscribed',
]);

function nowEpoch() {
  return Math.floor(Date.now() / 1000);
}

function normalizeEvent(input) {
  if (!input || typeof input !== 'object') return null;

  const type = typeof input.type === 'string' ? input.type : '';
  if (!ALLOWED_TYPES.has(type)) return null;

  const version = Number.isInteger(input.version) ? input.version : 1;
  const entity = typeof input.entity === 'string' ? input.entity : null;
  const entityId = input.entity_id != null ? String(input.entity_id) : null;
  const timestamp = Number.isFinite(input.timestamp)
    ? Math.trunc(input.timestamp)
    : nowEpoch();
  const payload = input.payload && typeof input.payload === 'object'
    ? input.payload
    : {};

  if (!entity || !entityId) return null;

  return {
    type,
    version,
    entity,
    entity_id: entityId,
    timestamp,
    payload,
    event_id: typeof input.event_id === 'string'
      ? input.event_id
      : `${type}:${entity}:${entityId}:${timestamp}`,
    channels: Array.isArray(input.channels)
      ? input.channels.filter((c) => typeof c === 'string')
      : [],
  };
}

module.exports = {
  ALLOWED_TYPES,
  normalizeEvent,
  nowEpoch,
};
