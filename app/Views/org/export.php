<?php use App\Services\Access; $org = Access::$org; ?>
<div class="page narrow">
  <header class="page-head">
    <h1>Export reports</h1>
    <p class="muted">Download tasks as a CSV to open in Excel or Google Sheets, e.g. everything one designer finished in the last 3 months.</p>
  </header>
  <form method="get" action="<?= e(url('/o/' . $org['slug'] . '/export/tasks.csv')) ?>" class="card stack" id="export-form">
    <?php if ($ownOnly): ?>
      <p class="note">You can export the tasks you’re assigned to.</p>
    <?php else: ?>
    <fieldset class="field">
      <legend>Team members <span class="muted">(none ticked = everyone)</span></legend>
      <div class="chips">
        <?php foreach ($members as $m): ?>
          <label class="chip-check"><input type="checkbox" name="members[]" value="<?= (int) $m['id'] ?>"><?= avatar($m, 'sm') ?> <?= e($m['name']) ?><?= $m['active'] ? '' : ' <span class="muted">(inactive)</span>' ?></label>
        <?php endforeach; ?>
      </div>
    </fieldset>
    <?php endif; ?>

    <div class="grid-2">
      <label class="field"><span>Date range</span>
        <select name="range" id="range">
          <option value="30">Last 30 days</option>
          <option value="60">Last 60 days</option>
          <option value="90" selected>Last 90 days</option>
          <option value="quarter">This quarter</option>
          <option value="180">Last 6 months</option>
          <option value="365">Last 12 months</option>
          <option value="all">All time</option>
          <option value="custom">Custom…</option>
        </select>
      </label>
      <label class="field"><span>The range applies to</span>
        <select name="basis">
          <option value="completed">Completed date</option>
          <option value="created">Created date</option>
          <option value="due">Due date</option>
        </select>
      </label>
    </div>
    <div class="grid-2" id="custom-range" hidden>
      <label class="field"><span>From</span><input type="date" name="from"></label>
      <label class="field"><span>To</span><input type="date" name="to" value="<?= date('Y-m-d') ?>"></label>
    </div>

    <fieldset class="field">
      <legend>Projects <span class="muted">(none ticked = all)</span></legend>
      <div class="chips">
        <?php foreach ($projects as $p): ?>
          <label class="chip-check"><input type="checkbox" name="projects[]" value="<?= (int) $p['id'] ?>"> <?= e($p['name']) ?><?= $p['archived'] ? ' <span class="muted">(archived)</span>' : '' ?></label>
        <?php endforeach; ?>
      </div>
    </fieldset>

    <fieldset class="field">
      <legend>Status <span class="muted">(none ticked = all)</span></legend>
      <div class="chips">
        <label class="chip-check"><input type="checkbox" name="types[]" value="open"> Not started</label>
        <label class="chip-check"><input type="checkbox" name="types[]" value="active"> In progress</label>
        <label class="chip-check"><input type="checkbox" name="types[]" value="done"> Done</label>
      </div>
    </fieldset>

    <label class="check"><input type="hidden" name="subtasks" value="0"><input type="checkbox" name="subtasks" value="1" checked> Include subtasks</label>

    <div class="actions">
      <button class="btn" type="submit" formaction="<?= e(url('/o/' . $org['slug'] . '/export/comments.csv')) ?>">Download comments CSV</button>
      <button class="btn btn-primary" type="submit">Download tasks CSV</button>
    </div>
    <p class="hint">Tasks CSV columns: Task ID, Title, Project, Parent Task, Status, Priority, Assignees, Created By, Created At, Start Date, Due Date, Completed At, Days to Complete, Overdue, Comment Count, Attachment URLs, Task URL.</p>
  </form>
</div>
<script>document.getElementById('range').addEventListener('change', e => { document.getElementById('custom-range').hidden = e.target.value !== 'custom'; });</script>
