<h1><?= $invite ? 'Welcome, ' . e(strtok($user['name'], ' ')) : 'Choose a new password' ?></h1>
<p class="hint" style="margin:0 0 16px"><?= $invite ? 'Choose a password for ' : 'For ' ?><strong><?= e($user['email']) ?></strong>.</p>
<form method="post" action="<?= e(url('/password/set/' . $token)) ?>" class="stack">
  <?= csrf_field() ?>
  <label class="field"><span>New password</span><input type="password" name="password" required minlength="8" autocomplete="new-password" autofocus></label>
  <label class="field"><span>Repeat it</span><input type="password" name="password_confirm" required minlength="8" autocomplete="new-password"></label>
  <?= error_for('password') ?>
  <button class="btn btn-primary btn-block"><?= $invite ? 'Set password and sign in' : 'Save and sign in' ?></button>
</form>
