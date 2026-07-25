import './styles.css';

import { ApiClient } from './api.js';
import { App } from './app.js';
import { ItemListView } from './ui.js';

/**
 * Entry point. The only thing this file does is construct the pieces and start them.
 *
 * There is no inline <script> anywhere in the page, which is what allows nginx to serve a
 * Content-Security-Policy of `script-src 'self'` with no 'unsafe-inline' escape hatch.
 */
function bootstrap(): void {
  const api = new ApiClient(ApiClient.csrfTokenFromDocument());
  const app = new App(api, (callbacks) => new ItemListView(document, callbacks));

  void app.start();
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', bootstrap, { once: true });
} else {
  bootstrap();
}
