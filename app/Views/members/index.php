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

  <?php if ($creds): $mailOn = \App\Services\Mailer::configured(); ?>
  <div class="card creds">
    <h2 class="card-title"><?= match ($creds['kind']) { 'invite' => 'Invite for ', default => 'Password reset for ' } ?><?= e($creds['name']) ?></h2>
    <?php if ($creds['link'] === null): ?>
      <p>We emailed <strong><?= e($creds['email']) ?></strong> a link to <?= $creds['kind'] === 'invite' ? 'set their password' : 'choose a new password' ?>. Their account also reaches beyond <?= e($org['name']) ?>, so the link goes to their inbox only.<?= $mailOn ? '' : ' <strong>Email isn’t set up on this server yet</strong>, so ask them to use “Forgot your password?” once it is.' ?></p>
    <?php else: ?>
    <?php if ($mailOn): ?>
      <p>We emailed <strong><?= e($creds['email']) ?></strong> a link to <?= $creds['kind'] === 'invite' ? 'set their password (valid for 7 days)' : 'choose a new password (valid for 3 days)' ?>. If it doesn’t arrive, send them this link yourself:</p>
    <?php else: ?>
      <p><strong>Email isn’t set up on this server yet</strong>, so nothing was sent. Send <?= e($creds['email']) ?> this link yourself (WhatsApp, Slack…). It works once, for <?= $creds['kind'] === 'invite' ? '7' : '3' ?> days:</p>
    <?php endif; ?>
    <pre id="creds-text"><?= e($creds['link']) ?></pre>
    <button type="button" class="btn btn-sm" data-copy="#creds-text">Copy link</button>
    <?php endif; ?>
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
          <td><?= $r['all_projects'] ? 'All' : 'Selected' ?></td>
          <td class="muted"><?= $r['last_login_at'] === null ? 'Invited, not signed in yet' : e(fmt_date($r['last_login_at'], 'j M, g:i a')) ?></td>
          <td class="right">
            <?php if ($canManage && (int) $r['user_id'] !== \App\Core\Auth::id()): ?>
              <span class="row-center gap-s" style="justify-content:flex-end">
              <?php if ($r['last_login_at'] === null && $r['active']): ?>
                <form method="post" action="<?= e(url('/o/' . $org['slug'] . '/members/' . $r['id'] . '/reset-password')) ?>"><?= csrf_field() ?><button class="btn btn-sm" title="Email them a new invite link">Resend invite</button></form>
              <?php endif; ?>
              <a class="btn btn-sm" href="<?= e(url('/o/' . $org['slug'] . '/members/' . $r['id'])) ?>">Permissions</a>
              </span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
