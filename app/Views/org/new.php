<div class="page narrow">
  <header class="page-head"><h1>New organization</h1></header>
  <form method="post" action="<?= e(url('/orgs')) ?>" class="card stack">
    <?= csrf_field() ?>
    <p class="hint">Each organization (e.g. a client like Sportskeeda) has its own members, projects and tasks. Nothing is shared between them.</p>
    <label class="field"><span>Organization name</span><input name="name" value="<?= e(old('name')) ?>" required maxlength="120" placeholder="Sportskeeda" autofocus></label>
    <?= error_for('name') ?>
    <label class="field"><span>First project</span><input name="project" value="<?= e(old('project', 'Creative & Design Hub')) ?>" maxlength="120"></label>
    <div class="field"><span>Its stages</span>
      <?= \App\Core\View::partial('partials/template_picker', ['selected' => old('template', 'design')]) ?>
    </div>
    <div class="actions"><button class="btn btn-primary">Create organization</button></div>
  </form>
</div>
