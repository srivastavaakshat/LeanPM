<?php
use App\Core\Auth;
use App\Services\Access;

$user = Auth::user();
$org = Access::$org;
$orgs = Access::myOrgs();
$path = $GLOBALS['__path'] ?? '/';
$projects = $org ? Access::projects() : [];
$active = fn (string $p) => $path === $p || str_starts_with($path, $p . '/') ? ' is-active' : '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
<title><?= e(($title ?? '') !== '' ? $title . ' · LeanPM' : 'LeanPM') ?></title>
<link rel="icon" href="<?= e(asset('favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
<script>try { if (localStorage.getItem('lpm:side') === 'collapsed') document.documentElement.classList.add('side-collapsed'); } catch (e) {}</script>
</head>
<body>
<div class="shell">
  <aside class="sidebar" id="sidebar">
    <div class="side-top">
      <details class="org-switch">
        <summary data-tip="<?= e($org['name'] ?? 'LeanPM') ?>" aria-label="<?= e($org['name'] ?? 'LeanPM') ?>">
          <span class="org-badge"><?= e(mb_strtoupper(mb_substr($org['name'] ?? 'L', 0, 1))) ?></span>
          <span class="org-name label"><?= e($org['name'] ?? 'LeanPM') ?></span>
          <svg class="i label" viewBox="0 0 20 20"><path d="M6 8l4 4 4-4"/></svg>
        </summary>
        <div class="menu">
          <?php foreach ($orgs as $o): ?>
            <a href="<?= e(url('/o/' . $o['slug'])) ?>" class="<?= $org && $o['id'] == $org['id'] ? 'is-active' : '' ?>"><?= e($o['name']) ?></a>
          <?php endforeach; ?>
          <?php if ($org && Access::can('org_settings')): ?>
            <a href="<?= e(url('/o/' . $org['slug'] . '/settings')) ?>" class="muted">Organization settings…</a>
          <?php endif; ?>
          <?php if (!$orgs): ?><span class="muted small menu-note">You aren’t a member of any organization.</span><?php endif; ?>
          <?php if (Auth::isOwner()): ?>
            <a href="<?= e(url('/admin')) ?>" class="muted">Manage LeanPM…</a>
          <?php endif; ?>
        </div>
      </details>
      <button type="button" class="icon-btn side-toggle" id="side-toggle" data-tip="Collapse sidebar ([)" data-tip-always>
        <svg class="i" viewBox="0 0 20 20"><rect x="3" y="4" width="14" height="12" rx="2"/><path d="M8 4v12"/></svg>
      </button>
    </div>

    <?php if ($org): $base = '/o/' . $org['slug']; ?>
    <nav class="side-nav">
      <?php $inboxOpen = (int) \App\Core\Database::value("SELECT COUNT(*) FROM tasks t JOIN statuses s ON s.id = t.status_id WHERE t.project_id = ? AND t.deleted_at IS NULL AND t.parent_id IS NULL AND s.type <> 'done'", [Access::inboxId()]); ?>
      <a href="<?= e(url($base . '/tasks')) ?>" class="side-link<?= $active($base . '/tasks') ?>" data-tip="All tasks<?= $inboxOpen ? " · {$inboxOpen} in Inbox" : '' ?>" aria-label="All tasks">
        <svg class="i" viewBox="0 0 20 20"><rect x="3.5" y="3.5" width="13" height="13" rx="3"/><path d="M7 10.2l2.2 2.2L13.5 8"/></svg>
        <span class="label">All tasks</span>
        <?php if ($inboxOpen): ?><span class="side-badge" title="<?= $inboxOpen ?> in the Inbox, not in a project yet"><?= $inboxOpen ?></span><?php endif; ?>
      </a>
      <?php if (Access::can('meeting_notes')): ?>
      <a href="<?= e(url($base . '/notes')) ?>" class="side-link<?= $active($base . '/notes') ?>" data-tip="Meeting notes" aria-label="Meeting notes">
        <svg class="i" viewBox="0 0 20 20"><path d="M6 3h5l4 4v9a1 1 0 01-1 1H6a1 1 0 01-1-1V4a1 1 0 011-1z"/><path d="M11 3v4h4M8 11h5M8 14h3"/></svg>
        <span class="label">Meeting notes</span>
      </a>
      <?php endif; ?>

      <div class="side-label"><span class="label">Projects</span>
        <?php if (Access::can('manage_projects')): ?>
          <button type="button" class="icon-btn" data-open="new-project" data-tip="New project" aria-label="New project">+</button>
        <?php endif; ?>
      </div>
      <?php foreach ($projects as $p): ?>
        <a href="<?= e(url($base . '/p/' . $p['id'])) ?>" class="side-link<?= $active($base . '/p/' . $p['id']) ?>" data-tip="<?= e($p['name']) ?>" aria-label="<?= e($p['name']) ?>">
          <span class="proj-badge" style="--pc:<?= e(project_color((int) $p['id'])) ?>"><?= e(mb_strtoupper(mb_substr($p['name'], 0, 1))) ?></span>
          <span class="label"><?= e($p['name']) ?></span>
        </a>
      <?php endforeach; ?>
      <?php if (!$projects): ?><p class="side-empty label">No projects yet.</p><?php endif; ?>

      <div class="side-label"><span class="label">Workspace</span></div>
      <a href="<?= e(url($base . '/members')) ?>" class="side-link<?= $active($base . '/members') ?>" data-tip="Members" aria-label="Members">
        <svg class="i" viewBox="0 0 20 20"><circle cx="8" cy="7" r="3"/><path d="M2.5 16c.8-3 3-4.5 5.5-4.5s4.7 1.5 5.5 4.5"/><path d="M13 4.5a2.5 2.5 0 010 5M15 11.5c1.3.6 2.1 1.8 2.5 3.5"/></svg>
        <span class="label">Members</span>
      </a>
      <?php if (Access::can('export')): ?>
      <a href="<?= e(url($base . '/export')) ?>" class="side-link<?= $active($base . '/export') ?>" data-tip="Export reports" aria-label="Export reports">
        <svg class="i" viewBox="0 0 20 20"><path d="M10 3v9M6 8l4 4 4-4M4 15h12"/></svg>
        <span class="label">Export reports</span>
      </a>
      <?php endif; ?>
      <?php if (Access::level('delete_tasks') === 'all'): ?>
      <a href="<?= e(url($base . '/trash')) ?>" class="side-link<?= $active($base . '/trash') ?>" data-tip="Trash" aria-label="Trash">
        <svg class="i" viewBox="0 0 20 20"><path d="M4 6h12M8 6V4h4v2M6 6l1 10h6l1-10"/></svg>
        <span class="label">Trash</span>
      </a>
      <?php endif; ?>
      <?php if (Access::can('org_settings')): ?>
      <a href="<?= e(url($base . '/settings')) ?>" class="side-link<?= $active($base . '/settings') ?>" data-tip="Settings" aria-label="Settings">
        <svg class="i" viewBox="0 0 20 20"><circle cx="10" cy="10" r="2.5"/><path d="M10 2.5v2M10 15.5v2M2.5 10h2M15.5 10h2M4.7 4.7l1.4 1.4M13.9 13.9l1.4 1.4M4.7 15.3l1.4-1.4M13.9 6.1l1.4-1.4"/></svg>
        <span class="label">Settings</span>
      </a>
      <?php endif; ?>
    </nav>
    <?php endif; ?>

    <?php if (Auth::isOwner()): ?>
    <nav class="side-nav side-nav-owner">
      <a href="<?= e(url('/admin')) ?>" class="side-link<?= $active('/admin') ?>" data-tip="Manage LeanPM" aria-label="Manage LeanPM">
        <svg class="i" viewBox="0 0 20 20"><path d="M3 17V7l7-4 7 4v10"/><path d="M7 17v-5h6v5M3 17h14"/></svg>
        <span class="label">Manage LeanPM</span>
      </a>
    </nav>
    <?php endif; ?>

    <div class="side-foot">
      <a href="<?= e(url('/account')) ?>" class="me" data-tip="Your account" aria-label="Your account">
        <?= avatar($user) ?>
        <span class="label"><?= e($user['name']) ?></span>
      </a>
      <form method="post" action="<?= e(url('/logout')) ?>"><?= csrf_field() ?>
        <button class="icon-btn" type="submit" data-tip="Sign out" aria-label="Sign out" data-tip-always>
          <svg class="i" viewBox="0 0 20 20"><path d="M8 4H5a1 1 0 00-1 1v10a1 1 0 001 1h3M12 13l3-3-3-3M15 10H8"/></svg>
        </button>
      </form>
    </div>
  </aside>

  <div class="main">
    <button type="button" class="menu-toggle" aria-label="Menu" onclick="document.body.classList.toggle('nav-open')">
      <svg class="i" viewBox="0 0 20 20"><path d="M3 6h14M3 10h14M3 14h14"/></svg>
    </button>
    <?php foreach (\App\Core\Session::flashes() as $f): ?>
      <div class="flash flash-<?= e($f['type']) ?>" data-autohide><?= e($f['message']) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
  </div>
</div>

<?php if ($org && Access::can('manage_projects')): ?>
<dialog id="new-project" class="dialog dialog-wide">
  <form method="post" action="<?= e(url('/o/' . $org['slug'] . '/projects')) ?>">
    <?= csrf_field() ?>
    <h2>New project</h2>
    <label class="field"><span>Name</span><input name="name" required maxlength="120" placeholder="e.g. Website redesign" autofocus></label>
    <div class="field"><span>Template</span>
      <?= \App\Core\View::partial('partials/template_picker', ['selected' => 'design']) ?>
    </div>
    <p class="hint">The template sets the starting stages and fields. Change both any time in the project’s settings. Each project has its own.</p>
    <div class="actions"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Create project</button></div>
  </form>
</dialog>
<?php endif; ?>
<script src="<?= e(asset('ui.js')) ?>"></script>
</body>
</html>
