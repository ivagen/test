import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    // The client is DOM code, so it is tested against a DOM rather than through mocks of
    // one. Browser-level behaviour that jsdom cannot honestly simulate (a real WebSocket,
    // two independent sessions) is covered by Playwright in e2e/ instead.
    environment: 'jsdom',
    include: ['tests/**/*.test.ts'],
    globals: false,
    restoreMocks: true,
    coverage: {
      provider: 'v8',
      include: ['src/**/*.ts'],
      reporter: ['text-summary'],
    },
  },
});
