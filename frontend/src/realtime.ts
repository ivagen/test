import { parseItemEvent } from './events.js';
import type { ItemEvent } from './types.js';

export type RealtimeState = 'connecting' | 'connected' | 'disconnected';

/** Backoff parameters from contracts/websocket-events.md, client rule 4: the cap is 30s. */
const BASE_DELAY_MS = 500;
const MAX_DELAY_MS = 30_000;

/** Kept from the 2017 client: works through proxies that hide protocol-level pings. */
const HEARTBEAT_INTERVAL_MS = 25_000;

export interface RealtimeOptions {
  readonly onEvent: (event: ItemEvent) => void;
  /** Called when the client cannot trust incremental state and must refetch. */
  readonly onResync: () => void;
  readonly onStateChange: (state: RealtimeState) => void;
  /** Overridable for tests; production uses the page's own origin. */
  readonly url?: string;
  readonly socketFactory?: (url: string) => WebSocket;
  readonly random?: () => number;
}

/**
 * The WebSocket half of the client.
 *
 * Three properties matter and each is enforced here rather than left to chance:
 *
 *  1. Same origin. The URL is derived from `location`, so an HTTPS page automatically uses
 *     `wss` and no mixed-content request is ever issued (spec US3 scenario 4). The 2017
 *     client hard-coded `ws://<host>:8047`, which could not be secured at all.
 *  2. Never trust the socket. Every connect -- including the very first -- triggers an HTTP
 *     resync, and so does anything unparseable. The socket only ever accelerates updates.
 *  3. Bounded, jittered reconnection, so a restarted worker is not hit by every open tab
 *     at the same instant.
 */
export class RealtimeClient {
  private socket: WebSocket | null = null;
  private attempt = 0;
  private stopped = false;
  private reconnectTimer: ReturnType<typeof setTimeout> | null = null;
  private heartbeatTimer: ReturnType<typeof setInterval> | null = null;
  private state: RealtimeState = 'disconnected';

  constructor(private readonly options: RealtimeOptions) {}

  /**
   * Builds the socket URL from the current origin. Never hard-code a host or a port here:
   * that is what tied the old client to plain HTTP.
   */
  static sameOriginUrl(location: { protocol: string; host: string } = window.location): string {
    const scheme = location.protocol === 'https:' ? 'wss:' : 'ws:';

    return `${scheme}//${location.host}/ws`;
  }

  get currentState(): RealtimeState {
    return this.state;
  }

  start(): void {
    this.stopped = false;
    this.open();
  }

  stop(): void {
    this.stopped = true;
    this.clearTimers();

    const socket = this.socket;
    this.socket = null;
    socket?.close();

    this.setState('disconnected');
  }

  private open(): void {
    if (this.stopped) {
      return;
    }

    this.setState(this.attempt === 0 ? 'connecting' : 'disconnected');

    const url = this.options.url ?? RealtimeClient.sameOriginUrl();
    const socket = (this.options.socketFactory ?? ((target: string) => new WebSocket(target)))(url);

    this.socket = socket;

    socket.onopen = (): void => {
      this.attempt = 0;
      this.setState('connected');
      this.startHeartbeat();

      // Rule 4: a fresh connection may have missed events while it was down, and there is
      // no way to know which. Refetching is the only honest recovery.
      this.options.onResync();
    };

    socket.onmessage = (message: MessageEvent<unknown>): void => {
      this.handleMessage(message.data);
    };

    socket.onclose = (): void => {
      this.handleDisconnect();
    };

    socket.onerror = (): void => {
      // `close` always follows `error`; reconnection is handled there so it cannot run twice.
      this.setState('disconnected');
    };
  }

  private handleMessage(data: unknown): void {
    if (typeof data !== 'string') {
      this.options.onResync();

      return;
    }

    // Liveness reply, not application state -- must never reach the item handling.
    if (data === 'pong') {
      return;
    }

    const event = parseItemEvent(data);

    if (event === null) {
      this.options.onResync();

      return;
    }

    this.options.onEvent(event);
  }

  private handleDisconnect(): void {
    this.clearTimers();
    this.socket = null;

    if (this.stopped) {
      return;
    }

    this.setState('disconnected');
    this.scheduleReconnect();
  }

  /** Exponential backoff with full jitter, capped by the contract at 30 seconds. */
  private scheduleReconnect(): void {
    const exponential = BASE_DELAY_MS * 2 ** Math.min(this.attempt, 10);
    const capped = Math.min(MAX_DELAY_MS, exponential);
    const random = this.options.random ?? Math.random;
    const delay = Math.round(capped * (0.5 + random() * 0.5));

    this.attempt += 1;

    this.reconnectTimer = setTimeout(() => {
      this.reconnectTimer = null;
      this.open();
    }, delay);
  }

  private startHeartbeat(): void {
    this.heartbeatTimer = setInterval(() => {
      if (this.socket?.readyState === WebSocket.OPEN) {
        this.socket.send('ping');
      }
    }, HEARTBEAT_INTERVAL_MS);
  }

  private clearTimers(): void {
    if (this.reconnectTimer !== null) {
      clearTimeout(this.reconnectTimer);
      this.reconnectTimer = null;
    }

    if (this.heartbeatTimer !== null) {
      clearInterval(this.heartbeatTimer);
      this.heartbeatTimer = null;
    }
  }

  private setState(state: RealtimeState): void {
    if (this.state === state) {
      return;
    }

    this.state = state;
    this.options.onStateChange(state);
  }
}
