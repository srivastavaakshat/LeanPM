<?php
use App\Services\Access;
$org = Access::$org;
$base = '/o/' . $org['slug'] . '/p/' . $project['id'];
?>
<div class="board-page" id="app">
  <header class="board-head">
    <div class="row-center">
      <h1><?= e($project['name']) ?></h1>
      <?php if (Access::can('manage_statuses') || Access::can('manage_projects')): ?>
        <a class="icon-btn" href="<?= e(url($base . '/settings')) ?>" title="Project settings and stages">
          <svg class="i" viewBox="0 0 20 20"><circle cx="10" cy="10" r="2.5"/><path d="M10 2.5v2M10 15.5v2M2.5 10h2M15.5 10h2M4.7 4.7l1.4 1.4M13.9 13.9l1.4 1.4M4.7 15.3l1.4-1.4M13.9 6.1l1.4-1.4"/></svg>
        </a>
      <?php endif; ?>
    </div>
    <div class="row-center">
    <?php if (Access::can('create_tasks')): ?>
      <a class="btn btn-sm" href="<?= e(url($base . '/import')) ?>" title="Import tasks from a CSV">
        <svg class="i" viewBox="0 0 20 20"><path d="M10 13V3M6 9l4 4 4-4M4 16h12"/></svg> Import
      </a>
    <?php endif; ?>
    <?php if (Access::can('manage_statuses')): ?>
      <a class="btn btn-sm" href="<?= e(url($base . '/settings')) ?>">Edit stages</a>
    <?php endif; ?>
    <div class="view-toggle" role="tablist">
      <button type="button" data-view="board" role="tab">
        <svg class="i" viewBox="0 0 20 20"><rect x="3" y="4" width="4" height="12" rx="1"/><rect x="8" y="4" width="4" height="8" rx="1"/><rect x="13" y="4" width="4" height="10" rx="1"/></svg> Board
      </button>
      <button type="button" data-view="list" role="tab">
        <svg class="i" viewBox="0 0 20 20"><path d="M7 5h10M7 10h10M7 15h10M3.5 5h.01M3.5 10h.01M3.5 15h.01"/></svg> List
      </button>
      <button type="button" data-view="files" role="tab">
        <svg class="i" viewBox="0 0 20 20"><path d="M6 3h5l4 4v9a1 1 0 01-1 1H6a1 1 0 01-1-1V4a1 1 0 011-1z"/><path d="M11 3v4h4"/></svg> Files
      </button>
    </div>
    </div>
  </header>

  <div class="toolbar">
    <label class="search"><svg class="i" viewBox="0 0 20 20"><circle cx="9" cy="9" r="5"/><path d="M13 13l4 4"/></svg><input type="search" id="f-search" placeholder="Search tasks"></label>
    <div class="file-filters" id="file-filters" hidden>
      <button type="button" class="pill-select is-set" data-kind="">All</button>
      <button type="button" class="pill-select" data-kind="image">Images</button>
      <button type="button" class="pill-select" data-kind="pdf">PDFs</button>
      <button type="button" class="pill-select" data-kind="doc">Docs &amp; sheets</button>
      <button type="button" class="pill-select" data-kind="design">Design files</button>
      <button type="button" class="pill-select" data-kind="other">Other</button>
    </div>
    <div class="task-filters" id="task-filters">
    <div class="filter" id="f-assignee"></div>
    <select id="f-priority" class="pill-select" aria-label="Priority">
      <option value="">Any priority</option>
      <?php foreach (\App\Services\Tasks::PRIORITIES as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?>
      <option value="none">No priority</option>
    </select>
    <select id="f-due" class="pill-select" aria-label="Due date">
      <option value="">Any due date</option>
      <option value="overdue">Overdue</option>
      <option value="today">Due today</option>
      <option value="week">Due in 7 days</option>
      <option value="none">No due date</option>
    </select>
    <label class="toggle-pill"><input type="checkbox" id="f-subtasks"> Show subtasks</label>
    <label class="toggle-pill"><input type="checkbox" id="f-hide-done"> Hide done</label>
    <button type="button" class="link-btn" id="f-clear" hidden>Clear filters</button>
    </div>
  </div>

  <div id="view" class="view"></div>
</div>

<div class="drawer-backdrop" id="drawer-backdrop" hidden></div>
<aside class="drawer" id="drawer" hidden aria-label="Task"></aside>

<script id="boot" type="application/json"><?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= e(asset('board.js')) ?>"></script>
