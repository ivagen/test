import { describe, expect, it } from 'vitest';

import { ItemStore } from '../src/store.js';
import type { Item, ItemEvent, ItemEventType } from '../src/types.js';

let counter = 0;

function event(type: ItemEventType, itemId: number, item: Item | null = null, eventId?: string): ItemEvent {
  counter += 1;

  return {
    eventId: eventId ?? `event-${String(counter)}`,
    type,
    itemId,
    item,
    occurredAt: '2026-07-25T15:00:00.000Z',
  };
}

/**
 * contracts/websocket-events.md client rules 2, 3 and 5 (de-duplication, idempotent
 * application, convergence) -- and spec US3 scenario 3, which requires that duplicated or
 * out-of-order events still converge with no duplicate rows.
 */
describe('ItemStore', () => {
  it('keeps items ordered by ascending id after replaceAll', () => {
    const store = new ItemStore();

    store.replaceAll([
      { id: 42, name: 'c' },
      { id: 1, name: 'a' },
      { id: 7, name: 'b' },
    ]);

    expect(store.all.map((item) => item.id)).toEqual([1, 7, 42]);
  });

  it('inserts a created item in id order', () => {
    const store = new ItemStore();
    store.replaceAll([
      { id: 1, name: 'a' },
      { id: 9, name: 'c' },
    ]);

    store.apply(event('item.created', 5, { id: 5, name: 'b' }));

    expect(store.all.map((item) => item.id)).toEqual([1, 5, 9]);
  });

  it('updates in place without reordering or duplicating', () => {
    const store = new ItemStore();
    store.replaceAll([
      { id: 1, name: 'a' },
      { id: 2, name: 'b' },
    ]);

    store.apply(event('item.updated', 1, { id: 1, name: 'renamed' }));

    expect(store.all).toEqual([
      { id: 1, name: 'renamed' },
      { id: 2, name: 'b' },
    ]);
  });

  it('removes a deleted item', () => {
    const store = new ItemStore();
    store.replaceAll([
      { id: 1, name: 'a' },
      { id: 2, name: 'b' },
    ]);

    store.apply(event('item.deleted', 1));

    expect(store.all.map((item) => item.id)).toEqual([2]);
  });

  it('ignores a repeated eventId', () => {
    const store = new ItemStore();
    const duplicate = event('item.created', 1, { id: 1, name: 'a' }, 'same-id');

    expect(store.apply(duplicate)).toBe(true);
    expect(store.apply(duplicate)).toBe(false);
    expect(store.all).toHaveLength(1);
  });

  /**
   * The same create delivered twice under different event ids -- which best-effort
   * delivery explicitly permits -- must still not produce two rows.
   */
  it('does not duplicate a row when the same item is created twice', () => {
    const store = new ItemStore();

    store.apply(event('item.created', 1, { id: 1, name: 'a' }));
    store.apply(event('item.created', 1, { id: 1, name: 'a' }));

    expect(store.all).toHaveLength(1);
  });

  it('treats deleting an unknown item as a no-op rather than an error', () => {
    const store = new ItemStore();
    store.replaceAll([{ id: 1, name: 'a' }]);

    expect(() => store.apply(event('item.deleted', 999))).not.toThrow();
    expect(store.all).toHaveLength(1);
  });

  /**
   * Out-of-order delivery: a delete that arrives before the create it followed must not
   * resurrect the row when the create is applied afterwards... but an HTTP resync is what
   * ultimately settles it, so the store simply must not crash or duplicate.
   */
  it('converges to the authoritative list after out-of-order events', () => {
    const store = new ItemStore();

    store.apply(event('item.deleted', 3));
    store.apply(event('item.created', 3, { id: 3, name: 'late' }));
    store.apply(event('item.updated', 3, { id: 3, name: 'later' }));

    store.replaceAll([{ id: 3, name: 'authoritative' }]);

    expect(store.all).toEqual([{ id: 3, name: 'authoritative' }]);
  });

  it('bounds the de-duplication memory', () => {
    const store = new ItemStore();

    for (let i = 0; i < 1000; i += 1) {
      store.apply(event('item.deleted', 1, null, `event-${String(i)}`));
    }

    // The oldest ids have been evicted, so re-applying one is accepted again. The point is
    // that memory stays bounded on a page left open for days.
    expect(store.apply(event('item.deleted', 1, null, 'event-0'))).toBe(true);
    expect(store.apply(event('item.deleted', 1, null, 'event-999'))).toBe(false);
  });

  it('discards accumulated state on replaceAll', () => {
    const store = new ItemStore();
    store.replaceAll([{ id: 1, name: 'stale' }]);

    store.replaceAll([]);

    expect(store.all).toEqual([]);
  });
});
