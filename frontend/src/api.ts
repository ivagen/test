import type { ApiErrorBody, Item } from './types.js';

/**
 * A failed API call, carrying the server's stable machine-readable envelope.
 *
 * The UI needs `code` to decide how to react and `details` to point at the offending
 * field; it must never have to parse a human sentence to work out what happened.
 */
export class ApiError extends Error {
  constructor(
    readonly status: number,
    readonly code: string,
    message: string,
    readonly details: Readonly<Record<string, readonly string[]>> = {},
  ) {
    super(message);
    this.name = 'ApiError';
  }

  /** Flattened field messages, in the order the server returned them. */
  get fieldMessages(): string[] {
    return Object.values(this.details).flat();
  }

  /** The message best suited to showing a user. */
  get displayMessage(): string {
    const [first] = this.fieldMessages;

    return first ?? this.message;
  }
}

/**
 * Typed client for /api/items.
 *
 * Every request is same-origin and relative, so the page works unchanged on http://
 * locally and behind TLS in production. Mutations always carry the CSRF token the server
 * published in a <meta> tag (FR-010).
 */
export class ApiClient {
  constructor(
    private readonly csrfToken: string,
    private readonly fetchImpl: typeof fetch = globalThis.fetch.bind(globalThis),
  ) {}

  /**
   * Reads the token the server rendered into the page. A missing token is a deployment
   * fault, not a user error, so it fails loudly rather than silently issuing requests that
   * would all be rejected with 403.
   */
  static csrfTokenFromDocument(doc: Document = document): string {
    const meta = doc.querySelector('meta[name="csrf-token"]');
    const token = meta?.getAttribute('content');

    if (!token) {
      throw new Error('The page is missing its csrf-token meta tag.');
    }

    return token;
  }

  async list(): Promise<Item[]> {
    const body = await this.request<{ items: Item[] }>('GET', '/api/items');

    return body?.items ?? [];
  }

  async create(name: string): Promise<Item> {
    const item = await this.request<Item>('POST', '/api/items', { name });

    return this.requireBody(item);
  }

  async update(id: number, name: string): Promise<Item> {
    const item = await this.request<Item>('PUT', `/api/items/${String(id)}`, { name });

    return this.requireBody(item);
  }

  async remove(id: number): Promise<void> {
    await this.request<null>('DELETE', `/api/items/${String(id)}`);
  }

  private requireBody<T>(value: T | null): T {
    if (value === null) {
      throw new ApiError(500, 'internal_error', 'The server returned an empty response.');
    }

    return value;
  }

  private async request<T>(method: string, path: string, payload?: unknown): Promise<T | null> {
    const headers: Record<string, string> = { Accept: 'application/json' };

    if (method !== 'GET') {
      headers['X-CSRF-Token'] = this.csrfToken;
    }

    if (payload !== undefined) {
      headers['Content-Type'] = 'application/json';
    }

    const response = await this.fetchImpl(path, {
      method,
      headers,
      // Cookies carry the CSRF secret the token is validated against.
      credentials: 'same-origin',
      ...(payload === undefined ? {} : { body: JSON.stringify(payload) }),
    });

    // 204 No Content: the contract says the body is empty, so do not try to parse one.
    if (response.status === 204) {
      return null;
    }

    const text = await response.text();
    let parsed: unknown = null;

    if (text !== '') {
      try {
        parsed = JSON.parse(text);
      } catch {
        // A non-JSON body from an endpoint that promises JSON means something upstream
        // (a proxy, a crash page) answered instead of the application.
        throw new ApiError(
          response.status,
          'internal_error',
          'The server returned a response that could not be read.',
        );
      }
    }

    if (!response.ok) {
      throw this.toApiError(response.status, parsed);
    }

    return parsed as T;
  }

  private toApiError(status: number, parsed: unknown): ApiError {
    if (typeof parsed === 'object' && parsed !== null && 'code' in parsed && 'message' in parsed) {
      const body = parsed as ApiErrorBody;

      return new ApiError(status, body.code, body.message, body.details ?? {});
    }

    return new ApiError(status, 'internal_error', `Request failed with status ${String(status)}.`);
  }
}
