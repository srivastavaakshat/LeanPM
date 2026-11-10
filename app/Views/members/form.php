<?php
use App\Services\Access;
use App\Services\Perms;
$org = Access::$org;
$mine = Access::perms();
$limited = Access::$member['project_ids'] !== null;
$action = $member ? '/o/' . $org['slug'] . '/members/' . $member['id'] : '/o/' . $org['slug'] . '/members';
$presets = [];
foreach (Perms::PRESETS as $k => $p) {
    $presets[$k] = Perms::clamp(Perms::normalize($p['perms']), $mine);
}
?>
<div class="page narrow">
  <header class="page-head">
    <a class="back" href="<?= e(url('/o/' . $org['slug'] . '/members')) ?>">← Members</a>
    <h1><?= $member ? e($member['name']) : 'Add member' ?></h1>
    <?php if ($member): ?><p class="muted"><?= e($member['email']) ?></p><?php endif; ?>
  </header>

  <form method="post" action="<?= e(url($action)) ?>" class="stack" id="member-form">
    <?= csrf_field() ?>
    <?php if (!$member): ?>
    <div class="card stack">
      <div class="grid-2">
        <label class="field"><span>Name</span><input name="name" value="<?= e(old('name')) ?>" required maxlength="120" autofocus></label>
        <label class="field"><span>Email</span><input type="email" name="email" value="<?= e(old('email')) ?>" required maxlength="190"></label>
      </div>
      <?= error_for('name') ?><?= error_for('email') ?>
      <p class="hint">They’ll get an email with a link to set their own password. If they already have an account (from another organization), they keep their password and just get a heads-up.</p>
    </div>
    <?php endif; ?>

    <div class="card stack">
      <h2 class="card-title">Start from a preset</h2>
      <div class="presets">
        <?php foreach (Perms::PRESETS as $k => $p): ?>
          <label class="preset"><input type="radio" name="preset" value="<?= e($k) ?>" <?= $preset === $k ? 'checked' : '' ?>><span><?= e($p['label']) ?></span></label>
        <?php endforeach; ?>
      </div>
      <p class="hint">A preset only ticks the boxes below. Change any box afterwards. <span id="custom-note" class="tag" hidden>Customized</span></p>
    </div>

    <div class="card stack">
      <h2 class="card-title">Project access</h2>
      <?php if ($limited): ?>
        <p class="hint">You can only give access to projects you can see yourself.</p>
      <?php else: ?>
        <label class="check"><input type="radio" name="all_projects" value="1" <?= $allProjects ? 'checked' : '' ?>> All projects, including ones created later</label>
      <?php endif; ?>
      <label class="check"><input type="radio" name="all_projects" value="0" <?= !$allProjects || $limited ? 'checked' : '' ?>> Only these projects:</label>
      <div class="chips" id="project-list">
        <?php foreach ($projects as $p): ?>
          <label class="chip-check"><input type="checkbox" name="projects[]" value="<?= (int) $p['id'] ?>" <?= in_array((int) $p['id'], $projectIds, true) ? 'checked' : '' ?>> <?= e($p['name']) ?><?= $p['archived'] ? ' <span class="muted">(archived)</span>' : '' ?></label>
        <?php endforeach; ?>
      </div>
    </div>

    <?php foreach (Perms::groups() as $group => $defs): ?>
    <div class="card">
      <h2 class="card-title"><?= e($group) ?></h2>
      <div class="perm-list">
        <?php foreach ($defs as $key => $d):
          $value = $perms[$key];
          $max = $mine[$key]; ?>
          <div class="perm">
            <div><strong><?= e($d['label']) ?></strong><?php if ($d['help']): ?><div class="muted small"><?= e($d['help']) ?></div><?php endif; ?></div>
            <?php if ($d['kind'] === 'bool'): ?>
              <label class="switch"><input type="checkbox" name="perms[<?= e($key) ?>]" value="1" data-perm="<?= e($key) ?>" <?= $value ? 'checked' : '' ?> <?= $max ? '' : 'disabled' ?>><span></span></label>
            <?php else: ?>
              <div class="seg" role="radiogroup">
                <?php foreach (['none' => 'No', 'own' => 'Own tasks', 'all' => 'All tasks'] as $lv => $lbl):
                  $allowed = ['none' => 0, 'own' => 1, 'all' => 2][$lv] <= ['none' => 0, 'own' => 1, 'all' => 2][$max]; ?>
                  <label><input type="radio" name="perms[<?= e($key) ?>]" value="<?= $lv ?>" data-perm="<?= e($key) ?>" <?= $value === $lv ? 'checked' : '' ?> <?= $allowed ? '' : 'disabled' ?>><span><?= $lbl ?></span></label>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>

    <div class="actions sticky-actions">
      <a class="btn" href="<?= e(url('/o/' . $org['slug'] . '/members')) ?>">Cancel</a>
      <button class="btn btn-primary"><?= $member ? 'Save permissions' : 'Add member' ?></button>
    </div>
  </form>

  <?php if ($member): ?>
  <div class="card stack danger-zone">
    <h2 class="card-title">Account</h2>
    <div class="row-between">
      <div><strong>Reset password</strong><div class="muted small">Their current password stops working and they get an email to choose a new one.</div></div>
      <form method="post" action="<?= e(url($action . '/reset-password')) ?>" data-confirm="Reset <?= e($member['name']) ?>’s password? Their current password stops working and they get an email to choose a new one."><?= csrf_field() ?><button class="btn btn-sm">Reset password</button></form>
    </div>
    <div class="row-between">
      <div><strong><?= $member['active'] ? 'Deactivate' : 'Reactivate' ?></strong><div class="muted small"><?= $member['active'] ? 'They can’t sign in to this organization. Their tasks and comments stay.' : 'Let them sign in again.' ?></div></div>
      <form method="post" action="<?= e(url($action . '/active')) ?>" <?= $member['active'] ? 'data-confirm="Deactivate ' . e($member['name']) . '?"' : '' ?>><?= csrf_field() ?><button class="btn btn-sm <?= $member['active'] ? 'btn-danger' : '' ?>"><?= $member['active'] ? 'Deactivate' : 'Reactivate' ?></button></form>
    </div>
  </div>
  <?php endif; ?>
</div>
<script>
(() => {
  const presets = <?= json_encode($presets) ?>;
  const form = document.getElementById('member-form');
  const radios = form.querySelectorAll('input[name="preset"]');
  const note = document.getElementById('custom-note');
  const current = () => {
    const out = {};
    form.querySelectorAll('[data-perm]').forEach(i => {
      const k = i.dataset.perm;
      if (i.type === 'checkbox') out[k] = i.checked;
      else if (i.checked) out[k] = i.value;
    });
    return out;
  };
  const apply = key => {
    const p = presets[key];
    form.querySelectorAll('[data-perm]').forEach(i => {
      const v = p[i.dataset.perm];
      if (i.type === 'checkbox') i.checked = !!v; else i.checked = i.value === v;
    });
    check();
  };
  const check = () => {
    const sel = form.querySelector('input[name="preset"]:checked');
    const cur = current();
    note.hidden = !sel || Object.keys(cur).every(k => cur[k] === presets[sel.value][k]);
  };
  radios.forEach(r => r.addEventListener('change', () => apply(r.value)));
  form.querySelectorAll('[data-perm]').forEach(i => i.addEventListener('change', check));
  const list = document.getElementById('project-list');
  const syncProjects = () => {
    const all = form.querySelector('input[name="all_projects"]:checked');
    list.classList.toggle('is-disabled', !!all && all.value === '1');
  };
  form.querySelectorAll('input[name="all_projects"]').forEach(r => r.addEventListener('change', syncProjects));
  syncProjects(); check();
})();
</script>
