<?php
use App\Services\Access;
$org = Access::$org;
$base = '/o/' . $org['slug'] . '/notes';
$isOwner = $boot['isOwner'];
$defaultName = 'Inbox';
foreach ($projects as $p) { if ($p['id'] === $boot['doc']['default_project']) { $defaultName = $p['inbox'] ? 'Inbox' : $p['name']; } }
?>
<div class="notes-page">
  <header class="notes-head">
    <a class="back" href="<?= e(url($base)) ?>">← Meeting notes</a>
    <div class="row-between notes-title-row">
      <h1><?= e($doc['title']) ?></h1>
      <div class="row-center gap-s">
        <details class="export-menu">
          <summary class="btn btn-sm">Export</summary>
          <div class="menu popover">
            <?php $ex = $base . '/' . $doc['id'] . '/export/'; ?>
            <a href="<?= e(url($ex . 'pdf')) ?>" target="_blank" rel="noopener"><strong>PDF</strong><span class="muted small">Opens a print view: choose “Save as PDF”</span></a>
            <a href="<?= e(url($ex . 'doc')) ?>"><strong>Word (.doc)</strong><span class="muted small">Opens in Word, Pages or Google Docs</span></a>
            <a href="<?= e(url($ex . 'md')) ?>"><strong>Markdown (.md)</strong><span class="muted small">For Notion, Obsidian or GitHub</span></a>
            <a href="<?= e(url($ex . 'txt')) ?>"><strong>Plain text (.txt)</strong><span class="muted small">Works everywhere</span></a>
          </div>
        </details>
        <?php if ($isOwner): ?><button type="button" class="btn btn-sm" data-open="doc-settings">Settings</button><?php endif; ?>
        <button type="button" class="btn btn-sm btn-primary" id="new-entry" title="Start another entry, e.g. a second meeting today">+ New entry</button>
      </div>
    </div>
    <?php if ($linked): ?>
      <div class="doc-linked">
        <span class="doc-linked-icon" aria-hidden="true">↳</span>
        <span>Connected to the task <a href="<?= e($linked['url']) ?>"><strong><?= e($linked['title']) ?></strong></a> <span class="muted">in <?= e($linked['project']) ?></span>. Tasks you create here become its subtasks.</span>
      </div>
    <?php endif; ?>
    <div class="notes-meta">
      <span class="tag"><?= $doc['visibility'] === 'org' ? 'Shared with ' . e($org['name']) : 'Only you' ?></span>
      <?php if ($linked): ?>
        <span class="muted small">New tasks become subtasks of “<?= e($linked['title']) ?>”</span>
      <?php elseif ($isOwner): ?>
        <form method="post" action="<?= e(url($base . '/' . $doc['id'] . '/settings')) ?>" class="row-center gap-s">
          <?= csrf_field() ?>
          <input type="hidden" name="title" value="<?= e($doc['title']) ?>">
          <input type="hidden" name="visibility" value="<?= e($doc['visibility']) ?>">
          <label class="muted small" for="default-project">New tasks go to</label>
          <select name="default_project" id="default-project" class="pill-select" onchange="this.form.submit()">
            <?php foreach ($projects as $p): ?><option value="<?= (int) $p['id'] ?>" <?= $p['id'] === $boot['doc']['default_project'] ? 'selected' : '' ?>><?= $p['inbox'] ? 'Inbox' : e($p['name']) ?></option><?php endforeach; ?>
          </select>
        </form>
      <?php else: ?>
        <span class="muted small">New tasks go to <?= e($defaultName) ?> (you can pick another project on each task)</span>
      <?php endif; ?>
      <span class="muted small notes-tip">Tip: type <kbd>/</kbd> or <kbd>\</kbd> at the end of a line to make it a task</span>
    </div>
  </header>

  <div class="notes-layout">
    <div class="notes-stream" id="notes-stream"></div>
    <aside class="notes-rail" id="notes-rail"></aside>
  </div>
</div>

<?php if ($isOwner): ?>
<dialog id="doc-settings" class="dialog">
  <form method="post" action="<?= e(url($base . '/' . $doc['id'] . '/settings')) ?>">
    <?= csrf_field() ?>
    <h2>Doc settings</h2>
    <label class="field"><span>Name</span><input name="title" value="<?= e($doc['title']) ?>" required maxlength="120"></label>
    <?php if ($linked): ?>
      <div class="field"><span>Connected task</span>
        <p class="hint" style="margin:0 0 6px">“<?= e($linked['title']) ?>”. Tasks created here become its subtasks.</p>
        <label class="check"><input type="checkbox" name="unlink_task" value="1"> Disconnect from this task (new tasks go to the project below instead)</label>
      </div>
    <?php endif; ?>
    <label class="field"><span><?= $linked ? 'When not connected, new tasks go to' : 'New tasks go to' ?></span>
      <select name="default_project"><?php foreach ($projects as $p): ?><option value="<?= (int) $p['id'] ?>" <?= $p['id'] === $boot['doc']['default_project'] ? 'selected' : '' ?>><?= $p['inbox'] ? 'Inbox (sort them into projects later)' : e($p['name']) ?></option><?php endforeach; ?></select>
    </label>
    <fieldset class="field"><legend>Who can see it</legend>
      <label class="check"><input type="radio" name="visibility" value="private" <?= $doc['visibility'] !== 'org' ? 'checked' : '' ?>> Only me</label>
      <label class="check"><input type="radio" name="visibility" value="org" <?= $doc['visibility'] === 'org' ? 'checked' : '' ?>> Everyone in <?= e($org['name']) ?> (they can read and add entries)</label>
    </fieldset>
    <fieldset class="field"><legend>Task quick view shows</legend>
      <input type="hidden" name="sections_form" value="1">
      <p class="hint" style="margin-bottom:6px">What you see when you click a task in these notes. Stage and project always show.</p>
      <div class="check-grid">
        <?php foreach (\App\Controllers\NotesController::PEEK_SECTIONS as $k => $label): ?>
          <label class="check"><input type="checkbox" name="peek[]" value="<?= e($k) ?>" <?= in_array($k, $boot['doc']['sections'], true) ? 'checked' : '' ?>> <?= e($label) ?></label>
        <?php endforeach; ?>
      </div>
    </fieldset>
    <div class="actions"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save</button></div>
  </form>
  <form method="post" action="<?= e(url($base . '/' . $doc['id'] . '/delete')) ?>" class="row-between danger-zone dialog-danger" data-confirm="Delete “<?= e($doc['title']) ?>” and all its entries? Tasks created from it stay on their boards. This can’t be undone.">
    <?= csrf_field() ?>
    <span class="muted small">Deletes the doc and all its entries. Tasks stay.</span>
    <button class="btn btn-sm btn-danger">Delete doc</button>
  </form>
</dialog>
<?php endif; ?>

<script id="boot" type="application/json"><?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= e(asset('peek.js')) ?>"></script>
<script src="<?= e(asset('notes.js')) ?>"></script>
