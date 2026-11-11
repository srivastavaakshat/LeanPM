<?php
use App\Services\Access;
$org = Access::$org;
$base = '/o/' . $org['slug'] . '/notes';
?>
<div class="page">
  <header class="page-head row-between">
    <div>
      <h1>Meeting notes</h1>
      <p class="muted">One running doc per meeting: a stand-up, a weekly call with the CEO, the sales sync. Each time you open it, a new dated entry is ready for today’s notes.</p>
    </div>
    <button type="button" class="btn btn-primary" data-open="new-doc">+ New doc</button>
  </header>

  <?php if ($counts['all']): ?>
    <nav class="view-toggle notes-tabs" aria-label="Which docs">
      <?php foreach (['all' => 'All', 'private' => 'Only me', 'shared' => 'Shared'] as $k => $label): ?>
        <a href="<?= e(url($base . ($k === 'all' ? '' : '?show=' . $k))) ?>" class="<?= $show === $k ? 'is-active' : '' ?>"<?= $show === $k ? ' aria-current="page"' : '' ?>><?= $label ?> <span class="count"><?= (int) $counts[$k] ?></span></a>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>

  <?php if (!$docs && $counts['all']): ?>
    <div class="card empty-state notes-empty">
      <h2><?= $show === 'shared' ? 'No shared docs yet' : 'No private docs yet' ?></h2>
      <p><?= $show === 'shared' ? 'Docs shared with everyone in ' . e($org['name']) . ' show up here. Share one from its Settings.' : 'Docs only you can see show up here.' ?></p>
      <a class="btn" href="<?= e(url($base)) ?>">See all docs</a>
    </div>
  <?php elseif (!$docs): ?>
    <div class="card empty-state notes-empty">
      <h2>Start your first meeting doc</h2>
      <p>For example “Internal stand-up”, “Weekly client call” or “Sales team sync”. Write notes during the call, type <kbd>/</kbd> at the end of a line to turn it into a task, and next time you’ll see what was decided and how those tasks are going.</p>
      <button type="button" class="btn btn-primary" data-open="new-doc">+ New doc</button>
    </div>
  <?php else: ?>
    <div class="doc-cards">
      <?php foreach ($docs as $d): ?>
        <a class="doc-card" href="<?= e(url($base . '/' . $d['id'])) ?>">
          <span class="doc-card-icon"><svg class="i" viewBox="0 0 20 20"><path d="M6 3h5l4 4v9a1 1 0 01-1 1H6a1 1 0 01-1-1V4a1 1 0 011-1z"/><path d="M11 3v4h4M8 11h5M8 14h3"/></svg></span>
          <span class="doc-card-title"><?= e($d['title']) ?></span>
          <span class="muted small">
            <?= $d['last_meeting'] ? 'Last meeting ' . e(fmt_date($d['last_meeting'], 'D, j M')) : 'No entries yet' ?>
            · <?= (int) $d['entries'] ?> <?= (int) $d['entries'] === 1 ? 'entry' : 'entries' ?>
          </span>
          <span class="doc-card-meta">
            <span class="tag"><?= $d['visibility'] === 'org' ? 'Shared' : 'Only you' ?></span>
            <?php if ($d['linked_task']): ?><span class="muted small">↳ <?= e($d['linked_task']) ?></span><?php else: ?><span class="muted small">Tasks → <?= e($d['default_project'] ?? 'Inbox') ?></span><?php endif; ?>
            <?php if ((int) $d['owner_id'] !== \App\Core\Auth::id()): ?><span class="muted small">by <?= e($d['owner_name']) ?></span><?php endif; ?>
          </span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<dialog id="new-doc" class="dialog">
  <form method="post" action="<?= e(url($base)) ?>">
    <?= csrf_field() ?>
    <h2>New meeting doc</h2>
    <label class="field"><span>Name</span><input name="title" required maxlength="120" placeholder="e.g. Weekly design review" autofocus></label>
    <label class="field"><span>New tasks from these notes go to</span>
      <select name="default_project">
        <?php foreach ($projects as $p): ?><option value="<?= (int) $p['id'] ?>"><?= $p['inbox'] ? 'Inbox (sort them into projects later)' : e($p['name']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <fieldset class="field"><legend>Who can see it</legend>
      <label class="check"><input type="radio" name="visibility" value="private" checked> Only me</label>
      <label class="check"><input type="radio" name="visibility" value="org"> Everyone in <?= e($org['name']) ?> (they can read and add entries)</label>
    </fieldset>
    <div class="actions"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Create doc</button></div>
  </form>
</dialog>
