import { expect, type Locator, type Page } from '@playwright/test';

/** A name no other test run can collide with. */
export function uniqueName(prefix: string): string {
  return `${prefix}-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
}

export function rowFor(page: Page, name: string): Locator {
  return page.locator('#items-body tr').filter({ has: page.locator('.item-name', { hasText: name }) });
}

/** Waits until the client has finished its first authoritative load. */
export async function openApp(page: Page): Promise<void> {
  await page.goto('/');
  await expect(page.locator('#loading-state')).toBeHidden();
}

export async function createItem(page: Page, name: string): Promise<void> {
  await page.fill('#create-name', name);
  await page.click('#create-form button[type="submit"]');
  await expect(rowFor(page, name)).toHaveCount(1);
}

export async function deleteItem(page: Page, name: string): Promise<void> {
  await rowFor(page, name).locator('[data-action="delete"]').click();
  await expect(rowFor(page, name)).toHaveCount(0);
}

/**
 * Removes every item whose name starts with the given prefix, through the API, so a failed
 * assertion cannot leave rows behind for the next run.
 */
export async function cleanUp(page: Page, prefix: string): Promise<void> {
  // Tests that build their own browser contexts never navigate the default fixture page,
  // which would leave it on about:blank where a same-origin relative fetch cannot resolve.
  if (!page.url().startsWith('http')) {
    await page.goto('/');
  }

  await page.evaluate(async (namePrefix: string) => {
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
    const response = await fetch('/api/items', { headers: { Accept: 'application/json' } });
    const body = (await response.json()) as { items: { id: number; name: string }[] };

    await Promise.all(
      body.items
        .filter((item) => item.name.startsWith(namePrefix))
        .map((item) =>
          fetch(`/api/items/${String(item.id)}`, {
            method: 'DELETE',
            headers: { 'X-CSRF-Token': token },
            credentials: 'same-origin',
          }),
        ),
    );
  }, prefix);
}
