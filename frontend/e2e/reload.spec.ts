import { expect, test, type Page } from '@playwright/test';

import { cleanUp, createItem, openApp, rowFor, uniqueName } from './helpers.js';

/**
 * Reloading at awkward moments (spec.md Edge Cases: "Browser refresh during a mutation or
 * reconnection").
 *
 * A reload throws away every scrap of client state, so the only thing that can make the
 * page correct afterwards is that it rebuilds itself from `GET /api/items`. These tests
 * assert exactly that: whatever was in flight, the page that comes back agrees with the
 * server and shows no duplicate or phantom row.
 */
const PREFIX = 'e2e-reload';

/**
 * The names the page currently displays, for comparison against the API.
 */
async function renderedNames(page: Page, prefix: string): Promise<string[]> {
  const names = await page.locator('#items-body .item-name').allTextContents();

  return names.filter((name) => name.startsWith(prefix)).sort();
}

async function storedNames(page: Page, prefix: string): Promise<string[]> {
  return page.evaluate(async (namePrefix: string) => {
    const response = await fetch('/api/items', { headers: { Accept: 'application/json' } });
    const body = (await response.json()) as { items: { id: number; name: string }[] };

    return body.items
      .map((item) => item.name)
      .filter((name) => name.startsWith(namePrefix))
      .sort();
  }, prefix);
}

/**
 * Asserts the requirement itself -- that the displayed list CONVERGES on the API state --
 * rather than a single instantaneous snapshot.
 *
 * A snapshot comparison is racy here by construction: a mutation that was still in flight
 * when the page navigated may land on the server a moment after the reloaded page has
 * already rendered. Polling both sides together is what makes "converges" testable instead
 * of flaky.
 */
async function expectConvergedOnServerState(page: Page): Promise<void> {
  await expect
    .poll(
      async () => {
        const [rendered, stored] = await Promise.all([
          renderedNames(page, PREFIX),
          storedNames(page, PREFIX),
        ]);

        return JSON.stringify(rendered) === JSON.stringify(stored);
      },
      { timeout: 10_000, message: 'the rendered list never converged on the API state' },
    )
    .toBe(true);
}

test.describe('reload during a mutation', () => {
  test.afterEach(async ({ page }) => {
    await cleanUp(page, PREFIX);
  });

  /**
   * The create request is deliberately still in flight when the page navigates away, so the
   * client never sees its own response. Whether the server committed it is genuinely
   * racy -- and that is the point: either outcome is acceptable, but the reloaded page must
   * match the server exactly and must never show the row twice.
   */
  test('recovers to the server state when reloaded mid-create', async ({ page }) => {
    const name = uniqueName(PREFIX);
    let delaying = true;

    await openApp(page);

    // A flag rather than unroute(): removing a route while a request is parked on it makes
    // Playwright report "Route is already handled".
    await page.route('**/api/items', async (route) => {
      if (delaying && route.request().method() === 'POST') {
        await new Promise((resolve) => setTimeout(resolve, 1500));
      }

      await route.continue();
    });

    await page.fill('#create-name', name);
    await page.click('#create-form button[type="submit"]');

    // Navigate away while the POST is still open. Whether the server committed it before
    // the navigation aborted the request is genuinely racy -- and irrelevant. What must
    // hold either way is that the reloaded page converges on the server's answer.
    await page.waitForTimeout(300);
    delaying = false;
    await page.reload();
    await expect(page.locator('#loading-state')).toBeHidden();

    await expectConvergedOnServerState(page);

    const rendered = await renderedNames(page, PREFIX);
    expect(rendered.filter((entry) => entry === name).length).toBeLessThanOrEqual(1);
  });

  test('recovers to the server state when reloaded mid-delete', async ({ page }) => {
    const name = uniqueName(PREFIX);
    let delaying = true;

    await openApp(page);
    await createItem(page, name);

    await page.route('**/api/items/*', async (route) => {
      if (delaying && route.request().method() === 'DELETE') {
        await new Promise((resolve) => setTimeout(resolve, 1500));
      }

      await route.continue();
    });

    await rowFor(page, name).locator('[data-action="delete"]').click();

    await page.waitForTimeout(300);
    delaying = false;
    await page.reload();
    await expect(page.locator('#loading-state')).toBeHidden();

    await expectConvergedOnServerState(page);
  });

  /**
   * A reload while the edit dialog is open must not leave a half-applied rename behind.
   */
  test('discards an unsubmitted edit on reload', async ({ page }) => {
    const name = uniqueName(PREFIX);

    await openApp(page);
    await createItem(page, name);

    await rowFor(page, name).locator('[data-action="edit"]').click();
    await page.fill('#edit-name', uniqueName(PREFIX));

    await page.reload();
    await expect(page.locator('#loading-state')).toBeHidden();

    await expect(page.locator('#edit-dialog')).toBeHidden();
    await expect(rowFor(page, name)).toHaveCount(1);
    expect(await renderedNames(page, PREFIX)).toEqual(await storedNames(page, PREFIX));
  });
});

test.describe('reload during a reconnection', () => {
  test.afterEach(async ({ page }) => {
    await cleanUp(page, PREFIX);
  });

  /**
   * FR-007 across a navigation: with real-time unavailable, a reload must still produce a
   * fully working page -- list loaded over HTTP, degraded banner shown, CRUD available.
   */
  test('still loads and stays usable when reloaded while real-time is unavailable', async ({ page }) => {
    const existing = uniqueName(PREFIX);

    await openApp(page);
    await createItem(page, existing);

    // Refuse every socket, including the reconnection attempts, so the page reloads into
    // an ongoing outage rather than a momentary blip.
    await page.routeWebSocket(/\/ws$/, (ws) => {
      void ws.close({ code: 1006 });
    });

    await page.reload();
    await expect(page.locator('#loading-state')).toBeHidden();

    // The list came from HTTP even though the socket never opened.
    await expect(rowFor(page, existing)).toHaveCount(1);
    await expect(page.locator('#realtime-status')).toHaveAttribute('data-realtime-state', 'disconnected');

    // And CRUD still works.
    const created = uniqueName(PREFIX);
    await createItem(page, created);
    await expect(rowFor(page, created)).toHaveCount(1);

    expect(await renderedNames(page, PREFIX)).toEqual(await storedNames(page, PREFIX));
  });

  /**
   * Once the socket becomes available again, a reload must connect normally -- the outage
   * must not leave any sticky state behind.
   */
  test('connects normally on a reload after the outage ends', async ({ page }) => {
    let blocking = true;

    await page.routeWebSocket(/\/ws$/, (ws) => {
      if (blocking) {
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
    });

    await openApp(page);
    await expect(page.locator('#realtime-status')).toHaveAttribute('data-realtime-state', 'disconnected');

    blocking = false;
    await page.reload();
    await expect(page.locator('#loading-state')).toBeHidden();

    await expect(page.locator('#realtime-status')).toHaveAttribute('data-realtime-state', 'connected');
  });
});
