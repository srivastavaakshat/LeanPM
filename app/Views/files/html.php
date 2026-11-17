<?php
// Viewer for an uploaded HTML file: a LeanPM bar on top, the page below in a sandboxed frame.
// The frame has no allow-same-origin, and FileController sends a matching CSP sandbox, so the
// page runs with an origin of its own and can't touch the app or the signed-in session.
$raw = url('/files/' . (int) $file['id'], ['raw' => 1]);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($file['file_name']) ?> · LeanPM</title>
<link rel="icon" href="<?= e(asset('favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
</head>
<body class="html-viewer">
  <header class="hv-bar">
    <a class="hv-back" href="<?= e($taskUrl) ?>" title="Back to the task">←</a>
    <span class="hv-badge">HTML</span>
    <div class="hv-name">
      <strong title="<?= e($file['file_name']) ?>"><?= e($file['file_name']) ?></strong>
      <span>on <a href="<?= e($taskUrl) ?>"><?= e($task['title']) ?></a> · added by <?= e((string) $by) ?> · <?= e(human_size((int) $file['size'])) ?></span>
    </div>
    <span class="hv-note" title="Scripts in this page run in a sandbox: it can't see LeanPM, your account or your other files.">Sandboxed preview</span>
    <a class="btn btn-sm" href="<?= e($raw) ?>" target="hv-frame" title="Reload the page">Reload</a>
    <a class="btn btn-sm btn-primary" href="<?= e(url('/files/' . (int) $file['id'], ['download' => 1])) ?>">Download</a>
  </header>
  <iframe name="hv-frame" class="hv-frame" src="<?= e($raw) ?>" title="<?= e($file['file_name']) ?>"
    sandbox="allow-scripts allow-forms allow-popups allow-modals allow-downloads" referrerpolicy="no-referrer"></iframe>
</body>
</html>
