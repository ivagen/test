import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ItemListView } from '../src/ui.js';

/**
 * The shell mirrors www/views/site/index.php. Drift between the two is caught in the
 * end-to-end suite, where ItemListView runs against the real rendered page and throws if
 * any of these ids is missing.
 */
const SHELL = `
<main class="app">
  <h1>Editable list</h1>
  <p id="realtime-status" class="status" role="status" data-state="connecting"></p>
  <form id="create-form" novalidate>
    <input type="text" id="create-name" name="name" maxlength="255" required>
    <button type="submit">Add item</button>
    <p id="create-error" role="alert" hidden></p>
  </form>
  <p id="feedback" role="status" aria-live="polite" hidden></p>
  <p id="loading-state">Loading items…</p>
  <table>
    <tbody id="items-body"></tbody>
  </table>
  <p id="empty-state" hidden></p>
</main>
<dialog id="edit-dialog">
  <form id="edit-form" novalidate>
    <input type="text" id="edit-name" name="name" maxlength="255" required>
    <p id="edit-error" role="alert" hidden></p>
    <button type="button" id="edit-cancel" value="cancel">Cancel</button>
    <button type="submit">Save</button>
  </form>
</dialog>
`;

function mount(): {
  view: ItemListView;
  onCreate: ReturnType<typeof vi.fn>;
  onUpdate: ReturnType<typeof vi.fn>;
  onDelete: ReturnType<typeof vi.fn>;
} {
  document.body.innerHTML = SHELL;

  const onCreate = vi.fn();
  const onUpdate = vi.fn();
  const onDelete = vi.fn();

  return { view: new ItemListView(document, { onCreate, onUpdate, onDelete }), onCreate, onUpdate, onDelete };
}

function rows(): HTMLTableRowElement[] {
  return [...document.querySelectorAll<HTMLTableRowElement>('#items-body tr')];
}

beforeEach(() => {
  document.body.innerHTML = '';
  // jsdom does not implement <dialog>; these keep the modal interactions testable.
  HTMLDialogElement.prototype.showModal = function showModal(this: HTMLDialogElement): void {
    this.open = true;
  };
  HTMLDialogElement.prototype.close = function close(this: HTMLDialogElement): void {
    this.open = false;
    this.dispatchEvent(new Event('close'));
  };
});

describe('ItemListView', () => {
  it('fails loudly when the page is missing a required element', () => {
    document.body.innerHTML = '<main></main>';

    expect(() => new ItemListView(document, { onCreate: vi.fn(), onUpdate: vi.fn(), onDelete: vi.fn() }))
      .toThrow(/missing/);
  });

  it('shows the empty state when there are no items', () => {
    const { view } = mount();

    view.render([]);

    expect(document.querySelector<HTMLElement>('#empty-state')?.hidden).toBe(false);
    expect(rows()).toHaveLength(0);
  });

  it('hides the empty state once items exist', () => {
    const { view } = mount();

    view.render([{ id: 1, name: 'Milk' }]);

    expect(document.querySelector<HTMLElement>('#empty-state')?.hidden).toBe(true);
    expect(rows()).toHaveLength(1);
  });

  it('toggles the loading state', () => {
    const { view } = mount();

    view.setLoading(true);
    expect(document.querySelector<HTMLElement>('#loading-state')?.hidden).toBe(false);

    view.setLoading(false);
    expect(document.querySelector<HTMLElement>('#loading-state')?.hidden).toBe(true);
  });

  it('renders id, name and actions for each item', () => {
    const { view } = mount();

    view.render([
      { id: 1, name: 'Milk' },
      { id: 7, name: 'Bread' },
    ]);

    const [first] = rows();
    expect(first?.querySelector('th')?.textContent).toBe('1');
    expect(first?.querySelector('.item-name')?.textContent).toBe('Milk');
    expect(first?.querySelector('[data-action="edit"]')).not.toBeNull();
    expect(first?.querySelector('[data-action="delete"]')).not.toBeNull();
  });

  /**
   * Item names are user input. Rendering them with textContent means a name containing
   * markup is displayed literally and can never execute.
   */
  it('renders a name containing markup as text, never as HTML', () => {
    const { view } = mount();
    const hostile = '<img src=x onerror="alert(1)">';

    view.render([{ id: 1, name: hostile }]);

    const cell = document.querySelector('.item-name');
    expect(cell?.textContent).toBe(hostile);
    expect(cell?.querySelector('img')).toBeNull();
  });

  it('gives each action button an accessible name that identifies its row', () => {
    const { view } = mount();

    view.render([{ id: 1, name: 'Milk' }]);

    expect(document.querySelector('[data-action="edit"]')?.getAttribute('aria-label')).toBe('Edit Milk');
    expect(document.querySelector('[data-action="delete"]')?.getAttribute('aria-label')).toBe('Delete Milk');
  });

  it('submits a trimmed name on create', () => {
    const { view, onCreate } = mount();
    view.render([]);

    const input = document.querySelector<HTMLInputElement>('#create-name');
    input!.value = '  Milk  ';
    document.querySelector<HTMLFormElement>('#create-form')!.requestSubmit();

    expect(onCreate).toHaveBeenCalledWith('Milk');
  });

  it('rejects a blank create locally without calling the API', () => {
    const { onCreate } = mount();

    const input = document.querySelector<HTMLInputElement>('#create-name');
    input!.value = '   ';
    document.querySelector<HTMLFormElement>('#create-form')!.requestSubmit();

    expect(onCreate).not.toHaveBeenCalled();
    expect(document.querySelector<HTMLElement>('#create-error')?.hidden).toBe(false);
  });

  it('shows a server validation message on the create field', () => {
    const { view } = mount();

    view.showCreateError('Name cannot be blank.');

    const error = document.querySelector<HTMLElement>('#create-error');
    expect(error?.hidden).toBe(false);
    expect(error?.textContent).toBe('Name cannot be blank.');
    expect(error?.getAttribute('role')).toBe('alert');
  });

  it('opens the edit dialog prefilled and submits an update', () => {
    const { view, onUpdate } = mount();
    view.render([{ id: 4, name: 'Milk' }]);

    document.querySelector<HTMLButtonElement>('[data-action="edit"]')!.click();

    const dialog = document.querySelector<HTMLDialogElement>('#edit-dialog');
    const input = document.querySelector<HTMLInputElement>('#edit-name');

    expect(dialog?.open).toBe(true);
    expect(input?.value).toBe('Milk');

    input!.value = 'Bread';
    document.querySelector<HTMLFormElement>('#edit-form')!.requestSubmit();

    expect(onUpdate).toHaveBeenCalledWith(4, 'Bread');
  });

  it('closes the edit dialog on cancel without updating', () => {
    const { view, onUpdate } = mount();
    view.render([{ id: 4, name: 'Milk' }]);
    document.querySelector<HTMLButtonElement>('[data-action="edit"]')!.click();

    document.querySelector<HTMLButtonElement>('#edit-cancel')!.click();

    expect(document.querySelector<HTMLDialogElement>('#edit-dialog')?.open).toBe(false);
    expect(onUpdate).not.toHaveBeenCalled();
  });

  it('requests deletion for the right row', () => {
    const { view, onDelete } = mount();
    view.render([
      { id: 1, name: 'Milk' },
      { id: 7, name: 'Bread' },
    ]);

    rows()[1]!.querySelector<HTMLButtonElement>('[data-action="delete"]')!.click();

    expect(onDelete).toHaveBeenCalledWith(7);
  });

  it('announces success and failure in a live region', () => {
    const { view } = mount();

    view.announce('Added “Milk”.', 'success');

    const feedback = document.querySelector<HTMLElement>('#feedback');
    expect(feedback?.hidden).toBe(false);
    expect(feedback?.textContent).toBe('Added “Milk”.');
    expect(feedback?.dataset['tone']).toBe('success');
    expect(feedback?.getAttribute('aria-live')).toBe('polite');
  });

  /**
   * FR-007: a real-time outage changes what the user is told, and nothing else. Every
   * control stays enabled.
   */
  it('shows a degraded banner without disabling any control', () => {
    const { view } = mount();
    view.render([{ id: 1, name: 'Milk' }]);

    view.setRealtimeState('disconnected');

    const status = document.querySelector<HTMLElement>('#realtime-status');
    expect(status?.getAttribute('data-realtime-state')).toBe('disconnected');
    expect(status?.textContent).toMatch(/still add, rename and delete/i);

    for (const control of document.querySelectorAll<HTMLButtonElement | HTMLInputElement>('button, input')) {
      expect(control.disabled).toBe(false);
    }
  });

  it('reports the connected state', () => {
    const { view } = mount();

    view.setRealtimeState('connected');

    expect(document.querySelector<HTMLElement>('#realtime-status')?.getAttribute('data-realtime-state'))
      .toBe('connected');
  });
});
