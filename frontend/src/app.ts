import { ApiClient, ApiError } from './api.js';
import { RealtimeClient, type RealtimeState } from './realtime.js';
import { ItemStore } from './store.js';
import type { ItemEvent } from './types.js';
import type { ItemListView, ViewCallbacks } from './ui.js';

/**
 * Wires the API client, the store, the real-time client and the view together.
 *
 * The invariant that holds the whole design together: **the API is the only source of
 * truth**. A mutation renders from its own HTTP response, and anything the socket says is
 * either a valid typed event (applied) or a reason to refetch. That is why duplicated,
 * reordered or missing events cannot corrupt the list (spec US3 scenario 3).
 */
export class App {
  private readonly store = new ItemStore();
  private readonly realtime: RealtimeClient;
  private resyncInFlight = false;
  private resyncQueued = false;

  private readonly view: ItemListView;

  /**
   * The view is built here from a factory rather than passed in, because the view needs
   * callbacks that call back into this object. Constructing it inside removes the
   * chicken-and-egg problem entirely, and a test can still substitute the factory.
   */
  constructor(
    private readonly api: ApiClient,
    viewFactory: (callbacks: ViewCallbacks) => ItemListView,
    realtimeFactory: (handlers: {
      onEvent: (event: ItemEvent) => void;
      onResync: () => void;
      onStateChange: (state: RealtimeState) => void;
    }) => RealtimeClient = (handlers) => new RealtimeClient(handlers),
  ) {
    this.view = viewFactory({
      onCreate: (name) => this.create(name),
      onUpdate: (id, name) => this.update(id, name),
      onDelete: (id) => this.remove(id),
    });

    this.realtime = realtimeFactory({
      onEvent: (event) => {
        this.applyEvent(event);
      },
      onResync: () => {
        void this.resync();
      },
      onStateChange: (state) => {
        this.view.setRealtimeState(state);
      },
    });
  }

  async start(): Promise<void> {
    this.view.setRealtimeState('connecting');
    this.view.setLoading(true);

    await this.resync();

    this.view.setLoading(false);

    // Started after the first authoritative load so that events arriving during start-up
    // cannot be applied on top of an empty list.
    this.realtime.start();
  }

  stop(): void {
    this.realtime.stop();
  }

  private applyEvent(event: ItemEvent): void {
    if (this.store.apply(event)) {
      this.view.render(this.store.all);
    }
  }

  /**
   * Refetches authoritative state.
   *
   * Coalesced: at most one request is in flight, and any request arriving while one is
   * running collapses into a single trailing refetch. Without this, a burst of events on a
   * flaky socket would produce a burst of identical GETs.
   */
  private async resync(): Promise<void> {
    if (this.resyncInFlight) {
      this.resyncQueued = true;

      return;
    }

    this.resyncInFlight = true;

    try {
      const items = await this.api.list();
      this.store.replaceAll(items);
      this.view.render(this.store.all);
    } catch {
      this.view.announce('Could not load the list. Retrying automatically.', 'error');
    } finally {
      this.resyncInFlight = false;

      if (this.resyncQueued) {
        this.resyncQueued = false;
        await this.resync();
      }
    }
  }

  async create(name: string): Promise<void> {
    try {
      const item = await this.api.create(name);

      // Rendered from the HTTP response, not from the event: CRUD must keep working even
      // when real-time delivery is down (FR-007).
      this.store.replaceAll([...this.store.all.filter((existing) => existing.id !== item.id), item]);
      this.view.render(this.store.all);
      this.view.clearCreateInput();
      this.view.announce(`Added “${item.name}”.`, 'success');
    } catch (error) {
      this.handleMutationError(error, (message) => {
        this.view.showCreateError(message);
      });
    }
  }

  async update(id: number, name: string): Promise<void> {
    try {
      const item = await this.api.update(id, name);

      this.store.replaceAll([...this.store.all.filter((existing) => existing.id !== item.id), item]);
      this.view.render(this.store.all);
      this.view.closeEditDialog();
      this.view.announce(`Renamed to “${item.name}”.`, 'success');
    } catch (error) {
      this.handleMutationError(error, (message) => {
        this.view.showEditError(message);
      });
    }
  }

  async remove(id: number): Promise<void> {
    try {
      await this.api.remove(id);

      this.store.replaceAll(this.store.all.filter((item) => item.id !== id));
      this.view.render(this.store.all);
      this.view.announce('Item deleted.', 'success');
    } catch (error) {
      this.handleMutationError(error, (message) => {
        this.view.announce(message, 'error');
      });
    }
  }

  /**
   * A 404 means someone else already changed this item, so the local view is stale and
   * must be reloaded rather than argued with.
   */
  private handleMutationError(error: unknown, showFieldError: (message: string) => void): void {
    if (error instanceof ApiError) {
      if (error.status === 404) {
        this.view.announce('That item no longer exists. The list has been refreshed.', 'error');
        this.view.closeEditDialog();
        void this.resync();

        return;
      }

      showFieldError(error.displayMessage);

      return;
    }

    showFieldError('The request could not be completed. Please try again.');
  }
}
