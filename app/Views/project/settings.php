<?php
use App\Services\Access;
$org = Access::$org;
$base = '/o/' . $org['slug'] . '/p/' . $project['id'];
$types = ['open' => 'Not started', 'active' => 'In progress', 'done' => 'Done (sets completed date)'];
?>
<div class="page narrow">
  <header class="page-head">
    <a class="back" href="<?= e(url($base)) ?>">← Back to board</a>
    <h1><?= e($project['name']) ?> settings</h1>
    <p class="muted">Stages, fields, name and archiving for this project.</p>
  </header>

  <?php if (Access::can('manage_statuses')): ?>
  <form method="post" action="<?= e(url($base . '/statuses')) ?>" class="card stack" id="status-form">
    <?= csrf_field() ?>
    <h2 class="card-title">Stages</h2>
    <p class="hint">The board columns for this project only, left to right. Rename, recolor, reorder, add or remove them. Moving a task into a <strong>Done</strong> stage records its completed date, which the reports use.</p>
    <div class="status-rows" id="status-rows">
      <?php foreach ($statuses as $s): ?>
        <div class="status-row">
          <input type="hidden" name="status_id[]" value="<?= (int) $s['id'] ?>">
          <div class="order-btns"><button type="button" class="icon-btn" data-up title="Move left">↑</button><button type="button" class="icon-btn" data-down title="Move right">↓</button></div>
          <input type="color" name="color[]" value="<?= e($s['color']) ?>" aria-label="Colour">
          <input name="name[]" value="<?= e($s['name']) ?>" maxlength="60" required aria-label="Name">
          <select name="type[]" aria-label="Type"><?php foreach ($types as $k => $l): ?><option value="<?= $k ?>" <?= $s['type'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
          <span class="muted small count"><?= (int) ($counts[$s['id']] ?? 0) ?> tasks</span>
          <button type="button" class="icon-btn danger" data-delete="<?= (int) $s['id'] ?>" data-name="<?= e($s['name']) ?>" data-count="<?= (int) ($counts[$s['id']] ?? 0) ?>" title="Delete">✕</button>
        </div>
      <?php endforeach; ?>
    </div>
    <template id="status-template">
      <div class="status-row">
        <input type="hidden" name="status_id[]" value="new">
        <div class="order-btns"><button type="button" class="icon-btn" data-up>↑</button><button type="button" class="icon-btn" data-down>↓</button></div>
        <input type="color" name="color[]" value="#8b5cf6">
        <input name="name[]" maxlength="60" required placeholder="Stage name">
        <select name="type[]"><?php foreach ($types as $k => $l): ?><option value="<?= $k ?>" <?= $k === 'active' ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
        <span class="muted small count">new</span>
        <button type="button" class="icon-btn danger" data-remove-new>✕</button>
      </div>
    </template>
    <div class="actions">
      <button type="button" class="btn" id="add-status">+ Add stage</button>
      <button class="btn btn-primary">Save stages</button>
    </div>
  </form>

  <form method="post" action="<?= e(url($base . '/statuses/template')) ?>" class="card stack" data-confirm="Replace this project’s stages with the template? Tasks move to the first new stage of the same kind (not started, in progress or done).">
    <?= csrf_field() ?>
    <h2 class="card-title">Switch to a template</h2>
    <p class="hint">Replaces the stages above. Tasks keep their progress: not-started tasks go to the template’s first not-started stage, in-progress to its first in-progress stage, done to its first done stage. You can fine-tune afterwards.</p>
    <?= \App\Core\View::partial('partials/template_picker', ['selected' => null]) ?>
    <div class="actions"><button class="btn">Switch stages</button></div>
  </form>

  <form method="post" action="<?= e(url($base . '/fields')) ?>" class="card stack" id="fields">
    <?= csrf_field() ?>
    <h2 class="card-title">Fields</h2>
    <p class="hint">Extra details every task in this project can have, like a <strong>Figma file</strong> link. They show on the task, on board cards (links) and in the list and CSV exports. Each project has its own fields.</p>
    <div class="field-rows" id="field-rows">
      <?php foreach ($fields as $f): $n = (int) ($fieldCounts[$f['id']] ?? 0); ?>
        <div class="field-def">
          <input type="hidden" name="field_id[]" value="<?= (int) $f['id'] ?>">
          <input type="hidden" name="field_type[]" value="<?= e($f['type']) ?>">
          <div class="order-btns"><button type="button" class="icon-btn" data-up title="Move up">↑</button><button type="button" class="icon-btn" data-down title="Move down">↓</button></div>
          <input name="field_name[]" value="<?= e($f['name']) ?>" maxlength="60" required aria-label="Field name">
          <span class="type-tag"><?= e(\App\Services\Fields::TYPES[$f['type']]) ?></span>
          <input name="field_options[]" value="<?= e(implode(', ', $f['options'])) ?>" placeholder="Options, comma separated" aria-label="Dropdown options" <?= $f['type'] === 'select' ? '' : 'hidden' ?>>
          <span class="muted small count"><?= $n ?> filled</span>
          <button type="submit" class="icon-btn danger" title="Delete field" formaction="<?= e(url($base . '/fields/' . $f['id'] . '/delete')) ?>" formnovalidate
            onclick="return confirm(<?= e(json_encode('Delete the “' . $f['name'] . '” field?' . ($n ? " Its value on {$n} tasks is removed." : ''))) ?>)">✕</button>
        </div>
      <?php endforeach; ?>
      <?php if (!$fields): ?><p class="muted small" id="no-fields">No fields yet. Add one below or from a template.</p><?php endif; ?>
    </div>
    <template id="field-template">
      <div class="field-def">
        <input type="hidden" name="field_id[]" value="new">
        <div class="order-btns"><button type="button" class="icon-btn" data-up>↑</button><button type="button" class="icon-btn" data-down>↓</button></div>
        <input name="field_name[]" maxlength="60" required placeholder="e.g. Figma file">
        <select name="field_type[]" aria-label="Type"><?php foreach (\App\Services\Fields::TYPES as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select>
        <input name="field_options[]" placeholder="Options, comma separated" hidden>
        <span class="muted small count">new</span>
        <button type="button" class="icon-btn danger" data-remove-new>✕</button>
      </div>
    </template>
    <div class="actions">
      <button type="button" class="btn" id="add-field">+ Add field</button>
      <button class="btn btn-primary">Save fields</button>
    </div>
    <div class="row-between template-fields">
      <span class="muted small">Or add a template’s fields:</span>
      <span class="row-center gap-s">
        <?php foreach (\App\Services\Tasks::TEMPLATES as $k => $t): ?>
          <button type="submit" class="btn btn-sm" formaction="<?= e(url($base . '/fields/template')) ?>" formnovalidate name="template" value="<?= e($k) ?>"
            title="<?= e(implode(', ', array_column(\App\Services\Fields::TEMPLATES[$k], 0))) ?>"><?= e($t['label']) ?></button>
        <?php endforeach; ?>
      </span>
    </div>
  </form>

  <dialog id="delete-status" class="dialog">
    <form method="post" id="delete-status-form">
      <?= csrf_field() ?>
      <h2>Delete “<span data-name></span>”?</h2>
      <div data-has-tasks>
        <p>It has <strong data-count></strong> tasks. Move them to:</p>
        <label class="field"><select name="move_to"><?php foreach ($statuses as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></label>
      </div>
      <div class="actions"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-danger">Delete stage</button></div>
    </form>
  </dialog>
  <?php endif; ?>

  <?php if (Access::can('manage_projects')): ?>
  <form method="post" action="<?= e(url($base . '/rename')) ?>" class="card stack">
    <?= csrf_field() ?>
    <h2 class="card-title">Project name</h2>
    <label class="field"><input name="name" value="<?= e($project['name']) ?>" required maxlength="120"></label>
    <div class="actions"><button class="btn btn-primary">Rename</button></div>
  </form>
  <form method="post" action="<?= e(url($base . '/archive')) ?>" class="card row-between danger-zone" data-confirm="Archive <?= e($project['name']) ?>? It disappears from the sidebar but nothing is deleted.">
    <?= csrf_field() ?><input type="hidden" name="archived" value="1">
    <div><strong>Archive project</strong><div class="muted small">Hides it from the sidebar. Restore it from Organization settings.</div></div>
    <button class="btn btn-sm btn-danger">Archive</button>
  </form>
  <?php endif; ?>
</div>
<script>
(() => {
  const rows = document.getElementById('status-rows');
  if (!rows) return;
  rows.addEventListener('click', e => {
    const row = e.target.closest('.status-row');
    if (e.target.matches('[data-up]') && row.previousElementSibling) row.parentNode.insertBefore(row, row.previousElementSibling);
    if (e.target.matches('[data-down]') && row.nextElementSibling) row.parentNode.insertBefore(row.nextElementSibling, row);
    if (e.target.matches('[data-remove-new]')) row.remove();
    if (e.target.matches('[data-delete]')) {
      const d = document.getElementById('delete-status');
      const b = e.target.dataset;
      d.querySelector('[data-name]').textContent = b.name;
      d.querySelector('[data-count]').textContent = b.count;
      d.querySelector('[data-has-tasks]').hidden = b.count === '0';
      const sel = d.querySelector('select');
      [...sel.options].forEach(o => o.disabled = o.value === b.delete);
      sel.value = [...sel.options].find(o => !o.disabled)?.value;
      d.querySelector('form').action = <?= json_encode(url($base . '/statuses/')) ?> + b.delete + '/delete';
      d.showModal();
    }
  });
  const frows = document.getElementById('field-rows');
  frows.addEventListener('click', e => {
    const row = e.target.closest('.field-def');
    if (!row) return;
    if (e.target.matches('[data-up]') && row.previousElementSibling?.matches('.field-def')) row.parentNode.insertBefore(row, row.previousElementSibling);
    if (e.target.matches('[data-down]') && row.nextElementSibling) row.parentNode.insertBefore(row.nextElementSibling, row);
    if (e.target.matches('[data-remove-new]')) row.remove();
  });
  frows.addEventListener('change', e => {
    if (e.target.name === 'field_type[]') e.target.closest('.field-def').querySelector('[name="field_options[]"]').hidden = e.target.value !== 'select';
  });
  document.getElementById('add-field').addEventListener('click', () => {
    document.getElementById('no-fields')?.remove();
    frows.appendChild(document.getElementById('field-template').content.cloneNode(true));
    frows.lastElementChild.querySelector('input[name="field_name[]"]').focus();
  });
  document.getElementById('add-status').addEventListener('click', () => {
    rows.appendChild(document.getElementById('status-template').content.cloneNode(true));
    rows.lastElementChild.querySelector('input[name="name[]"]').focus();
  });
})();
</script>
