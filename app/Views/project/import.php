<?php
use App\Services\Access;
use App\Services\CsvImport;
$org = Access::$org;
$board = '/o/' . $org['slug'] . '/p/' . $project['id'];
?>
<div class="page">
  <header class="page-head">
    <a class="back" href="<?= e(url($board)) ?>">← Back to board</a>
    <h1>Import tasks into <?= e($project['name']) ?></h1>
  </header>

  <?php if ($result): ?>
  <div class="card stack import-result">
    <h2 class="card-title">Imported <?= (int) $result['created'] ?> task<?= $result['created'] === 1 ? '' : 's' ?></h2>
    <?php if ($result['warnings']): ?>
      <p class="hint">Done, with a few things to check:</p>
      <ul class="warn-list"><?php foreach ($result['warnings'] as $w): ?><li><?= e($w) ?></li><?php endforeach; ?></ul>
    <?php else: ?>
      <p class="hint">Everything was imported.</p>
    <?php endif; ?>
    <div class="actions"><a class="btn btn-primary" href="<?= e(url($board)) ?>">Open the board</a></div>
  </div>
  <?php endif; ?>

  <?php if ($step === 'upload'): ?>
  <form method="post" action="<?= e(url($base)) ?>" enctype="multipart/form-data" class="card stack narrow-card">
    <?= csrf_field() ?>
    <h2 class="card-title">1. Upload a CSV</h2>
    <p class="hint">The first row should be column names. Any spreadsheet works (Excel, Google Sheets, Numbers: <em>File → Download/Export → CSV</em>), as do LeanPM’s own exports and ClickUp or Trello CSV exports. Up to <?= CsvImport::MAX_ROWS ?> tasks per file.</p>
    <label class="dropfile">
      <input type="file" name="csv" accept=".csv,.tsv,.txt,text/csv" required>
      <span>Choose a CSV file</span>
    </label>
    <div class="row-between">
      <a href="<?= e(url($base . '/sample.csv')) ?>" class="small">Download a sample CSV for this project</a>
      <button class="btn btn-primary">Continue</button>
    </div>
  </form>

  <?php else: ?>
  <form method="post" action="<?= e(url($base . '/run')) ?>" class="stack" id="import-form">
    <?= csrf_field() ?>
    <div class="card stack">
      <div class="row-between">
        <h2 class="card-title">2. Match the columns</h2>
        <span class="muted small"><?= e($name) ?> · <?= count($rows) ?> row<?= count($rows) === 1 ? '' : 's' ?></span>
      </div>
      <p class="hint">Each column in your file goes to a field here. We guessed from the column names. Change any that are wrong, or choose “Don’t import”.</p>
      <div class="map-table">
        <div class="map-row map-head"><span>Column in your file</span><span>Example</span><span>Import as</span></div>
        <?php foreach ($headers as $i => $h):
          $example = '';
          foreach ($rows as $r) { if (($r[$i] ?? '') !== '') { $example = $r[$i]; break; } } ?>
          <div class="map-row">
            <strong><?= e($h !== '' ? $h : 'Column ' . ($i + 1)) ?></strong>
            <span class="muted small ex" title="<?= e($example) ?>"><?= e(mb_strimwidth($example, 0, 60, '…')) ?></span>
            <select name="map[<?= $i ?>]" class="map-select">
              <?php foreach (CsvImport::TARGETS as $k => $label): ?>
                <option value="<?= e($k) ?>" <?= $guess[$i] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
              <?php if ($fields): ?>
                <optgroup label="This project’s fields">
                  <?php foreach ($fields as $f): $k = 'field:' . $f['id']; ?>
                    <option value="<?= e($k) ?>" <?= $guess[$i] === $k ? 'selected' : '' ?>><?= e($f['name']) ?></option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endif; ?>
            </select>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="field-error" id="map-error" hidden></p>
    </div>

    <div class="card stack">
      <h2 class="card-title">3. Options</h2>
      <div class="grid-2">
        <label class="field"><span>Tasks with no stage, or a stage this project doesn’t have, go to</span>
          <select name="default_status"><?php foreach ($statuses as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select>
        </label>
        <label class="field"><span>Dates like 05/10/2026 mean</span>
          <select name="date_format">
            <option value="dmy">5 October (day first)</option>
            <option value="mdy">May 10 (month first, US)</option>
          </select>
        </label>
      </div>
      <p class="hint">Stages are matched by name (e.g. “For Review”). Assignees are matched by name or email; a first name works if it’s unique. A “Parent task” column makes the row a subtask of the task with that title. Tasks in a Done stage get their completed date from the “Completed date” column, or today.</p>
    </div>

    <div class="card table-card">
      <div class="pad row-between"><h2 class="card-title" style="margin:0">Preview</h2><span class="muted small">First <?= min(5, count($rows)) ?> of <?= count($rows) ?></span></div>
      <table class="table preview-table">
        <thead><tr><?php foreach ($headers as $h): ?><th><?= e($h) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach (array_slice($rows, 0, 5) as $r): ?>
          <tr><?php foreach ($r as $v): ?><td title="<?= e($v) ?>"><?= e(mb_strimwidth($v, 0, 40, '…')) ?></td><?php endforeach; ?></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="actions">
      <button type="submit" class="btn" formaction="<?= e(url($base . '/cancel')) ?>" formnovalidate>Use a different file</button>
      <button class="btn btn-primary" id="import-go">Import <?= count($rows) ?> task<?= count($rows) === 1 ? '' : 's' ?></button>
    </div>
  </form>
  <script>
  (() => {
    const form = document.getElementById('import-form');
    const err = document.getElementById('map-error');
    const go = document.getElementById('import-go');
    const check = () => {
      const picked = [...form.querySelectorAll('.map-select')].map(s => s.value).filter(Boolean);
      const dupes = picked.filter((v, i) => picked.indexOf(v) !== i);
      let msg = '';
      if (!picked.includes('title')) msg = 'Choose which column is the task Title.';
      else if (dupes.length) msg = 'Two columns are set to “' + form.querySelector(`.map-select option[value="${dupes[0]}"]`).textContent + '”. Each field can come from one column only.';
      err.textContent = msg;
      err.hidden = !msg;
      go.disabled = !!msg;
    };
    form.addEventListener('change', check);
    form.addEventListener('submit', e => { if (e.submitter === go) { go.disabled = true; go.textContent = 'Importing…'; } });
    check();
  })();
  </script>
  <?php endif; ?>
</div>
