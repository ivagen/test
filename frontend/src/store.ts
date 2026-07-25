import type { Item, ItemEvent } from './types.js';

/** How many recent event ids are remembered for de-duplication. */
const SEEN_EVENT_LIMIT = 256;

/**
 * The client's view of the list.
 *
 * Implements client rules 2, 3 and 5 of contracts/websocket-events.md: bounded
 * de-duplication, idempotent typed application, and convergence to ascending-id order.
 *
 * The store never fetches anything. Whether an event can be applied at all is decided by
 * `events.ts`, and anything ambiguous is resolved by the caller refetching and calling
 * `replaceAll`. Keeping those responsibilities apart is what makes each of them testable.
 */
export class ItemStore {
  private items: Item[] = [];
  private readonly seenEventIds = new Set<string>();
  private readonly seenOrder: string[] = [];

  /** Authoritative state from GET /api/items; discards any accumulated uncertainty. */
  replaceAll(items: readonly Item[]): void {
    this.items = [...items].sort(byId);
  }

  get all(): readonly Item[] {
    return this.items;
  }

  find(id: number): Item | undefined {
    return this.items.find((item) => item.id === id);
  }

  /**
   * Applies one validated event.
   *
   * @returns true when the store changed, false when the event was a duplicate.
   */
  apply(event: ItemEvent): boolean {
    if (this.seenEventIds.has(event.eventId)) {
      return false;
    }

    this.remember(event.eventId);

    switch (event.type) {
      case 'item.created':
      case 'item.updated':
        // Upsert, not push: a create that arrives twice under different event ids, or
        // after the list was already refetched, must not produce a duplicate row.
        if (event.item !== null) {
          this.upsert(event.item);
        }

        return true;

      case 'item.deleted':
        // Deleting something already absent is normal (the list may have been refetched
        // in between) and is not an error.
        this.items = this.items.filter((item) => item.id !== event.itemId);

        return true;
    }
  }

  private upsert(item: Item): void {
    const index = this.items.findIndex((existing) => existing.id === item.id);

    if (index === -1) {
      this.items = [...this.items, item].sort(byId);

      return;
    }

    const next = [...this.items];
    next[index] = item;
    this.items = next;
  }

  /**
   * Keeps the de-duplication set bounded so a long-lived page cannot grow it without limit.
   */
  private remember(eventId: string): void {
    this.seenEventIds.add(eventId);
    this.seenOrder.push(eventId);

    while (this.seenOrder.length > SEEN_EVENT_LIMIT) {
      const oldest = this.seenOrder.shift();

      if (oldest !== undefined) {
        this.seenEventIds.delete(oldest);
      }
    }
  }
}

function byId(a: Item, b: Item): number {
  return a.id - b.id;
}
