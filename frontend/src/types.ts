/**
 * Wire types shared by the API client and the real-time client.
 *
 * These mirror contracts/openapi.yaml and contracts/websocket-events.md. Keeping them in
 * one place means a contract change breaks the build in one obvious spot instead of
 * silently drifting.
 */

/** The OpenAPI `Item` schema. */
export interface Item {
  readonly id: number;
  readonly name: string;
}

/** The OpenAPI `Error` schema. */
export interface ApiErrorBody {
  readonly code: string;
  readonly message: string;
  readonly details?: Readonly<Record<string, readonly string[]>>;
}

export const ITEM_EVENT_TYPES = ['item.created', 'item.updated', 'item.deleted'] as const;

export type ItemEventType = (typeof ITEM_EVENT_TYPES)[number];

/** The WebSocket event envelope. */
export interface ItemEvent {
  readonly eventId: string;
  readonly type: ItemEventType;
  readonly itemId: number;
  readonly item: Item | null;
  readonly occurredAt: string;
}
