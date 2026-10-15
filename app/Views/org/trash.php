<?php use App\Services\Access; $org = Access::$org; ?>
<div class="page">
  <header class="page-head"><h1>Trash</h1><p class="muted">Deleted tasks (with their subtasks) stay here until you restore them.</p></header>
  <div class="card table-card">
    <?php if (!$rows): ?><p class="muted pad">Trash is empty.</p><?php else: ?>
    <table class="table">
      <thead><tr><th>Task</th><th>Project</th><th>Deleted</th><th>By</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e($r['title']) ?></td>
          <td><?= e($r['project']) ?></td>
          <td><?= e(fmt_date($r['deleted_at'], 'j M Y, g:i a')) ?></td>
          <td><?= e($r['deleted_by_name'] ?? '') ?></td>
          <td class="right">
            <form method="post" action="<?= e(url('/o/' . $org['slug'] . '/trash/' . $r['id'] . '/restore')) ?>"><?= csrf_field() ?><button class="btn btn-sm">Restore</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>
