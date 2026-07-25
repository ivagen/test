import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { RealtimeClient, type RealtimeState } from '../src/realtime.js';
import type { ItemEvent } from '../src/types.js';

/** Minimal WebSocket stand-in: jsdom has no server to connect to. */
class FakeSocket {
  static instances: FakeSocket[] = [];

  onopen: (() => void) | null = null;
  onclose: (() => void) | null = null;
  onerror: (() => void) | null = null;
  onmessage: ((event: MessageEvent<unknown>) => void) | null = null;

  readyState = 1;
  readonly sent: string[] = [];
  closed = false;

  constructor(readonly url: string) {
    FakeSocket.instances.push(this);
  }

  send(data: string): void {
    this.sent.push(data);
  }

  close(): void {
    this.closed = true;
    this.onclose?.();
  }

  open(): void {
    this.onopen?.();
  }

  receive(data: unknown): void {
    this.onmessage?.({ data } as MessageEvent<unknown>);
  }
}

interface Harness {
  client: RealtimeClient;
  events: ItemEvent[];
  states: RealtimeState[];
  resyncs: () => number;
}

function harness(random = (): number => 1): Harness {
  const events: ItemEvent[] = [];
  const states: RealtimeState[] = [];
  let resyncs = 0;

  const client = new RealtimeClient({
    url: 'ws://test/ws',
    socketFactory: (url) => new FakeSocket(url) as unknown as WebSocket,
    random,
    onEvent: (event) => events.push(event),
    onResync: () => {
      resyncs += 1;
    },
    onStateChange: (state) => states.push(state),
  });

  return { client, events, states, resyncs: () => resyncs };
}

const validEvent = JSON.stringify({
  eventId: 'e1',
  type: 'item.created',
  itemId: 1,
  item: { id: 1, name: 'Milk' },
  occurredAt: '2026-07-25T15:00:00.000Z',
});

beforeEach(() => {
  FakeSocket.instances = [];
  vi.useFakeTimers();
});

afterEach(() => {
  vi.useRealTimers();
});

describe('RealtimeClient URL', () => {
  it('uses ws on a plain HTTP page', () => {
    expect(RealtimeClient.sameOriginUrl({ protocol: 'http:', host: 'example.test:8080' }))
      .toBe('ws://example.test:8080/ws');
  });

  /**
   * spec US3 scenario 4: an HTTPS page must upgrade to wss on its OWN origin, so no
   * mixed-content request is ever made. The 2017 client hard-coded ws://host:8047.
   */
  it('uses wss on an HTTPS page and keeps the same host', () => {
    expect(RealtimeClient.sameOriginUrl({ protocol: 'https:', host: 'example.test' }))
      .toBe('wss://example.test/ws');
  });
});

describe('RealtimeClient', () => {
  it('resynchronises over HTTP on every connect', () => {
    const h = harness();
    h.client.start();

    FakeSocket.instances[0]?.open();

    expect(h.resyncs()).toBe(1);
    expect(h.client.currentState).toBe('connected');
  });

  it('delivers a valid typed event', () => {
    const h = harness();
    h.client.start();
    FakeSocket.instances[0]?.open();

    FakeSocket.instances[0]?.receive(validEvent);

    expect(h.events).toHaveLength(1);
    expect(h.events[0]?.type).toBe('item.created');
  });

  it('never treats a pong as application state', () => {
    const h = harness();
    h.client.start();
    FakeSocket.instances[0]?.open();
    const before = h.resyncs();

    FakeSocket.instances[0]?.receive('pong');

    expect(h.events).toHaveLength(0);
    expect(h.resyncs()).toBe(before);
  });

  it.each([
    ['malformed json', '{'],
    ['an unknown type', JSON.stringify({ eventId: 'e', type: 'item.archived', itemId: 1, item: null, occurredAt: 'x' })],
    ['a non-string frame', 42],
  ])('resynchronises instead of failing on %s', (_label, frame) => {
    const h = harness();
    h.client.start();
    FakeSocket.instances[0]?.open();
    const before = h.resyncs();

    FakeSocket.instances[0]?.receive(frame);

    expect(h.events).toHaveLength(0);
    expect(h.resyncs()).toBe(before + 1);
  });

  /**
   * FR-007: the user must be told the list may be stale. CRUD stays available -- that is
   * asserted end-to-end, because it is a property of the page, not of this class.
   */
  it('reports the disconnected state when the socket drops', () => {
    const h = harness();
    h.client.start();
    FakeSocket.instances[0]?.open();

    FakeSocket.instances[0]?.close();

    expect(h.client.currentState).toBe('disconnected');
    expect(h.states).toContain('disconnected');
  });

  it('reconnects and resynchronises after a drop', () => {
    const h = harness();
    h.client.start();
    FakeSocket.instances[0]?.open();
    FakeSocket.instances[0]?.close();

    vi.advanceTimersByTime(60_000);
    expect(FakeSocket.instances).toHaveLength(2);

    FakeSocket.instances[1]?.open();

    expect(h.resyncs()).toBe(2);
    expect(h.client.currentState).toBe('connected');
  });

  /**
   * Backoff grows, is capped at the contract's 30 seconds, and is jittered so that many
   * open tabs do not reconnect in lockstep.
   */
  it('backs off exponentially up to a 30 second cap', () => {
    const h = harness(() => 1); // full jitter at its maximum -> the raw capped delay
    h.client.start();

    const delays: number[] = [];
    let previousCount = 1;

    for (let attempt = 0; attempt < 9; attempt += 1) {
      FakeSocket.instances[FakeSocket.instances.length - 1]?.close();

      let waited = 0;
      while (FakeSocket.instances.length === previousCount && waited <= 60_000) {
        vi.advanceTimersByTime(100);
        waited += 100;
      }

      delays.push(waited);
      previousCount = FakeSocket.instances.length;
    }

    expect(delays[0]).toBeLessThanOrEqual(1000);
    // Monotonic until the cap.
    expect(delays[3]).toBeGreaterThan(delays[0] as number);
    for (const delay of delays) {
      expect(delay).toBeLessThanOrEqual(30_100);
    }
    expect(delays.at(-1)).toBeGreaterThanOrEqual(20_000);
  });

  it('applies jitter so reconnect delays differ', () => {
    const withMinJitter = harness(() => 0);
    const withMaxJitter = harness(() => 1);

    const measure = (h: Harness): number => {
      h.client.start();
      const socket = FakeSocket.instances[FakeSocket.instances.length - 1];
      const before = FakeSocket.instances.length;
      socket?.close();

      let waited = 0;
      while (FakeSocket.instances.length === before && waited <= 5000) {
        vi.advanceTimersByTime(10);
        waited += 10;
      }

      return waited;
    };

    expect(measure(withMinJitter)).toBeLessThan(measure(withMaxJitter));
  });

  it('sends a heartbeat while connected', () => {
    const h = harness();
    h.client.start();
    const socket = FakeSocket.instances[0];
    socket?.open();

    vi.advanceTimersByTime(26_000);

    expect(socket?.sent).toContain('ping');
  });

  it('stops reconnecting once stopped', () => {
    const h = harness();
    h.client.start();
    FakeSocket.instances[0]?.open();

    h.client.stop();
    vi.advanceTimersByTime(120_000);

    expect(FakeSocket.instances).toHaveLength(1);
  });
});
