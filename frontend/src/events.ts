import { ITEM_EVENT_TYPES, type Item, type ItemEvent, type ItemEventType } from './types.js';

/**
 * Validates an incoming WebSocket frame against contracts/websocket-events.md.
 *
 * Returning `null` rather than throwing is the point: the contract says an unreadable,
 * unknown-type or structurally wrong frame must trigger an HTTP resynchronisation, not a
 * crash. `null` therefore means "I cannot trust this -- go ask the API", which is always a
 * safe answer and is what makes the client converge no matter what arrives on the socket.
 *
 * This also covers data-model.md's rule about unknown *versions*: the envelope carries no
 * version field, so any future shape this build does not understand fails validation here
 * and resynchronises, which is exactly the behaviour that rule asks for.
 */
export function parseItemEvent(raw: string): ItemEvent | null {
  let parsed: unknown;

  try {
    parsed = JSON.parse(raw);
  } catch {
    return null;
  }

  if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) {
    return null;
  }

  const candidate = parsed as Record<string, unknown>;

  if (typeof candidate['eventId'] !== 'string' || candidate['eventId'] === '') {
    return null;
  }

  if (!isItemEventType(candidate['type'])) {
    return null;
  }

  if (!isPositiveInteger(candidate['itemId'])) {
    return null;
  }

  if (typeof candidate['occurredAt'] !== 'string') {
    return null;
  }

  const item = parseItem(candidate['item']);

  // create/update must carry a representation; delete must not.
  if (candidate['type'] === 'item.deleted') {
    if (item !== null) {
      return null;
    }
  } else if (item === null || item.id !== candidate['itemId']) {
    return null;
  }

  return {
    eventId: candidate['eventId'],
    type: candidate['type'],
    itemId: candidate['itemId'],
    item,
    occurredAt: candidate['occurredAt'],
  };
}

function parseItem(value: unknown): Item | null {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) {
    return null;
  }

  const candidate = value as Record<string, unknown>;

  if (!isPositiveInteger(candidate['id']) || typeof candidate['name'] !== 'string') {
    return null;
  }

  return { id: candidate['id'], name: candidate['name'] };
}

function isItemEventType(value: unknown): value is ItemEventType {
  return typeof value === 'string' && (ITEM_EVENT_TYPES as readonly string[]).includes(value);
}

function isPositiveInteger(value: unknown): value is number {
  return typeof value === 'number' && Number.isInteger(value) && value >= 1;
}
