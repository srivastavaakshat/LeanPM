<?php
// "Main task" choice for the New doc and Doc settings dialogs. Every task added in a doc with a
// main task becomes its subtask. Expects $linked (the connected task summary, or null).
use App\Services\Access;
$linked ??= null;
$canCreate = Access::can('create_tasks');
?>
<fieldset class="field main-task-field">
  <legend>Main task <span class="muted">(optional)</span></legend>
  <p class="hint" style="margin:0 0 8px">Pick or create a main task and every task you add in these notes becomes its subtask.</p>
  <?php if ($linked): ?>
    <label class="check"><input type="radio" name="main_task" value="keep" checked> Keep “<?= e($linked['title']) ?>” <span class="muted small">in <?= e($linked['project']) ?></span></label>
  <?php endif; ?>
  <label class="check"><input type="radio" name="main_task" value="none" <?= $linked ? '' : 'checked' ?>> <?= $linked ? 'No main task (disconnect it)' : 'No main task' ?></label>
  <label class="check"><input type="radio" name="main_task" value="existing"> Pick an existing task</label>
  <div class="mt-panel" data-for="existing" hidden>
    <input type="hidden" name="main_task_id" value="">
    <div class="mt-picked" hidden></div>
    <label class="search mt-search"><svg class="i" viewBox="0 0 20 20"><circle cx="9" cy="9" r="5"/><path d="M13 13l4 4"/></svg><input type="search" placeholder="Search tasks by name" autocomplete="off" aria-label="Search for the main task"></label>
    <div class="mt-results" role="listbox"></div>
  </div>
  <?php if ($canCreate): ?>
    <label class="check"><input type="radio" name="main_task" value="new"> Create a new main task</label>
    <div class="mt-panel" data-for="new" hidden>
      <input type="text" name="main_task_title" maxlength="255" placeholder="Name of the main task (the doc’s name if left empty)">
      <p class="hint" style="margin:4px 0 0">Created in the project picked above and assigned to you.</p>
    </div>
  <?php endif; ?>
</fieldset>
