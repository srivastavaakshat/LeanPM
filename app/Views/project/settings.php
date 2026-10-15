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
  </header>

  <?php if (Access::can('manage_statuses')): ?>
  <form method="post" action="<?= e(url($base . '/statuses')) ?>" class="card stack" id="status-form">
    <?= csrf_field() ?>
    <h2 class="card-title">Statuses</h2>
    <p class="hint">These are the board columns, left to right. Moving a task into a <strong>Done</strong> status records its completed date, which the reports use.</p>
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
        <input name="name[]" maxlength="60" required placeholder="Status name">
        <select name="type[]"><?php foreach ($types as $k => $l): ?><option value="<?= $k ?>" <?= $k === 'active' ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
        <span class="muted small count">new</span>
        <button type="button" class="icon-btn danger" data-remove-new>✕</button>
      </div>
    </template>
    <div class="actions">
      <button type="button" class="btn" id="add-status">+ Add status</button>
      <button class="btn btn-primary">Save statuses</button>
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
      <div class="actions"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-danger">Delete status</button></div>
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
  document.getElementById('add-status').addEventListener('click', () => {
    rows.appendChild(document.getElementById('status-template').content.cloneNode(true));
    rows.lastElementChild.querySelector('input[name="name[]"]').focus();
  });
})();
</script>
