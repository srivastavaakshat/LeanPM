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
        <?php if ($isOwner): ?><button type="button" class="btn btn-sm" data-open="doc-settings">Settings</button><?php endif; ?>
        <button type="button" class="btn btn-sm btn-primary" id="new-entry" title="Start another entry, e.g. a second meeting today">+ New entry</button>
      </div>
    </div>
    <div class="notes-meta">
      <span class="tag"><?= $doc['visibility'] === 'org' ? 'Shared with ' . e($org['name']) : 'Only you' ?></span>
      <?php if ($isOwner): ?>
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
    <label class="field"><span>New tasks go to</span>
      <select name="default_project"><?php foreach ($projects as $p): ?><option value="<?= (int) $p['id'] ?>" <?= $p['id'] === $boot['doc']['default_project'] ? 'selected' : '' ?>><?= $p['inbox'] ? 'Inbox (sort them into projects later)' : e($p['name']) ?></option><?php endforeach; ?></select>
    </label>
    <fieldset class="field"><legend>Who can see it</legend>
      <label class="check"><input type="radio" name="visibility" value="private" <?= $doc['visibility'] !== 'org' ? 'checked' : '' ?>> Only me</label>
      <label class="check"><input type="radio" name="visibility" value="org" <?= $doc['visibility'] === 'org' ? 'checked' : '' ?>> Everyone in <?= e($org['name']) ?> (they can read and add entries)</label>
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
