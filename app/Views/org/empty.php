<?php use App\Services\Access; ?>
<div class="page narrow">
  <div class="empty-state">
    <h1>No projects here yet</h1>
    <?php if (Access::can('manage_projects')): ?>
      <p>Create a project to get a board with the default statuses.</p>
      <button type="button" class="btn btn-primary" data-open="new-project">+ New project</button>
    <?php else: ?>
      <p>You haven’t been given access to any project. Ask your admin.</p>
    <?php endif; ?>
  </div>
</div>
