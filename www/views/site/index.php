<?php

declare(strict_types=1);

/**
 * The single page.
 *
 * It renders only the static, accessible shell; the TypeScript client fills in the list and
 * wires the interactions. There is no templating language embedded in attributes and no
 * inline application logic -- the 2017 version carried an AngularJS `ng-controller`,
 * `ng-repeat`, `{{row.name}}` interpolation and an `<script type="text/ng-template">`
 * modal directly in this file.
 *
 * @var yii\web\View $this
 */

$this->title = 'Editable list';
?>

<main class="app">
    <h1>Editable list</h1>

    <p id="realtime-status" class="status" role="status" data-state="connecting" data-realtime-state="connecting">
        Connecting to live updates…
    </p>

    <form id="create-form" class="create-form" novalidate>
        <div class="field">
            <label for="create-name">New item</label>
            <input
                type="text"
                id="create-name"
                name="name"
                maxlength="255"
                autocomplete="off"
                required
                aria-describedby="create-error"
            >
        </div>
        <button type="submit">Add item</button>
        <p id="create-error" class="field-error" role="alert" hidden></p>
    </form>

    <p id="feedback" class="feedback" role="status" aria-live="polite" hidden></p>

    <p id="loading-state" class="loading-state">Loading items…</p>

    <table>
        <caption class="visually-hidden">Items, ordered by id</caption>
        <thead>
            <tr>
                <th scope="col">#</th>
                <th scope="col">Name</th>
                <th scope="col">Actions</th>
            </tr>
        </thead>
        <tbody id="items-body"></tbody>
    </table>

    <p id="empty-state" class="empty-state" hidden>No items yet. Add the first one above.</p>
</main>

<dialog id="edit-dialog" aria-labelledby="edit-dialog-title">
    <h2 id="edit-dialog-title">Rename item</h2>
    <form id="edit-form" novalidate>
        <div class="field">
            <label for="edit-name">Name</label>
            <input
                type="text"
                id="edit-name"
                name="name"
                maxlength="255"
                autocomplete="off"
                required
                aria-describedby="edit-error"
            >
        </div>
        <p id="edit-error" class="field-error" role="alert" hidden></p>
        <div class="dialog-actions">
            <?php // Wired up in frontend/src/ui.ts: an inline onclick would need a CSP 'unsafe-inline' escape hatch. ?>
            <button type="button" id="edit-cancel" value="cancel">Cancel</button>
            <button type="submit">Save</button>
        </div>
    </form>
</dialog>
