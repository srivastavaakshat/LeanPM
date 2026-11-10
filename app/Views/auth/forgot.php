<h1>Reset your password</h1>
<?php if ($sent): unset($_SESSION['forgot_sent']); ?>
  <div class="flash flash-success">If that email has a LeanPM account, a reset link is on its way. It works for 1 hour. Check your spam folder too.</div>
  <p class="hint"><a href="<?= e(url('/login')) ?>">Back to sign in</a></p>
<?php else: ?>
  <p class="hint" style="margin:0 0 16px">Enter the email you sign in with and we’ll send you a link to choose a new password.</p>
  <form method="post" action="<?= e(url('/password/forgot')) ?>" class="stack">
    <?= csrf_field() ?>
    <label class="field"><span>Email</span><input type="email" name="email" required autofocus autocomplete="username"></label>
    <button class="btn btn-primary btn-block">Send reset link</button>
  </form>
  <p class="hint"><a href="<?= e(url('/login')) ?>">Back to sign in</a></p>
<?php endif; ?>
