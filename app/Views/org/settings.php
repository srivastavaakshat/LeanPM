<?php use App\Services\Access; $org = Access::$org; ?>
<div class="page narrow">
  <header class="page-head"><h1>Organization settings</h1><p class="muted">Rename this organization or restore archived projects.<?= \App\Core\Auth::isOwner() ? ' To delete it, use <a href="' . e(url('/admin')) . '">Manage LeanPM</a>.' : '' ?></p></header>
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

</div>
