<?php
use App\Services\Access;
$org = Access::$org;
$creds = $_SESSION['new_credentials'] ?? null;
unset($_SESSION['new_credentials']);
?>
<div class="page">
  <header class="page-head row-between">
    <div><h1>Members</h1><p class="muted">Everyone in <?= e($org['name']) ?> and what they can do.</p></div>
    <?php if ($canManage): ?><a class="btn btn-primary" href="<?= e(url('/o/' . $org['slug'] . '/members/new')) ?>">+ Add member</a><?php endif; ?>
  </header>

  <?php if ($creds): ?>
  <div class="card creds">
    <h2 class="card-title"><?= !empty($creds['reset']) ? 'New temporary password for ' : 'Added ' ?><?= e($creds['name']) ?></h2>
    <p>Send them these sign-in details. The password is shown only once; they’ll choose their own the first time they sign in.</p>
    <pre id="creds-text">Sign in to LeanPM: <?= e($creds['url']) . "\n" ?>Email: <?= e($creds['email']) . "\n" ?>Temporary password: <?= e($creds['password']) ?></pre>
    <button type="button" class="btn btn-sm" data-copy="#creds-text">Copy</button>
  </div>
  <?php endif; ?>

  <div class="card table-card">
    <table class="table">
      <thead><tr><th>Name</th><th>Access</th><th>Projects</th><th>Last sign-in</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr class="<?= $r['active'] ? '' : 'is-inactive' ?>">
          <td><div class="row-center"><?= avatar($r) ?><div><strong><?= e($r['name']) ?></strong><?= $r['active'] ? '' : ' <span class="tag">Inactive</span>' ?><div class="muted small"><?= e($r['email']) ?></div></div></div></td>
          <td><?= e($r['label']) ?></td>
          <td><?= $r['is_owner'] || $r['all_projects'] ? 'All' : 'Selected' ?></td>
          <td class="muted"><?= $r['must_change_password'] ? 'Not yet' : e(fmt_date($r['last_login_at'], 'j M, g:i a')) ?></td>
          <td class="right">
            <?php if ($canManage && !$r['is_owner'] && (int) $r['user_id'] !== \App\Core\Auth::id()): ?>
              <a class="btn btn-sm" href="<?= e(url('/o/' . $org['slug'] . '/members/' . $r['id'])) ?>">Permissions</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
