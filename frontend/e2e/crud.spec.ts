import { expect, test } from '@playwright/test';

import { cleanUp, createItem, deleteItem, openApp, rowFor, uniqueName } from './helpers.js';

/**
 * The complete original business capability, in a real browser (spec US2, tasks.md T051).
 */
test.describe('CRUD journeys', () => {
  const PREFIX = 'e2e-crud';

  test.afterEach(async ({ page }) => {
    await cleanUp(page, PREFIX);
  });

  test('renders the list from the API on load', async ({ page }) => {
    const name = uniqueName(PREFIX);

    await openApp(page);
    await createItem(page, name);

    // A reload proves the item came from the server, not from client state.
    await page.reload();
    await expect(page.locator('#loading-state')).toBeHidden();
    await expect(rowFor(page, name)).toHaveCount(1);
  });

  test('creates an item and confirms it', async ({ page }) => {
    const name = uniqueName(PREFIX);

    await openApp(page);
    await createItem(page, name);

    await expect(page.locator('#feedback')).toContainText(name);
    await expect(page.locator('#create-name')).toHaveValue('');
  });

  test('renames an item through the edit dialog', async ({ page }) => {
    const original = uniqueName(PREFIX);
    const renamed = uniqueName(PREFIX);

    await openApp(page);
    await createItem(page, original);

    await rowFor(page, original).locator('[data-action="edit"]').click();
    await expect(page.locator('#edit-dialog')).toBeVisible();
    await expect(page.locator('#edit-name')).toHaveValue(original);

    await page.fill('#edit-name', renamed);
    await page.click('#edit-form button[type="submit"]');

    await expect(rowFor(page, renamed)).toHaveCount(1);
    await expect(rowFor(page, original)).toHaveCount(0);
    await expect(page.locator('#edit-dialog')).toBeHidden();
  });

  test('deletes an item', async ({ page }) => {
    const name = uniqueName(PREFIX);

    await openApp(page);
    await createItem(page, name);
    await deleteItem(page, name);

    await expect(page.locator('#feedback')).toContainText('deleted');
  });

  test('keeps the list ordered by ascending id', async ({ page }) => {
    await openApp(page);
    await createItem(page, uniqueName(PREFIX));
    await createItem(page, uniqueName(PREFIX));

    const ids = await page.locator('#items-body tr th').allTextContents();
    const numeric = ids.map(Number);

    expect(numeric).toEqual([...numeric].sort((a, b) => a - b));
  });

  test('rejects a blank name without contacting the server', async ({ page }) => {
    await openApp(page);

    await page.fill('#create-name', '   ');
    await page.click('#create-form button[type="submit"]');

    await expect(page.locator('#create-error')).toBeVisible();
    await expect(page.locator('#create-error')).toContainText(/name/i);
  });

  test('shows the server validation message for an over-long name', async ({ page }) => {
    await openApp(page);

    // The maxlength attribute is a convenience, not the guard: fill bypasses it the way a
    // paste or a scripted client would, and the server must still reject the value.
    await page.locator('#create-name').evaluate((input, value) => {
      (input as HTMLInputElement).value = value;
    }, 'x'.repeat(256));
    await page.click('#create-form button[type="submit"]');

    await expect(page.locator('#create-error')).toBeVisible();
  });

  test('handles unicode names safely and exactly', async ({ page }) => {
    const name = `${uniqueName(PREFIX)} Хліб ☕ 日本語 🍰`;

    await openApp(page);
    await createItem(page, name);

    await expect(rowFor(page, name).locator('.item-name')).toHaveText(name);
  });

  /**
   * A name containing markup must be displayed literally. If it were ever injected as HTML
   * this would fail, because the <img> would exist as an element rather than as text.
   */
  test('renders a name containing markup as text', async ({ page }) => {
    const name = `${uniqueName(PREFIX)} <img src=x onerror="window.__xss=1">`;

    await openApp(page);
    await createItem(page, name);

    await expect(rowFor(page, name).locator('.item-name')).toHaveText(name);
    await expect(rowFor(page, name).locator('img')).toHaveCount(0);
    expect(await page.evaluate(() => (window as unknown as { __xss?: number }).__xss)).toBeUndefined();
  });

  /**
   * The empty state is a property of the UI, not of the database, so it is exercised by
   * serving the client an empty list rather than by emptying anyone's data.
   *
   * This used to call `test.skip()` when the database already held rows, which meant that
   * in the full serial run -- where earlier tests create data -- it never actually ran on
   * any engine. A test that is always skipped is not coverage.
   *
   * Only `GET /api/items` is intercepted. The page, its assets, the CSRF bootstrap and the
   * WebSocket all behave normally, so this still proves the real client renders the real
   * empty state. Backend empty-list behaviour is covered separately by
   * `www/tests/integration/ApiItemsTest.php`.
   */
  test('shows an empty state when the list is empty', async ({ page }) => {
    await page.route('**/api/items', async (route) => {
      if (route.request().method() !== 'GET') {
        await route.continue();

        return;
      }

      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ items: [] }),
      });
    });

    await openApp(page);

    await expect(page.locator('#items-body tr')).toHaveCount(0);
    await expect(page.locator('#empty-state')).toBeVisible();
    await expect(page.locator('#empty-state')).toContainText(/no items yet/i);

    // An empty list is a normal state, not an error one: the page must stay fully usable.
    await expect(page.locator('#loading-state')).toBeHidden();
    await expect(page.locator('#create-name')).toBeEnabled();
    await expect(page.locator('#create-form button[type="submit"]')).toBeEnabled();
  });

  /**
   * The counterpart: once a row exists the empty state must disappear again.
   */
  test('hides the empty state as soon as an item exists', async ({ page }) => {
    const name = uniqueName(PREFIX);

    await openApp(page);
    await createItem(page, name);

    await expect(page.locator('#empty-state')).toBeHidden();
  });

  test('reports no console errors during a full journey', async ({ page }) => {
    const errors: string[] = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        errors.push(message.text());
      }
    });
    page.on('pageerror', (error) => errors.push(error.message));

    const name = uniqueName(PREFIX);

    await openApp(page);
    await createItem(page, name);
    await deleteItem(page, name);

    // Catches Content-Security-Policy violations, which surface as console errors.
    expect(errors).toEqual([]);
  });
});

test.describe('responsive layout', () => {
  const PREFIX = 'e2e-responsive';

  test.afterEach(async ({ page }) => {
    await cleanUp(page, PREFIX);
  });

  for (const viewport of [
    { name: 'mobile', width: 360, height: 720 },
    { name: 'tablet', width: 768, height: 1024 },
    { name: 'desktop', width: 1440, height: 900 },
  ]) {
    test(`remains usable and never scrolls sideways at ${viewport.name}`, async ({ page }) => {
      await page.setViewportSize({ width: viewport.width, height: viewport.height });

      const name = uniqueName(PREFIX);

      await openApp(page);
      await createItem(page, name);

      await expect(page.locator('#create-name')).toBeVisible();
      await expect(rowFor(page, name).locator('[data-action="edit"]')).toBeVisible();
      await expect(rowFor(page, name).locator('[data-action="delete"]')).toBeVisible();

      const overflows = await page.evaluate(
        () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
      );

      expect(overflows, 'the page must not scroll horizontally').toBe(false);
    });
  }
});
