<?php use App\Services\Access; $org = Access::$org; ?>
<div class="page narrow">
  <header class="page-head"><h1>Organization settings</h1><p class="muted">Rename this organization<?= \App\Core\Auth::isOwner() ? ', restore archived projects or delete it.' : ' or restore archived projects.' ?></p></header>
  <form method="post" action="<?= e(url('/o/' . $org['slug'] . '/settings')) ?>" class="card stack">
    <?= csrf_field() ?>
    <label class="field"><span>Name</span><input name="name" value="<?= e($org['name']) ?>" required maxlength="120"></label>
    <?= error_for('name') ?>
    <p class="hint">Web address: <code><?= e(absolute_url('/o/' . $org['slug'])) ?></code></p>
    <div class="actions"><button class="btn btn-primary">Save</button></div>
  </form>
  <div class="card">
    <h2 class="card-title">Archived projects</h2>
    <?php if (!$archived): ?><p class="muted">None.</p><?php endif; ?>
    <?php foreach ($archived as $p): ?>
      <form method="post" action="<?= e(url('/o/' . $org['slug'] . '/p/' . $p['id'] . '/archive')) ?>" class="row-between line">
        <?= csrf_field() ?><input type="hidden" name="archived" value="0">
        <span><?= e($p['name']) ?></span>
        <button class="btn btn-sm">Restore</button>
      </form>
    <?php endforeach; ?>
  </div>

  <?php if (\App\Core\Auth::isOwner()):
    $counts = \App\Core\Database::one('SELECT (SELECT COUNT(*) FROM projects WHERE org_id = ? AND is_inbox = 0) AS projects, (SELECT COUNT(*) FROM tasks t JOIN projects p ON p.id = t.project_id WHERE p.org_id = ?) AS tasks, (SELECT COUNT(*) FROM memberships WHERE org_id = ?) AS members', [$org['id'], $org['id'], $org['id']]); ?>
  <form method="post" action="<?= e(url('/o/' . $org['slug'] . '/delete')) ?>" class="card stack danger-zone delete-org">
    <?= csrf_field() ?>
    <h2 class="card-title">Delete organization</h2>
    <p class="hint">Permanently deletes <strong><?= e($org['name']) ?></strong> with its <?= (int) $counts['projects'] ?> projects, <?= (int) $counts['tasks'] ?> tasks, every comment and uploaded file, and its <?= (int) $counts['members'] ?> memberships. This can’t be undone. Export a CSV first if you want to keep a record. People’s accounts stay, so they can still sign in to other organizations.</p>
    <label class="field"><span>Type <strong><?= e($org['name']) ?></strong> to confirm</span>
      <input name="confirm_name" autocomplete="off" required data-must-equal="<?= e($org['name']) ?>"></label>
    <div class="actions"><button class="btn btn-danger" disabled>Delete organization</button></div>
  </form>
  <script>
  (() => {
    const f = document.querySelector('.delete-org');
    const i = f.querySelector('[data-must-equal]');
    i.addEventListener('input', () => { f.querySelector('button').disabled = i.value.trim() !== i.dataset.mustEqual; });
  })();
  </script>
  <?php endif; ?>
</div>
