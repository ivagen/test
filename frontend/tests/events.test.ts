import { describe, expect, it } from 'vitest';

import { parseItemEvent } from '../src/events.js';

/**
 * contracts/websocket-events.md client rule 4: anything that cannot be trusted must return
 * null so the caller resynchronises over HTTP. Nothing here may throw -- a thrown error in
 * a socket handler would leave the page permanently stale.
 */
describe('parseItemEvent', () => {
  const valid = {
    eventId: '6db69aac-0f19-47f0-a24c-c57af9cf0d16',
    type: 'item.updated',
    itemId: 12,
    item: { id: 12, name: 'Updated name' },
    occurredAt: '2026-07-25T15:00:00.000Z',
  };

  it('accepts a well-formed create event', () => {
    const event = parseItemEvent(JSON.stringify({ ...valid, type: 'item.created' }));

    expect(event).not.toBeNull();
    expect(event?.type).toBe('item.created');
    expect(event?.item).toEqual({ id: 12, name: 'Updated name' });
  });

  it('accepts a delete event with a null item', () => {
    const event = parseItemEvent(JSON.stringify({ ...valid, type: 'item.deleted', item: null }));

    expect(event?.type).toBe('item.deleted');
    expect(event?.item).toBeNull();
  });

  it('preserves unicode names exactly', () => {
    const event = parseItemEvent(
      JSON.stringify({ ...valid, item: { id: 12, name: 'Хліб ☕ 🍰' } }),
    );

    expect(event?.item?.name).toBe('Хліб ☕ 🍰');
  });

  it.each([
    ['malformed json', '{"eventId": '],
    ['not an object', '"a string"'],
    ['an array', '[]'],
    ['null', 'null'],
    ['empty string', ''],
  ])('returns null for %s instead of throwing', (_label, raw) => {
    expect(() => parseItemEvent(raw)).not.toThrow();
    expect(parseItemEvent(raw)).toBeNull();
  });

  it.each([
    ['an unknown type', { ...valid, type: 'item.archived' }],
    ['a future envelope shape', { ...valid, type: 'item.v2.updated' }],
    ['a missing eventId', { ...valid, eventId: undefined }],
    ['an empty eventId', { ...valid, eventId: '' }],
    ['a non-numeric itemId', { ...valid, itemId: '12' }],
    ['a zero itemId', { ...valid, itemId: 0 }],
    ['a fractional itemId', { ...valid, itemId: 1.5 }],
    ['a missing occurredAt', { ...valid, occurredAt: undefined }],
    ['a create event with no item', { ...valid, type: 'item.created', item: null }],
    ['an update event with no item', { ...valid, item: null }],
    ['a delete event that still carries an item', { ...valid, type: 'item.deleted' }],
    ['an item whose id contradicts itemId', { ...valid, item: { id: 99, name: 'x' } }],
    ['an item with a non-string name', { ...valid, item: { id: 12, name: 42 } }],
  ])('returns null for %s', (_label, payload) => {
    expect(parseItemEvent(JSON.stringify(payload))).toBeNull();
  });
});
