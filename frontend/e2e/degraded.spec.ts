import { expect, test, type Page } from '@playwright/test';

import { cleanUp, createItem, openApp, rowFor, uniqueName } from './helpers.js';

/**
 * Behaviour during and after a real-time outage
 * (spec US3 scenario 2, FR-006, FR-007, quickstart.md "Real-time acceptance" items 4-5).
 *
 * The outage is injected at the network layer with Playwright's WebSocket routing, which
 * transparently proxies to the REAL worker and then drops the connection on demand. From
 * the browser's point of view that is indistinguishable from the worker being restarted,
 * and it lets one test observe a single page across the whole disconnect/reconnect cycle.
 *
 * A genuine `docker compose stop websocket` is exercised separately by
 * scripts/acceptance.sh, which asserts the API-level guarantees that do not need a browser.
 */
const PREFIX = 'e2e-degraded';

interface OutageControl {
  drop: () => Promise<void>;
  allowReconnect: () => void;
}

async function withInterceptedSocket(page: Page): Promise<OutageControl> {
  // Holder object: the assignment happens inside the route callback, which TypeScript's
  // control-flow analysis cannot follow.
  const live: { close?: () => Promise<void> } = {};
  let blocking = false;

  await page.routeWebSocket(/\/ws$/, (ws) => {
    if (blocking) {
      // Simulates the worker still being unavailable: refuse immediately.
      void ws.close({ code: 1006 });

      return;
    }

    const server = ws.connectToServer();

    ws.onMessage((message) => {
      server.send(message);
    });
    server.onMessage((message) => {
      ws.send(message);
    });

    live.close = async () => ws.close({ code: 1006 });
  });

  return {
    drop: async () => {
      blocking = true;
      await live.close?.();
    },
    allowReconnect: () => {
      blocking = false;
    },
  };
}

test.describe('real-time outage', () => {
  test.afterEach(async ({ page }) => {
    await cleanUp(page, PREFIX);
  });

  test('shows a degraded indicator when the connection drops', async ({ page }) => {
    const outage = await withInterceptedSocket(page);

    await openApp(page);
    await expect(page.locator('#realtime-status')).toHaveAttribute('data-realtime-state', 'connected');

    await outage.drop();

    await expect(page.locator('#realtime-status')).toHaveAttribute('data-realtime-state', 'disconnected');
    await expect(page.locator('#realtime-status')).toContainText(/out of date/i);
  });

  /**
   * FR-007, the crux of it: a real-time outage must NOT prevent valid CRUD operations.
   */
  test('all CRUD keeps working while real-time is down', async ({ page }) => {
    const outage = await withInterceptedSocket(page);

    await openApp(page);
    await expect(page.locator('#realtime-status')).toHaveAttribute('data-realtime-state', 'connected');

    await outage.drop();
    await expect(page.locator('#realtime-status')).toHaveAttribute('data-realtime-state', 'disconnected');

    const created = uniqueName(PREFIX);
    const renamed = uniqueName(PREFIX);

    // Create.
    await createItem(page, created);

    // Rename.
    await rowFor(page, created).locator('[data-action="edit"]').click();
    await page.fill('#edit-name', renamed);
    await page.click('#edit-form button[type="submit"]');
    await expect(rowFor(page, renamed)).toHaveCount(1);

    // Delete.
    await rowFor(page, renamed).locator('[data-action="delete"]').click();
    await expect(rowFor(page, renamed)).toHaveCount(0);

    // Still degraded: nothing about the outage was silently repaired by doing CRUD.
    await expect(page.locator('#realtime-status')).toHaveAttribute('data-realtime-state', 'disconnected');
  });

  test('leaves every control enabled while degraded', async ({ page }) => {
    const outage = await withInterceptedSocket(page);

    await openApp(page);
    await createItem(page, uniqueName(PREFIX));
    await outage.drop();
    await expect(page.locator('#realtime-status')).toHaveAttribute('data-realtime-state', 'disconnected');

    for (const locator of [
      page.locator('#create-name'),
      page.locator('#create-form button[type="submit"]'),
      page.locator('#items-body tr [data-action="edit"]').first(),
      page.locator('#items-body tr [data-action="delete"]').first(),
    ]) {
      await expect(locator).toBeEnabled();
    }
  });

  /**
   * spec US3 scenario 2: on reconnect the client "refreshes authoritative state". The
   * change below is made by a DIFFERENT session while the first one is disconnected, so it
   * can only appear if the reconnect really did refetch over HTTP.
   */
  test('reconnects with backoff and converges to state it missed', async ({ page, browser }) => {
    const outage = await withInterceptedSocket(page);

    await openApp(page);
    await expect(page.locator('#realtime-status')).toHaveAttribute('data-realtime-state', 'connected');

    await outage.drop();
    await expect(page.locator('#realtime-status')).toHaveAttribute('data-realtime-state', 'disconnected');

    // A change this page cannot possibly have been told about.
    const missed = uniqueName(PREFIX);
    const other = await browser.newContext();

    try {
      const otherPage = await other.newPage();
      await openApp(otherPage);
      await createItem(otherPage, missed);
    } finally {
      await other.close();
    }

    await expect(rowFor(page, missed)).toHaveCount(0);

    outage.allowReconnect();

    // Bounded backoff: the contract caps it at 30s, and the first retries are sub-second.
    await expect(page.locator('#realtime-status')).toHaveAttribute('data-realtime-state', 'connected', {
      timeout: 40_000,
    });

    await expect(rowFor(page, missed)).toHaveCount(1, { timeout: 10_000 });
  });

  /**
   * spec US3 scenario 3 / FR-006: an unreadable frame must trigger an HTTP resync rather
   * than a crash or a corrupted list.
   */
  test('resynchronises instead of breaking on a malformed frame', async ({ page, browser }) => {
    // A holder object rather than a `let`: control-flow analysis cannot see the assignment
    // that happens inside the route callback, and would narrow a plain variable to `null`.
    const socket: { send?: (message: string) => void } = {};

    await page.routeWebSocket(/\/ws$/, (ws) => {
      const server = ws.connectToServer();
      ws.onMessage((message) => {
        server.send(message);
      });
      server.onMessage((message) => {
        ws.send(message);
      });
      socket.send = (message: string) => {
        ws.send(message);
      };
    });

    await openApp(page);
    await expect(page.locator('#realtime-status')).toHaveAttribute('data-realtime-state', 'connected');

    // Meanwhile, a real change the page has not seen yet.
    const missed = uniqueName(PREFIX);
    const other = await browser.newContext();

    try {
      const otherPage = await other.newPage();
      await openApp(otherPage);

      // Stop the page from learning about it through a valid event by garbling the socket
      // first; the resync it triggers is what must pick the change up.
      socket.send?.('this is not an event');
      socket.send?.('{"type":"item.unknown"}');

      await createItem(otherPage, missed);
    } finally {
      await other.close();
    }

    socket.send?.('still not an event');

    await expect(rowFor(page, missed)).toHaveCount(1, { timeout: 10_000 });
    await expect(page.locator('#realtime-status')).toHaveAttribute('data-realtime-state', 'connected');
  });
});
