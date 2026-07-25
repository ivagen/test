import { expect, test, type Page } from '@playwright/test';

import { cleanUp, createItem, openApp, rowFor, uniqueName } from './helpers.js';

/**
 * Two-session propagation (spec US3, SC-004, quickstart.md "Real-time acceptance").
 *
 * SC-004 requires a second browser to reflect a change within two seconds. The timing is
 * measured and asserted, not merely awaited, and the measured value is printed so the
 * final report can quote a real number.
 */
const PREFIX = 'e2e-realtime';
const PROPAGATION_BUDGET_MS = 2000;

async function openTwoSessions(browser: Parameters<Parameters<typeof test>[2]>[0]['browser']): Promise<{
  a: Page;
  b: Page;
  close: () => Promise<void>;
}> {
  // Separate contexts, so these are genuinely two independent sessions with their own
  // cookies and their own WebSocket -- not two tabs sharing state.
  const contextA = await browser.newContext();
  const contextB = await browser.newContext();
  const a = await contextA.newPage();
  const b = await contextB.newPage();

  await openApp(a);
  await openApp(b);

  await expect(a.locator('#realtime-status')).toHaveAttribute('data-realtime-state', 'connected');
  await expect(b.locator('#realtime-status')).toHaveAttribute('data-realtime-state', 'connected');

  return {
    a,
    b,
    close: async () => {
      await contextA.close();
      await contextB.close();
    },
  };
}

test.describe('real-time propagation', () => {
  test.afterEach(async ({ page }) => {
    await cleanUp(page, PREFIX);
  });

  test('a create in one session appears in another within two seconds', async ({ browser }) => {
    const { a, b, close } = await openTwoSessions(browser);

    try {
      const name = uniqueName(PREFIX);

      const started = Date.now();
      await createItem(a, name);
      await expect(rowFor(b, name)).toHaveCount(1, { timeout: PROPAGATION_BUDGET_MS });
      const elapsed = Date.now() - started;

      console.log(`propagation(create) = ${String(elapsed)}ms`);
      expect(elapsed).toBeLessThan(PROPAGATION_BUDGET_MS);
    } finally {
      await close();
    }
  });

  test('a rename in one session appears in another within two seconds', async ({ browser }) => {
    const { a, b, close } = await openTwoSessions(browser);

    try {
      const original = uniqueName(PREFIX);
      const renamed = uniqueName(PREFIX);

      await createItem(a, original);
      await expect(rowFor(b, original)).toHaveCount(1, { timeout: PROPAGATION_BUDGET_MS });

      const started = Date.now();
      await rowFor(a, original).locator('[data-action="edit"]').click();
      await a.fill('#edit-name', renamed);
      await a.click('#edit-form button[type="submit"]');

      await expect(rowFor(b, renamed)).toHaveCount(1, { timeout: PROPAGATION_BUDGET_MS });
      const elapsed = Date.now() - started;

      console.log(`propagation(update) = ${String(elapsed)}ms`);
      expect(elapsed).toBeLessThan(PROPAGATION_BUDGET_MS);
      await expect(rowFor(b, original)).toHaveCount(0);
    } finally {
      await close();
    }
  });

  test('a delete in one session disappears in another within two seconds', async ({ browser }) => {
    const { a, b, close } = await openTwoSessions(browser);

    try {
      const name = uniqueName(PREFIX);

      await createItem(a, name);
      await expect(rowFor(b, name)).toHaveCount(1, { timeout: PROPAGATION_BUDGET_MS });

      const started = Date.now();
      await rowFor(a, name).locator('[data-action="delete"]').click();
      await expect(rowFor(b, name)).toHaveCount(0, { timeout: PROPAGATION_BUDGET_MS });
      const elapsed = Date.now() - started;

      console.log(`propagation(delete) = ${String(elapsed)}ms`);
      expect(elapsed).toBeLessThan(PROPAGATION_BUDGET_MS);
    } finally {
      await close();
    }
  });

  test('neither session ends up with a duplicate row', async ({ browser }) => {
    const { a, b, close } = await openTwoSessions(browser);

    try {
      const name = uniqueName(PREFIX);

      // The creating session renders from its own HTTP response AND receives its own
      // event. If de-duplication were wrong, this is where a second row would appear.
      await createItem(a, name);
      await expect(rowFor(b, name)).toHaveCount(1, { timeout: PROPAGATION_BUDGET_MS });

      await expect(rowFor(a, name)).toHaveCount(1);
      await expect(rowFor(b, name)).toHaveCount(1);
    } finally {
      await close();
    }
  });

  test('a reload converges to the same list in both sessions', async ({ browser }) => {
    const { a, b, close } = await openTwoSessions(browser);

    try {
      const name = uniqueName(PREFIX);

      await createItem(a, name);
      await expect(rowFor(b, name)).toHaveCount(1, { timeout: PROPAGATION_BUDGET_MS });

      await b.reload();
      await expect(b.locator('#loading-state')).toBeHidden();

      expect(await b.locator('#items-body tr th').allTextContents())
        .toEqual(await a.locator('#items-body tr th').allTextContents());
    } finally {
      await close();
    }
  });

  /**
   * spec US3 scenario 4: on an HTTPS page the socket must be wss on the SAME origin, with
   * no mixed content. Local acceptance runs over HTTP, so the assertion here is that the
   * URL is derived from the page origin rather than hard-coded -- which is the property
   * that makes wss work behind TLS.
   */
  test('connects to /ws on the page origin', async ({ page }) => {
    const sockets: string[] = [];
    page.on('websocket', (ws) => sockets.push(ws.url()));

    await openApp(page);
    await expect(page.locator('#realtime-status')).toHaveAttribute('data-realtime-state', 'connected');

    expect(sockets).toHaveLength(1);
    expect(sockets[0]).toBe(new URL('/ws', page.url()).toString().replace(/^http/, 'ws'));
  });
});
