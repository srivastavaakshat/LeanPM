<h1><?= $forced ? 'Set your password' : 'Change password' ?></h1>
<?php if ($forced): ?><p class="hint">You signed in with a temporary password. Choose your own to continue.</p><?php endif; ?>
<form method="post" action="<?= e(url('/account/password')) ?>" class="stack">
  <?= csrf_field() ?>
  <?php if (!$forced): ?>
    <label class="field"><span>Current password</span><input type="password" name="current" required autocomplete="current-password"></label>
    <?= error_for('current') ?>
  <?php endif; ?>
  <label class="field"><span>New password</span><input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
  <label class="field"><span>Repeat new password</span><input type="password" name="password_confirm" required minlength="8" autocomplete="new-password"></label>
  <?= error_for('password') ?>
  <button class="btn btn-primary btn-block">Save password</button>
</form>
<?php if (!$forced): ?><p class="hint"><a href="<?= e(url('/account')) ?>">Back</a></p><?php endif; ?>
