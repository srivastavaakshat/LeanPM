<?php
use App\Core\Auth;
$creds = $_SESSION['admin_credentials'] ?? null;
unset($_SESSION['admin_credentials']);
?>
<div class="page">
  <header class="page-head row-between">
    <div>
      <h1>Manage LeanPM</h1>
      <p class="muted">As an owner you create organizations and choose who runs them. You only see an organization’s projects and tasks if you’re a member of it.</p>
    </div>
    <a class="btn btn-primary" href="<?= e(url('/orgs/new')) ?>">+ New organization</a>
  </header>

  <?php if ($creds): $mailOn = \App\Services\Mailer::configured(); ?>
  <div class="card creds">
    <h2 class="card-title">Invite for <?= e($creds['name']) ?></h2>
    <?php if ($mailOn): ?>
      <p>We emailed <strong><?= e($creds['email']) ?></strong> a link to set their password and start as <?= e($creds['what']) ?>. It works once, for 7 days. If it doesn’t arrive, send them this link yourself:</p>
    <?php else: ?>
      <p><strong>Email isn’t set up on this server yet</strong>, so nothing was sent. Send <?= e($creds['email']) ?> this link yourself. It works once, for 7 days, and makes them <?= e($creds['what']) ?>:</p>
    <?php endif; ?>
    <pre id="creds-text"><?= e($creds['link']) ?></pre>
    <button type="button" class="btn btn-sm" data-copy="#creds-text">Copy link</button>
  </div>
  <?php endif; ?>

  <h2 class="section-title">Organizations</h2>
  <?php if (!$orgs): ?>
    <div class="card empty-state">
      <h2>No organizations yet</h2>
      <p>Create one for each team or client, then invite the person who will run it.</p>
      <a class="btn btn-primary" href="<?= e(url('/orgs/new')) ?>">+ New organization</a>
    </div>
  <?php else: ?>
  <div class="card table-card">
    <table class="table">
      <thead><tr><th>Organization</th><th>Admins</th><th>Size</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($orgs as $o): ?>
        <tr>
          <td>
            <strong><?= e($o['name']) ?></strong>
            <div class="muted small">Created <?= e(fmt_date($o['created_at'], 'j M Y')) ?><?= (int) $o['my_membership'] === 1 ? ' · <a href="' . e(url('/o/' . $o['slug'])) . '">Open</a>' : ' · You’re not a member' ?></div>
          </td>
          <td>
            <?php if ($o['admins']): ?>
              <?php foreach ($o['admins'] as $a): ?><div><?= e($a['name']) ?> <span class="muted small"><?= e($a['email']) ?></span></div><?php endforeach; ?>
            <?php else: ?><span class="tag">No admin yet</span><?php endif; ?>
          </td>
          <td class="muted small"><?= (int) $o['members'] ?> <?= (int) $o['members'] === 1 ? 'member' : 'members' ?> · <?= (int) $o['projects'] ?> <?= (int) $o['projects'] === 1 ? 'project' : 'projects' ?> · <?= (int) $o['tasks'] ?> tasks</td>
          <td class="right">
            <span class="row-center gap-s" style="justify-content:flex-end">
              <button type="button" class="btn btn-sm" data-open="add-admin-<?= (int) $o['id'] ?>">Add admin</button>
              <button type="button" class="btn btn-sm" data-open="rename-<?= (int) $o['id'] ?>">Rename</button>
              <button type="button" class="btn btn-sm btn-danger" data-open="delete-<?= (int) $o['id'] ?>">Delete</button>
            </span>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <h2 class="section-title">Owners</h2>
  <div class="card table-card">
    <table class="table">
      <thead><tr><th>Name</th><th>Last sign-in</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($owners as $u): ?>
        <tr>
          <td><div class="row-center"><?= avatar($u) ?><div><strong><?= e($u['name']) ?></strong><?= (int) $u['id'] === Auth::id() ? ' <span class="tag">You</span>' : '' ?><div class="muted small"><?= e($u['email']) ?></div></div></div></td>
          <td class="muted"><?= $u['last_login_at'] === null ? 'Invited, not signed in yet' : e(fmt_date($u['last_login_at'], 'j M, g:i a')) ?></td>
          <td class="right">
            <?php if ((int) $u['id'] === Auth::id()): ?>
              <a class="btn btn-sm" href="<?= e(url('/account')) ?>">Edit your details</a>
            <?php else: ?>
              <form method="post" action="<?= e(url('/admin/owners/' . (int) $u['id'] . '/remove')) ?>" data-confirm="Remove <?= e($u['name']) ?> as an owner? They keep any organization memberships they have."><?= csrf_field() ?><button class="btn btn-sm">Remove owner</button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <form method="post" action="<?= e(url('/admin/owners')) ?>" class="card stack">
    <?= csrf_field() ?>
    <h2 class="card-title">Add an owner</h2>
    <p class="hint">Owners can create, rename and delete organizations, add an organization’s admin, and add or remove owners. Nothing else: to work in an organization they need to be added to it as a member.</p>
    <div class="grid-2">
      <label class="field"><span>Name</span><input name="name" maxlength="120" placeholder="Not needed if they already have an account"></label>
      <label class="field"><span>Email</span><input name="email" type="email" required maxlength="190"></label>
    </div>
    <div class="actions"><button class="btn btn-primary">Add owner</button></div>
  </form>
</div>

<?php foreach ($orgs as $o): ?>
<dialog id="add-admin-<?= (int) $o['id'] ?>" class="dialog">
  <form method="post" action="<?= e(url('/admin/orgs/' . (int) $o['id'] . '/admins')) ?>">
    <?= csrf_field() ?>
    <h2>Add an admin to <?= e($o['name']) ?></h2>
    <p class="hint">They get every permission in <?= e($o['name']) ?> and can add the rest of the team. New people get an email to set their password. Use your own email to join it yourself.</p>
    <label class="field"><span>Name</span><input name="name" maxlength="120" placeholder="Not needed if they already have an account"></label>
    <label class="field"><span>Email</span><input name="email" type="email" required maxlength="190" autofocus></label>
    <div class="actions"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Add admin</button></div>
  </form>
</dialog>
<dialog id="rename-<?= (int) $o['id'] ?>" class="dialog">
  <form method="post" action="<?= e(url('/admin/orgs/' . (int) $o['id'] . '/rename')) ?>">
    <?= csrf_field() ?>
    <h2>Rename organization</h2>
    <label class="field"><span>Name</span><input name="name" value="<?= e($o['name']) ?>" required maxlength="120" autofocus></label>
    <p class="hint">Its web address stays the same, so links people saved keep working.</p>
    <div class="actions"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save</button></div>
  </form>
</dialog>
<dialog id="delete-<?= (int) $o['id'] ?>" class="dialog">
  <form method="post" action="<?= e(url('/admin/orgs/' . (int) $o['id'] . '/delete')) ?>" class="delete-org">
    <?= csrf_field() ?>
    <h2>Delete <?= e($o['name']) ?>?</h2>
    <p class="hint">Permanently deletes its <?= (int) $o['projects'] ?> projects, <?= (int) $o['tasks'] ?> tasks, every comment and uploaded file, meeting notes, and its memberships. This can’t be undone. People’s accounts stay, so they can still sign in to other organizations.</p>
    <label class="field"><span>Type <strong><?= e($o['name']) ?></strong> to confirm</span>
      <input name="confirm_name" autocomplete="off" required data-must-equal="<?= e($o['name']) ?>"></label>
    <div class="actions"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-danger" disabled>Delete organization</button></div>
  </form>
</dialog>
<?php endforeach; ?>
<script>
document.querySelectorAll('.delete-org').forEach(f => {
  const i = f.querySelector('[data-must-equal]');
  i.addEventListener('input', () => { f.querySelector('.btn-danger').disabled = i.value.trim() !== i.dataset.mustEqual; });
});
</script>
