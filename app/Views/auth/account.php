<div class="page narrow">
  <header class="page-head"><h1>Your account</h1></header>
  <form method="post" action="<?= e(url('/account')) ?>" class="card stack">
    <?= csrf_field() ?>
    <div class="row-center"><?= avatar($user, 'lg') ?><div><strong><?= e($user['name']) ?></strong><div class="muted"><?= e($user['email']) ?></div></div></div>
    <label class="field"><span>Name</span><input name="name" value="<?= e($user['name']) ?>" required maxlength="120"></label>
    <?= error_for('name') ?>
    <div class="actions"><a class="btn" href="<?= e(url('/account/password')) ?>">Change password</a><button class="btn btn-primary">Save</button></div>
  </form>
  <?php if ($orgs): ?>
  <div class="card">
    <h2 class="card-title">Your organizations</h2>
    <ul class="plain-list"><?php foreach ($orgs as $o): ?><li><a href="<?= e(url('/o/' . $o['slug'])) ?>"><?= e($o['name']) ?></a></li><?php endforeach; ?></ul>
  </div>
  <?php endif; ?>
</div>
