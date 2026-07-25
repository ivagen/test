import { defineConfig, devices } from '@playwright/test';

/**
 * End-to-end acceptance (quickstart.md "Real-time acceptance").
 *
 * Runs inside the `frontend` container and reaches the application through nginx, i.e.
 * through the same single published entrypoint a user would -- including the /ws upgrade,
 * which is the only way to prove the same-origin real-time requirement holds.
 *
 * Serial with one worker on purpose: the real-time tests stop and start the websocket
 * service, which is global state that parallel workers would corrupt.
 */
export default defineConfig({
  testDir: './e2e',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 60_000,
  expect: {
    timeout: 10_000,
  },
  reporter: [['list']],
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://nginx',
    trace: 'retain-on-failure',
    video: 'off',
    screenshot: 'only-on-failure',
  },
  /**
   * All three evergreen engines named in spec.md's assumptions. WebKit is the closest
   * available stand-in for Safari.
   *
   * Set E2E_BROWSER to run just one (`E2E_BROWSER=chromium npm run test:e2e`) while
   * iterating; the default, and CI, run every engine.
   */
  projects: (
    [
      { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
      { name: 'firefox', use: { ...devices['Desktop Firefox'] } },
      { name: 'webkit', use: { ...devices['Desktop Safari'] } },
    ] as const
  ).filter((project) => !process.env.E2E_BROWSER || process.env.E2E_BROWSER === project.name),
});
