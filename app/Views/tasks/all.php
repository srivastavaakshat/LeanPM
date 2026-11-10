<div class="page all-page">
  <header class="page-head">
    <h1>All tasks</h1>
    <p class="muted">Every task you can see, across projects. Tasks that aren’t in a project yet (for example ones created from meeting notes) wait in the <strong>Inbox</strong> at the top, so you can move each one to the right project.</p>
  </header>
  <div class="toolbar all-toolbar">
    <label class="search"><svg class="i" viewBox="0 0 20 20"><circle cx="9" cy="9" r="5"/><path d="M13 13l4 4"/></svg><input type="search" id="a-search" placeholder="Search tasks"></label>
    <label class="toggle-pill"><input type="checkbox" id="a-mine"> Assigned to me</label>
    <label class="toggle-pill"><input type="checkbox" id="a-hide-done" checked> Hide done</label>
    <label class="toggle-pill"><input type="checkbox" id="a-subtasks"> Show subtasks</label>
  </div>
  <div id="all-groups"></div>
</div>
<script id="boot" type="application/json"><?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= e(asset('peek.js')) ?>"></script>
<script src="<?= e(asset('all.js')) ?>"></script>
