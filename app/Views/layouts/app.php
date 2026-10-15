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
</head>
<body>
<div class="shell">
  <aside class="sidebar" id="sidebar">
    <details class="org-switch">
      <summary>
        <span class="org-badge"><?= e(mb_strtoupper(mb_substr($org['name'] ?? 'L', 0, 1))) ?></span>
        <span class="org-name"><?= e($org['name'] ?? 'LeanPM') ?></span>
        <svg class="i" viewBox="0 0 20 20"><path d="M6 8l4 4 4-4"/></svg>
      </summary>
      <div class="menu">
        <?php foreach ($orgs as $o): ?>
          <a href="<?= e(url('/o/' . $o['slug'])) ?>" class="<?= $org && $o['id'] == $org['id'] ? 'is-active' : '' ?>"><?= e($o['name']) ?></a>
        <?php endforeach; ?>
        <?php if (Auth::isOwner()): ?>
          <a href="<?= e(url('/orgs/new')) ?>" class="muted">+ New organization</a>
        <?php endif; ?>
      </div>
    </details>

    <?php if ($org): $base = '/o/' . $org['slug']; ?>
    <nav class="side-nav">
      <div class="side-label">Projects
        <?php if (Access::can('manage_projects')): ?>
          <button type="button" class="icon-btn" data-open="new-project" title="New project">+</button>
        <?php endif; ?>
      </div>
      <?php foreach ($projects as $p): ?>
        <a href="<?= e(url($base . '/p/' . $p['id'])) ?>" class="side-link<?= $active($base . '/p/' . $p['id']) ?>">
          <svg class="i" viewBox="0 0 20 20"><rect x="3" y="4" width="4" height="12" rx="1"/><rect x="8" y="4" width="4" height="8" rx="1"/><rect x="13" y="4" width="4" height="10" rx="1"/></svg>
          <span><?= e($p['name']) ?></span>
        </a>
      <?php endforeach; ?>
      <?php if (!$projects): ?><p class="side-empty">No projects yet.</p><?php endif; ?>

      <div class="side-label">Workspace</div>
      <a href="<?= e(url($base . '/members')) ?>" class="side-link<?= $active($base . '/members') ?>">
        <svg class="i" viewBox="0 0 20 20"><circle cx="8" cy="7" r="3"/><path d="M2.5 16c.8-3 3-4.5 5.5-4.5s4.7 1.5 5.5 4.5"/><path d="M13 4.5a2.5 2.5 0 010 5M15 11.5c1.3.6 2.1 1.8 2.5 3.5"/></svg>
        <span>Members</span>
      </a>
      <?php if (Access::can('export')): ?>
      <a href="<?= e(url($base . '/export')) ?>" class="side-link<?= $active($base . '/export') ?>">
        <svg class="i" viewBox="0 0 20 20"><path d="M10 3v9M6 8l4 4 4-4M4 15h12"/></svg>
        <span>Export reports</span>
      </a>
      <?php endif; ?>
      <?php if (Access::level('delete_tasks') === 'all'): ?>
      <a href="<?= e(url($base . '/trash')) ?>" class="side-link<?= $active($base . '/trash') ?>">
        <svg class="i" viewBox="0 0 20 20"><path d="M4 6h12M8 6V4h4v2M6 6l1 10h6l1-10"/></svg>
        <span>Trash</span>
      </a>
      <?php endif; ?>
      <?php if (Access::can('org_settings')): ?>
      <a href="<?= e(url($base . '/settings')) ?>" class="side-link<?= $active($base . '/settings') ?>">
        <svg class="i" viewBox="0 0 20 20"><circle cx="10" cy="10" r="2.5"/><path d="M10 2.5v2M10 15.5v2M2.5 10h2M15.5 10h2M4.7 4.7l1.4 1.4M13.9 13.9l1.4 1.4M4.7 15.3l1.4-1.4M13.9 6.1l1.4-1.4"/></svg>
        <span>Settings</span>
      </a>
      <?php endif; ?>
    </nav>
    <?php endif; ?>

    <div class="side-foot">
      <a href="<?= e(url('/account')) ?>" class="me">
        <?= avatar($user) ?>
        <span><?= e($user['name']) ?></span>
      </a>
      <form method="post" action="<?= e(url('/logout')) ?>"><?= csrf_field() ?><button class="link-btn" type="submit">Sign out</button></form>
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
<dialog id="new-project" class="dialog">
  <form method="post" action="<?= e(url('/o/' . $org['slug'] . '/projects')) ?>">
    <?= csrf_field() ?>
    <h2>New project</h2>
    <label class="field"><span>Name</span><input name="name" required maxlength="120" placeholder="e.g. Cricrocket redesign" autofocus></label>
    <p class="hint">It starts with the default statuses (Requested → Approved). You can change them in the project’s settings.</p>
    <div class="actions"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Create project</button></div>
  </form>
</dialog>
<?php endif; ?>
<script src="<?= e(asset('ui.js')) ?>"></script>
</body>
</html>
