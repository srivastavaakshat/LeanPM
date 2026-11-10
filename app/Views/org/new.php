<div class="page narrow">
  <header class="page-head"><h1>New organization</h1></header>
  <form method="post" action="<?= e(url('/orgs')) ?>" class="card stack">
    <?= csrf_field() ?>
    <p class="hint">Each organization (e.g. one per client) has its own members, projects and tasks. Nothing is shared between them.</p>
    <label class="field"><span>Organization name</span><input name="name" value="<?= e(old('name')) ?>" required maxlength="120" placeholder="Acme Studio" autofocus></label>
    <?= error_for('name') ?>
    <label class="field"><span>First project</span><input name="project" value="<?= e(old('project', 'Creative & Design Hub')) ?>" maxlength="120"></label>
    <div class="field"><span>Its template (stages and fields)</span>
      <?= \App\Core\View::partial('partials/template_picker', ['selected' => old('template', 'design')]) ?>
    </div>
    <fieldset class="field"><legend>Who runs it (optional)</legend>
      <p class="hint">They become its admin, with every permission, and get an email to join. You aren’t added yourself unless you enter your own email (<?= e(\App\Core\Auth::user()['email']) ?>). You can also add an admin later from Manage LeanPM.</p>
      <div class="grid-2">
        <label class="field"><span>Name</span><input name="admin_name" value="<?= e(old('admin_name')) ?>" maxlength="120"></label>
        <label class="field"><span>Email</span><input name="admin_email" type="email" value="<?= e(old('admin_email')) ?>" maxlength="190"></label>
      </div>
      <?= error_for('admin_name') ?><?= error_for('admin_email') ?>
    </fieldset>
    <div class="actions"><a class="btn" href="<?= e(url('/admin')) ?>">Cancel</a><button class="btn btn-primary">Create organization</button></div>
  </form>
</div>
