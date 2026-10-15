<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e(($title ?? '') !== '' ? $title . ' · LeanPM' : 'LeanPM') ?></title>
<link rel="icon" href="<?= e(asset('favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
</head>
<body class="plain">
<main class="plain-card">
  <a class="brand" href="<?= e(url('/')) ?>"><span class="brand-mark">L</span> LeanPM</a>
  <?php foreach (\App\Core\Session::flashes() as $f): ?>
    <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
  <?php endforeach; ?>
  <?= $content ?>
</main>
</body>
</html>
