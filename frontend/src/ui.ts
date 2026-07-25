import type { RealtimeState } from './realtime.js';
import type { Item } from './types.js';

export interface ViewCallbacks {
  readonly onCreate: (name: string) => void | Promise<void>;
  readonly onUpdate: (id: number, name: string) => void | Promise<void>;
  readonly onDelete: (id: number) => void | Promise<void>;
}

const REALTIME_LABELS: Record<RealtimeState, string> = {
  connecting: 'Connecting to live updates…',
  connected: 'Live updates active',
  disconnected: 'Live updates unavailable — the list may be out of date. You can still add, rename and delete items.',
};

/**
 * All DOM reading and writing lives here, so the rest of the client is plain data.
 *
 * Two rules are absolute in this file:
 *
 *  1. Text is only ever written with `textContent`, never `innerHTML`. Item names are
 *     user-controlled, and this makes cross-site scripting through a name impossible by
 *     construction rather than by escaping discipline.
 *  2. The degraded banner never disables anything. FR-007 requires that CRUD keeps working
 *     during a real-time outage, so a lost socket only changes what the user is TOLD.
 */
export class ItemListView {
  private readonly tableBody: HTMLElement;
  private readonly emptyState: HTMLElement;
  private readonly loadingState: HTMLElement;
  private readonly realtimeStatus: HTMLElement;
  private readonly feedback: HTMLElement;
  private readonly createForm: HTMLFormElement;
  private readonly createInput: HTMLInputElement;
  private readonly createError: HTMLElement;
  private readonly editDialog: HTMLDialogElement;
  private readonly editForm: HTMLFormElement;
  private readonly editInput: HTMLInputElement;
  private readonly editError: HTMLElement;

  private editingId: number | null = null;

  constructor(
    private readonly root: Document | HTMLElement,
    private readonly callbacks: ViewCallbacks,
  ) {
    this.tableBody = this.require('items-body');
    this.emptyState = this.require('empty-state');
    this.loadingState = this.require('loading-state');
    this.realtimeStatus = this.require('realtime-status');
    this.feedback = this.require('feedback');
    this.createForm = this.require<HTMLFormElement>('create-form');
    this.createInput = this.require<HTMLInputElement>('create-name');
    this.createError = this.require('create-error');
    this.editDialog = this.require<HTMLDialogElement>('edit-dialog');
    this.editForm = this.require<HTMLFormElement>('edit-form');
    this.editInput = this.require<HTMLInputElement>('edit-name');
    this.editError = this.require('edit-error');

    this.bindForms();
  }

  private require<T extends HTMLElement = HTMLElement>(id: string): T {
    const scope: ParentNode = this.root;
    const element = scope.querySelector(`#${id}`);

    if (element === null) {
      throw new Error(`The page is missing the #${id} element.`);
    }

    return element as T;
  }

  private bindForms(): void {
    this.createForm.addEventListener('submit', (event) => {
      event.preventDefault();
      this.clearError(this.createError);

      const name = this.createInput.value.trim();

      if (name === '') {
        this.showError(this.createError, 'Please enter a name.');
        this.createInput.focus();

        return;
      }

      void this.callbacks.onCreate(name);
    });

    this.editForm.addEventListener('submit', (event) => {
      event.preventDefault();
      this.clearError(this.editError);

      const name = this.editInput.value.trim();

      if (name === '') {
        this.showError(this.editError, 'Please enter a name.');
        this.editInput.focus();

        return;
      }

      if (this.editingId !== null) {
        void this.callbacks.onUpdate(this.editingId, name);
      }
    });

    this.require<HTMLButtonElement>('edit-cancel').addEventListener('click', () => {
      this.closeEditDialog();
    });

    this.editDialog.addEventListener('close', () => {
      this.editingId = null;
    });
  }

  setLoading(loading: boolean): void {
    this.loadingState.hidden = !loading;
  }

  render(items: readonly Item[]): void {
    this.tableBody.replaceChildren(...items.map((item) => this.renderRow(item)));
    this.emptyState.hidden = items.length > 0;
  }

  private renderRow(item: Item): HTMLTableRowElement {
    const row = document.createElement('tr');
    row.dataset['itemId'] = String(item.id);

    const id = document.createElement('th');
    id.scope = 'row';
    id.textContent = String(item.id);

    const name = document.createElement('td');
    name.className = 'item-name';
    // textContent, never innerHTML: a name is user input.
    name.textContent = item.name;

    const actions = document.createElement('td');
    actions.className = 'item-actions';
    actions.append(
      this.actionButton('Edit', `Edit ${item.name}`, 'edit', () => {
        this.openEditDialog(item);
      }),
      this.actionButton('Delete', `Delete ${item.name}`, 'delete', () => {
        void this.callbacks.onDelete(item.id);
      }),
    );

    row.append(id, name, actions);

    return row;
  }

  private actionButton(label: string, accessibleName: string, action: string, onClick: () => void): HTMLButtonElement {
    const button = document.createElement('button');
    button.type = 'button';
    button.textContent = label;
    // The visible label is short; screen readers get the full "Edit <name>".
    button.setAttribute('aria-label', accessibleName);
    button.dataset['action'] = action;
    button.addEventListener('click', onClick);

    return button;
  }

  openEditDialog(item: Item): void {
    this.editingId = item.id;
    this.editInput.value = item.name;
    this.clearError(this.editError);
    this.editDialog.showModal();
    this.editInput.focus();
    this.editInput.select();
  }

  closeEditDialog(): void {
    if (this.editDialog.open) {
      this.editDialog.close();
    }
  }

  clearCreateInput(): void {
    this.createForm.reset();
    this.createInput.focus();
  }

  showCreateError(message: string): void {
    this.showError(this.createError, message);
  }

  showEditError(message: string): void {
    this.showError(this.editError, message);
  }

  /**
   * Announces a transient outcome. `role="status"` on the container means assistive
   * technology hears it without the focus being stolen.
   */
  announce(message: string, tone: 'success' | 'error'): void {
    this.feedback.textContent = message;
    this.feedback.dataset['tone'] = tone;
    this.feedback.hidden = message === '';
  }

  setRealtimeState(state: RealtimeState): void {
    this.realtimeStatus.textContent = REALTIME_LABELS[state];
    this.realtimeStatus.dataset['state'] = state;
    // Used by the end-to-end degraded-state assertions.
    this.realtimeStatus.setAttribute('data-realtime-state', state);
  }

  private showError(target: HTMLElement, message: string): void {
    target.textContent = message;
    target.hidden = false;
  }

  private clearError(target: HTMLElement): void {
    target.textContent = '';
    target.hidden = true;
  }
}
