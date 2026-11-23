<div class="page notif-page">
  <header class="page-head row-between notif-head">
    <div>
      <h1>Notifications</h1>
      <p class="muted">When someone @mentions you, comments on a task you’re on, assigns you a task, or completes one of yours. Click one to open the task right here and reply.</p>
    </div>
    <button type="button" class="btn" id="n-all-read" title="Mark all as read (Shift+R)">Mark all as read</button>
  </header>
  <div class="view-toggle notif-tabs" role="tablist" aria-label="Show">
    <button type="button" role="tab" data-filter="all">All</button>
    <button type="button" role="tab" data-filter="unread">Unread <span class="count" data-count="unread"></span></button>
    <button type="button" role="tab" data-filter="mention">Mentions <span class="count" data-count="mention"></span></button>
    <button type="button" role="tab" data-filter="assigned">Assigned to me <span class="count" data-count="assigned"></span></button>
  </div>
  <div id="notif-list" class="notif-list"></div>
</div>
<script id="boot" type="application/json"><?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= e(asset('peek.js')) ?>"></script>
<script src="<?= e(asset('notifications.js')) ?>"></script>
