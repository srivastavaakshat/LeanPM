<div class="page narrow">
  <header class="page-head"><h1>Your account</h1></header>
  <form method="post" action="<?= e(url('/account')) ?>" class="card stack">
    <?= csrf_field() ?>
    <div class="row-center"><?= avatar($user, 'lg') ?><div><strong><?= e($user['name']) ?></strong><div class="muted"><?= e($user['email']) ?></div></div></div>
    <label class="field"><span>Name</span><input name="name" value="<?= e($user['name']) ?>" required maxlength="120"></label>
    <?= error_for('name') ?>
    <label class="check"><input type="hidden" name="notify_mentions" value="0"><input type="checkbox" name="notify_mentions" value="1" <?= $notify ? 'checked' : '' ?>> Email me when someone @mentions me</label>
    <div class="actions"><a class="btn" href="<?= e(url('/account/password')) ?>">Change password</a><button class="btn btn-primary">Save</button></div>
  </form>
  <?php if (\App\Services\GoogleAuth::enabled()): ?>
  <div class="card row-between">
    <div><h2 class="card-title" style="margin:0 0 2px">Google sign-in</h2>
      <div class="muted small"><?= $googleLinked ? 'Connected. You can sign in with Google or your password.' : 'Not connected. Connect it to sign in with one click.' ?></div></div>
    <?php if ($googleLinked): ?>
      <form method="post" action="<?= e(url('/auth/google/unlink')) ?>" data-confirm="Disconnect Google? You’ll sign in with your email and password (reset it first if you don’t know it)."><?= csrf_field() ?><button class="btn btn-sm">Disconnect</button></form>
    <?php else: ?>
      <a class="btn btn-sm btn-google" href="<?= e(url('/auth/google')) ?>"><svg class="g-logo" viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg> Connect Google</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <?php if ($orgs): ?>
  <div class="card">
    <h2 class="card-title">Your organizations</h2>
    <ul class="plain-list"><?php foreach ($orgs as $o): ?><li><a href="<?= e(url('/o/' . $o['slug'])) ?>"><?= e($o['name']) ?></a></li><?php endforeach; ?></ul>
  </div>
  <?php endif; ?>
</div>
