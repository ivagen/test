import { resolve } from 'node:path';
import { defineConfig } from 'vite';

/**
 * Production build for the browser client.
 *
 * The output lands in www/web/assets/app, which nginx serves with immutable caching and
 * which www/views/layouts/main.php reads through the generated Vite manifest. Filenames
 * are content-hashed, so a deploy can never leave a browser holding a stale bundle.
 *
 * VITE_OUT_DIR is set by the `frontend` Compose service, where the assets directory is
 * mounted at /assets; the fallback keeps a bare `npm run build` working outside Docker.
 */
const outDir = process.env.VITE_OUT_DIR ?? resolve(import.meta.dirname, '../www/web/assets/app');

export default defineConfig({
  // Must match the nginx `location /assets/` prefix.
  base: '/assets/app/',
  build: {
    outDir,
    // The directory holds nothing but generated output, so clearing it is safe and keeps
    // stale hashed bundles from accumulating.
    emptyOutDir: true,
    manifest: true,
    sourcemap: false,
    // Matches the evergreen browsers named in spec.md's assumptions.
    target: 'es2022',
    rollupOptions: {
      input: resolve(import.meta.dirname, 'src/main.ts'),
    },
  },
});
