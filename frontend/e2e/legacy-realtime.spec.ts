import { expect, test } from '@playwright/test';

import { cleanUp, createItem, openApp, rowFor, uniqueName } from './helpers.js';

/**
 * Browser-level characterisation of the 2017 real-time behaviour (tasks.md T005).
 *
 * WHAT THE ORIGINAL DID, read from www/daemons/WebSocket.php and www/web/source/js/main.js
 * (both preserved in this repository's git history):
 *
 *   - The AngularJS client opened `ws://<document.domain>:8047/websocket` -- a raw socket
 *     on a SECOND origin and a second host port.
 *   - A PHPDaemon timer polled Redis key `data` once per second and pushed the ENTIRE
 *     serialised list to every connected client, whether anything had changed or not.
 *   - The client polled its own last-received payload every 500 ms and, when the string
 *     differed from the previous one, replaced `$scope.rows` wholesale.
 *   - The client sent a literal `ping` every 10 s and the server replied `pong`.
 *
 * The OBSERVABLE outcome of all that was simply: a change made in one browser session
 * shows up in another without a manual refresh. That outcome is the compatibility baseline
 * under Constitution I and is what this file asserts.
 *
 * This test could not be recorded against the running original -- its image no longer
 * builds (research.md, "Legacy build evidence") -- so it is a documented parity check
 * rather than a captured trace. The mechanism it runs against is completely different
 * (typed per-item events over same-origin /ws, no polling anywhere); only the user-visible
 * behaviour is required to match.
 */
const PREFIX = 'e2e-legacy';

test.describe('legacy real-time parity', () => {
  test.afterEach(async ({ page }) => {
    await cleanUp(page, PREFIX);
  });

  test('a change in one session appears in another with no manual refresh', async ({ browser }) => {
    const contextA = await browser.newContext();
    const contextB = await browser.newContext();

    try {
      const a = await contextA.newPage();
      const b = await contextB.newPage();

      await openApp(a);
      await openApp(b);

      let reloads = 0;
      b.on('framenavigated', () => {
        reloads += 1;
      });

      const name = uniqueName(PREFIX);
      await createItem(a, name);

      await expect(rowFor(b, name)).toHaveCount(1);

      // The 2017 promise was specifically "without a manual refresh".
      expect(reloads, 'session B must not have navigated').toBe(0);
    } finally {
      await contextA.close();
      await contextB.close();
    }
  });

  /**
   * PRESERVED: the `ping`/`pong` liveness exchange the original client relied on still
   * works, so a proxy that hides protocol-level pings cannot silently cull idle sockets.
   */
  test('the liveness ping still receives a pong', async ({ page }) => {
    await openApp(page);

    const pong = await page.evaluate(
      () =>
        new Promise<string>((resolve, reject) => {
          const scheme = location.protocol === 'https:' ? 'wss:' : 'ws:';
          const socket = new WebSocket(`${scheme}//${location.host}/ws`);
          const timer = setTimeout(() => {
            socket.close();
            reject(new Error('timed out waiting for pong'));
          }, 5000);

          socket.onopen = () => socket.send('ping');
          socket.onmessage = (event: MessageEvent<string>) => {
            if (event.data === 'pong') {
              clearTimeout(timer);
              socket.close();
              resolve(event.data);
            }
          };
          socket.onerror = () => {
            clearTimeout(timer);
            reject(new Error('socket error'));
          };
        }),
    );

    expect(pong).toBe('pong');
  });

  /**
   * CHANGED, by Constitution IV: the second origin is gone. The socket must be on the
   * page's own origin -- port 8047 must not be part of any connection the page makes.
   */
  test('no second origin or port 8047 is used any more', async ({ page }) => {
    const sockets: string[] = [];
    page.on('websocket', (ws) => sockets.push(ws.url()));

    await openApp(page);
    await expect(page.locator('#realtime-status')).toHaveAttribute('data-realtime-state', 'connected');

    expect(sockets.length).toBeGreaterThan(0);

    for (const url of sockets) {
      expect(url).not.toContain(':8047');
      expect(new URL(url).host).toBe(new URL(page.url()).host);
    }
  });
});
