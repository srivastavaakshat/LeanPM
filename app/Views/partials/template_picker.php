<?php
/** Stage template choice. Pass $selected (a template key, or null for none picked); optional $name. */
use App\Services\Tasks;
?>
<div class="templates">
  <?php foreach (Tasks::TEMPLATES as $key => $t): ?>
    <label class="template">
      <input type="radio" name="<?= e($name ?? 'template') ?>" value="<?= e($key) ?>" <?= $selected === $key ? 'checked' : '' ?> required>
      <span class="template-box">
        <strong><?= e($t['label']) ?></strong>
        <span class="muted small"><?= e($t['hint']) ?></span>
        <span class="stage-preview">
          <?php foreach ($t['stages'] as [$stage, $color]): ?>
            <span class="stage-chip" style="--sc:<?= e($color) ?>"><?= e($stage) ?></span>
          <?php endforeach; ?>
        </span>
        <span class="muted small">Fields: <?= e(implode(', ', array_column(\App\Services\Fields::TEMPLATES[$key] ?? [], 0))) ?></span>
      </span>
    </label>
  <?php endforeach; ?>
</div>
