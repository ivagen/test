import { describe, expect, it, vi } from 'vitest';

import { ApiClient, ApiError } from '../src/api.js';

function jsonResponse(status: number, body: unknown): Response {
  return new Response(body === undefined ? '' : JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}

function clientWith(response: Response | (() => Response)): { client: ApiClient; fetchMock: ReturnType<typeof vi.fn> } {
  const fetchMock = vi.fn(() => Promise.resolve(typeof response === 'function' ? response() : response));

  return { client: new ApiClient('test-token', fetchMock), fetchMock };
}

describe('ApiClient', () => {
  it('reads the CSRF token from the meta tag', () => {
    document.head.innerHTML = '<meta name="csrf-token" content="abc123">';

    expect(ApiClient.csrfTokenFromDocument(document)).toBe('abc123');
  });

  /**
   * A missing token means every mutation would be rejected with 403. Failing loudly at
   * start-up is far easier to diagnose than a page where nothing can be saved.
   */
  it('throws when the meta tag is absent', () => {
    document.head.innerHTML = '';

    expect(() => ApiClient.csrfTokenFromDocument(document)).toThrow(/csrf-token/);
  });

  it('lists items from the items envelope', async () => {
    const { client, fetchMock } = clientWith(jsonResponse(200, { items: [{ id: 1, name: 'a' }] }));

    await expect(client.list()).resolves.toEqual([{ id: 1, name: 'a' }]);

    const [path, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(path).toBe('/api/items');
    expect(init.method).toBe('GET');
    // Reads are safe methods and must not require a token.
    expect((init.headers as Record<string, string>)['X-CSRF-Token']).toBeUndefined();
  });

  it('sends the CSRF token and a JSON body on create', async () => {
    const { client, fetchMock } = clientWith(jsonResponse(201, { id: 3, name: 'Milk' }));

    await expect(client.create('Milk')).resolves.toEqual({ id: 3, name: 'Milk' });

    const [path, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    const headers = init.headers as Record<string, string>;

    expect(path).toBe('/api/items');
    expect(init.method).toBe('POST');
    expect(headers['X-CSRF-Token']).toBe('test-token');
    expect(headers['Content-Type']).toBe('application/json');
    expect(init.credentials).toBe('same-origin');
    expect(init.body).toBe('{"name":"Milk"}');
  });

  it('uses the resource path on update', async () => {
    const { client, fetchMock } = clientWith(jsonResponse(200, { id: 7, name: 'Bread' }));

    await client.update(7, 'Bread');

    const [path, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(path).toBe('/api/items/7');
    expect(init.method).toBe('PUT');
  });

  it('accepts an empty 204 body on delete', async () => {
    const { client } = clientWith(new Response(null, { status: 204 }));

    await expect(client.remove(4)).resolves.toBeUndefined();
  });

  /**
   * The stable error envelope is what the UI reacts to; it must survive the client intact.
   */
  it('turns a 422 into an ApiError carrying code, message and details', async () => {
    const { client } = clientWith(
      jsonResponse(422, {
        code: 'validation_failed',
        message: 'The submitted data failed validation.',
        details: { name: ['Name cannot be blank.'] },
      }),
    );

    const error = await client.create('').catch((caught: unknown) => caught);

    expect(error).toBeInstanceOf(ApiError);
    expect((error as ApiError).status).toBe(422);
    expect((error as ApiError).code).toBe('validation_failed');
    expect((error as ApiError).details).toEqual({ name: ['Name cannot be blank.'] });
    expect((error as ApiError).displayMessage).toBe('Name cannot be blank.');
  });

  it('exposes a 404 so the caller can resynchronise', async () => {
    const { client } = clientWith(jsonResponse(404, { code: 'not_found', message: 'Gone.' }));

    const error = await client.remove(9).catch((caught: unknown) => caught);

    expect((error as ApiError).status).toBe(404);
    expect((error as ApiError).code).toBe('not_found');
  });

  it('exposes a 403 for a rejected CSRF token', async () => {
    const { client } = clientWith(jsonResponse(403, { code: 'forbidden', message: 'Rejected.' }));

    const error = await client.create('x').catch((caught: unknown) => caught);

    expect((error as ApiError).code).toBe('forbidden');
  });

  /**
   * A proxy error page or a crash can answer with HTML. The client must report a usable
   * error rather than throwing a JSON syntax error from deep inside itself.
   */
  it('reports a readable error when the response is not JSON', async () => {
    const { client } = clientWith(new Response('<html>502 Bad Gateway</html>', { status: 502 }));

    const error = await client.list().catch((caught: unknown) => caught);

    expect(error).toBeInstanceOf(ApiError);
    expect((error as ApiError).code).toBe('internal_error');
  });

  it('falls back to a generic error when the body has no envelope', async () => {
    const { client } = clientWith(jsonResponse(500, { unexpected: true }));

    const error = await client.list().catch((caught: unknown) => caught);

    expect((error as ApiError).code).toBe('internal_error');
    expect((error as ApiError).status).toBe(500);
  });
});
