<h1>Sign in</h1>
<form method="post" action="<?= e(url('/login')) ?>" class="stack">
  <?= csrf_field() ?>
  <label class="field"><span>Email</span><input type="email" name="email" value="<?= e(old('email')) ?>" required autofocus autocomplete="username"></label>
  <label class="field"><span>Password</span><input type="password" name="password" required autocomplete="current-password"></label>
  <?= error_for('email') ?>
  <button class="btn btn-primary btn-block">Sign in</button>
</form>
<p class="hint">Accounts are created by your admin. Forgot your password? Ask them to reset it.</p>
