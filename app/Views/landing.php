<?php
/** Public landing page (layout-free). $repo is the GitHub repository URL. */
$login = url('/login');
$av = fn (string $i, string $c) => '<span class="m-av" style="--c:' . $c . '">' . $i . '</span>';
?>
<!doctype html>
<html lang="en">
<head>
<script>document.documentElement.classList.add('js');</script>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>LeanPM · A lean, self-hosted project manager</title>
<meta name="description" content="LeanPM is a small, self-hosted project manager built by Akshat Srivastava for his design team: a Kanban board and list, meeting notes that turn into tasks, and CSV reports. Open source, plain PHP and MariaDB.">
<meta property="og:title" content="LeanPM · A lean, self-hosted project manager">
<meta property="og:description" content="A Kanban board and list, meeting notes that turn into tasks, and monthly CSV reports. Built for a design team, open source.">
<meta name="theme-color" content="#14213D">
<link rel="icon" href="<?= e(asset('favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('landing.css')) ?>">
</head>
<body>

<header class="nav">
  <div class="wrap nav-in">
    <a href="<?= e(url('/')) ?>" class="nav-logo"><img src="<?= e(asset('logo.svg')) ?>" alt="LeanPM" width="130" height="30"></a>
    <nav class="nav-links" aria-label="Sections">
      <a href="#board">Board &amp; list</a>
      <a href="#notes">Meeting notes</a>
      <a href="#keyboard">Keyboard</a>
      <a href="#reports">Reports</a>
      <a href="#build">Build your own</a>
    </nav>
    <div class="nav-cta">
      <a class="btn btn-ghost" href="<?= e($repo) ?>" rel="noopener">GitHub</a>
      <a class="btn btn-dark" href="<?= e($login) ?>">Sign in</a>
    </div>
  </div>
</header>

<main>
  <section class="hero">
    <div class="wrap hero-in">
      <p class="eyebrow"><span class="dot"></span> A personal project by Akshat Srivastava</p>
      <h1>Project management,<br><span class="hl">without the weight.</span></h1>
      <p class="lede">LeanPM is the tool I built for my own design team: a Kanban board and a list, meeting notes that turn into tasks, a CSV export for the end-of-month report, and you can run all of it from the keyboard. Small, self-hosted and open source.</p>
      <div class="hero-cta">
        <a class="btn btn-dark btn-lg" href="<?= e($login) ?>">Sign in to LeanPM</a>
        <a class="btn btn-line btn-lg" href="<?= e($repo) ?>" rel="noopener">Build your own on GitHub <span aria-hidden="true">→</span></a>
      </div>
      <p class="hero-note">Plain PHP and MariaDB · runs on ordinary shared hosting · your data stays on your server</p>
    </div>

    <div class="wrap">
      <div class="window hero-shot" aria-label="The LeanPM board">
        <div class="win-bar"><i></i><i></i><i></i><span>Brand refresh · Board</span></div>
        <div class="m-board">
          <?php
          $cols = [
            ['To do', '#94a3b8', [['Moodboard for the spring campaign', 'NK', '#e0663a', '18 Nov', 'High', '2/4'], ['Icon set: sports categories', 'RV', '#2f9e78', '21 Nov', null, null]]],
            ['In progress', '#5b8def', [['Homepage hero, three directions', 'AS', '#14213D', 'Today', 'Urgent', '1/3']]],
            // Being dragged into review right now: lifted, tilted, and the column lights up.
            ['In review', '#e8a33d', [['App store screenshots', 'PM', '#c2410c', '20 Nov', null, null, true], ['Newsletter template v2', 'RV', '#2f9e78', '15 Nov', 'High', null]]],
            ['Done', '#2f9e78', [['Brand colours, final', 'AS', '#14213D', null, null, '4/4'], ['Social banner sizes', 'NK', '#e0663a', null, null, null]]],
          ];
          foreach ($cols as [$name, $color, $cards]): ?>
          <div class="m-col<?= array_filter($cards, fn ($c) => !empty($c[6])) ? ' is-target' : '' ?>">
            <div class="m-col-head"><span class="m-stage" style="--c:<?= $color ?>"></span><?= $name ?><span class="m-count"><?= count($cards) ?></span></div>
            <?php foreach ($cards as $card): [$t, $i, $c, $due, $prio, $sub] = $card; ?>
            <div class="m-card<?= $name === 'Done' ? ' is-done' : '' ?><?= !empty($card[6]) ? ' is-lifted' : '' ?>">
              <p><?= $t ?></p>
              <div class="m-meta">
                <?= $av($i, $c) ?>
                <?php if ($due): ?><span class="m-chip<?= $due === 'Today' ? ' is-due' : '' ?>"><?= $due ?></span><?php endif; ?>
                <?php if ($prio): ?><span class="m-flag m-flag-<?= strtolower($prio) ?>"><?= $prio ?></span><?php endif; ?>
                <?php if ($sub): ?><span class="m-sub">☑ <?= $sub ?></span><?php endif; ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </section>

  <section class="why" id="why">
    <div class="wrap reveal why-in">
      <div class="why-me">
        <span class="me-mark" aria-hidden="true">AS</span>
        <div>
          <strong>Akshat Srivastava</strong>
          <span>Designer · leads a design team</span>
        </div>
      </div>
      <div class="why-text">
        <h2>Why I built it</h2>
        <p>I’m a designer, and I run a design team. We planned our work in one of the big project management tools, and most of it got in the way: dozens of features we never touched, a price for every seat, and our files and history sitting in someone else’s system.</p>
        <p>So I wrote down the handful of things we actually use, and built only those. LeanPM runs on my own hosting. I use it to run the team’s projects and my own work, and to send a clean report at the end of every month.</p>
        <ul class="principles">
          <li><strong>Only what we use.</strong> A board, a list, tasks, comments, files. Nothing to configure before you can start.</li>
          <li><strong>Your data, your server.</strong> Tasks, comments and files live in your database and on your disk.</li>
          <li><strong>Easy to get data out.</strong> Everything exports to CSV, so a report is one click away.</li>
        </ul>
      </div>
    </div>
  </section>

  <section class="feature" id="board">
    <div class="wrap feature-in reveal">
      <div class="feature-text">
        <p class="kicker">Board &amp; list</p>
        <h2>A board for the day, a list for the details.</h2>
        <p>Drag cards across stages that each project defines for itself. Switch to the list when you want to scan everything and edit it in place.</p>
        <ul class="ticks">
          <li>Stage templates for design work, reviews and general projects, and you can edit them any time</li>
          <li>Assignees, priority, start and due dates, and subtasks (or link an existing task as one)</li>
          <li>Custom fields per project, like the Figma file for every design task</li>
          <li>One “All tasks” view across projects, with an Inbox for things not sorted yet</li>
        </ul>
      </div>
      <div class="window" aria-label="The list view">
        <div class="win-bar"><i></i><i></i><i></i><span>Brand refresh · List</span></div>
        <table class="m-list">
          <thead><tr><th>Task</th><th>Assignee</th><th>Priority</th><th>Due</th><th>Stage</th></tr></thead>
          <tbody>
            <tr><td>Homepage hero, three directions</td><td><?= $av('AS', '#14213D') ?></td><td><span class="m-flag m-flag-urgent">Urgent</span></td><td class="is-due">Today</td><td><span class="m-pill" style="--c:#5b8def">In progress</span></td></tr>
            <tr><td>Newsletter template v2</td><td><?= $av('RV', '#2f9e78') ?></td><td><span class="m-flag m-flag-high">High</span></td><td>15 Nov</td><td><span class="m-pill" style="--c:#e8a33d">In review</span></td></tr>
            <tr><td>Moodboard for the spring campaign</td><td><?= $av('NK', '#e0663a') ?></td><td><span class="m-flag m-flag-high">High</span></td><td>18 Nov</td><td><span class="m-pill" style="--c:#94a3b8">To do</span></td></tr>
            <tr><td>App store screenshots</td><td><?= $av('PM', '#c2410c') ?></td><td><span class="muted">—</span></td><td>20 Nov</td><td><span class="m-pill" style="--c:#5b8def">In progress</span></td></tr>
            <tr><td>Icon set: sports categories</td><td><?= $av('RV', '#2f9e78') ?></td><td><span class="muted">—</span></td><td>21 Nov</td><td><span class="m-pill" style="--c:#94a3b8">To do</span></td></tr>
            <tr class="is-done"><td>Brand colours, final</td><td><?= $av('AS', '#14213D') ?></td><td><span class="muted">—</span></td><td>8 Nov</td><td><span class="m-pill" style="--c:#2f9e78">Done</span></td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </section>

  <section class="feature feature-alt" id="notes">
    <div class="wrap feature-in reveal">
      <div class="feature-text">
        <p class="kicker">Meeting notes</p>
        <h2>Meeting notes that turn into tasks.</h2>
        <p>Keep one running doc per meeting: the stand-up, a weekly client call, the sales sync. Each time you open it, a fresh dated entry is waiting.</p>
        <ul class="ticks">
          <li>Type <kbd>/</kbd> at the end of a line to turn it into a task, sent to the doc’s project or the Inbox</li>
          <li>Task chips show their live stage, so next week you see what was decided and how it’s going</li>
          <li>Click a task to edit it right there: people, subtasks, priority, files and updates</li>
        </ul>
      </div>
      <div class="window" aria-label="A meeting notes doc">
        <div class="win-bar"><i></i><i></i><i></i><span>Meeting notes · Design stand-up</span></div>
        <div class="m-notes">
          <div class="m-entry">
            <p class="m-date">Monday, 10 November</p>
            <ul>
              <li>Hero directions look good; go with option B for the review</li>
              <li><span class="m-task"><span class="m-check"></span>Share hero option B with the client <span class="m-pill" style="--c:#5b8def">In progress</span><?= $av('AS', '#14213D') ?></span></li>
              <li>Newsletter: one more pass on the footer</li>
              <li class="m-typing">Get final copy for the app store screenshots /<span class="caret"></span>
                <div class="m-slash">
                  <span class="m-slash-label">Create</span>
                  <div class="m-slash-item"><span class="m-slash-icon">✓</span><div><strong>Task</strong><span>Turn this line into a task</span></div></div>
                  <span class="m-slash-foot">Goes to Brand refresh · Esc to keep typing</span>
                </div>
              </li>
            </ul>
          </div>
          <div class="m-entry m-entry-past">
            <p class="m-date">Monday, 3 November</p>
            <ul>
              <li><span class="m-task is-done"><span class="m-check is-on">✓</span>Lock the brand colours <span class="m-pill" style="--c:#2f9e78">Done</span></span></li>
              <li><span class="m-task"><span class="m-check"></span>Newsletter template v2 <span class="m-pill" style="--c:#e8a33d">In review</span></span></li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section class="feature" id="keyboard">
    <div class="wrap feature-in reveal">
      <div class="feature-text">
        <p class="kicker">Keyboard first</p>
        <h2>Your hands never leave the keyboard.</h2>
        <p>Press <kbd>⌘</kbd><kbd>K</kbd> anywhere to find any task or jump to any page. Open a task and the cursor is already in the comment box, so an update takes seconds.</p>
        <ul class="ticks">
          <li>Search every project you can see, then <kbd>↑</kbd><kbd>↓</kbd> and <kbd>↵</kbd> to open</li>
          <li>Arrow keys move around the board, <kbd>⇧</kbd><kbd>→</kbd> moves a card to the next stage</li>
          <li>Type a comment and send it with <kbd>⌘</kbd><kbd>↵</kbd>; <kbd>?</kbd> shows every shortcut</li>
        </ul>
      </div>
      <div class="window m-palette-wrap" aria-label="The command palette">
        <div class="win-bar"><i></i><i></i><i></i><span>Search · ⌘K</span></div>
        <div class="m-palette">
          <div class="m-pal-search"><span class="m-pal-icon" aria-hidden="true">⌕</span>hero<span class="caret"></span><kbd>esc</kbd></div>
          <p class="m-pal-label">Tasks</p>
          <div class="m-pal-item is-on"><span class="m-pal-dot" style="--c:#0ea5e9"></span><span class="m-pal-main"><strong>Homepage hero: three directions</strong><span>Website Revamp · In Progress</span></span><kbd>↵</kbd></div>
          <div class="m-pal-item"><span class="m-pal-dot" style="--c:#8b8f98"></span><span class="m-pal-main"><strong>Send the three hero options to the client</strong><span>Inbox · To do</span></span><span class="m-pal-id">#36</span></div>
          <div class="m-pal-item"><span class="m-pal-dot" style="--c:#22c55e"></span><span class="m-pal-main"><strong>Direction A: bold type</strong><span>Website Revamp · Approved · subtask</span></span><span class="m-pal-id">#13</span></div>
          <p class="m-pal-label">Go to</p>
          <div class="m-pal-item"><span class="m-pal-go">→</span><span class="m-pal-main"><strong>Meeting notes</strong></span><span class="m-pal-id">G N</span></div>
          <div class="m-pal-foot"><span><kbd>↑</kbd><kbd>↓</kbd> move</span><span><kbd>↵</kbd> open</span><span><kbd>esc</kbd> close</span><span class="m-pal-right"><kbd>?</kbd> all shortcuts</span></div>
        </div>
      </div>
    </div>
  </section>

  <section class="feature feature-alt" id="reports">
    <div class="wrap feature-in reveal">
      <div class="feature-text">
        <p class="kicker">Reports</p>
        <h2>The monthly report, in one click.</h2>
        <p>At the end of the month, pick the people and the dates and download a CSV. Open it in Google Sheets, Excel or Numbers and share it with the team.</p>
        <ul class="ticks">
          <li>Filter by member, project and date range</li>
          <li>Completed dates, days to complete and overdue flags, plus every custom field</li>
          <li>Comments export too, and you can import tasks from a CSV when you move in</li>
        </ul>
      </div>
      <div class="window" aria-label="Exporting a report">
        <div class="win-bar"><i></i><i></i><i></i><span>Export reports</span></div>
        <div class="m-export">
          <div class="m-form">
            <label>Members<span class="m-input">Everyone</span></label>
            <label>From<span class="m-input">1 Oct 2026</span></label>
            <label>To<span class="m-input">31 Oct 2026</span></label>
            <span class="m-btn">Download tasks.csv</span>
          </div>
          <div class="m-csv">
            <div class="m-csv-name">tasks-october.csv</div>
            <table>
              <thead><tr><th>Title</th><th>Assignees</th><th>Completed</th><th>Days</th><th>Overdue</th></tr></thead>
              <tbody>
                <tr><td>Brand colours, final</td><td>Akshat</td><td>2026-10-28</td><td>6</td><td>N</td></tr>
                <tr><td>Social banner sizes</td><td>Neha</td><td>2026-10-24</td><td>3</td><td>N</td></tr>
                <tr><td>Pitch deck refresh</td><td>Ravi, Akshat</td><td>2026-10-17</td><td>9</td><td>Y</td></tr>
                <tr><td>Event poster</td><td>Priya</td><td>2026-10-09</td><td>2</td><td>N</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section class="extras">
    <div class="wrap">
      <h2 class="center">Also in the box</h2>
      <div class="grid reveal">
        <div class="tile"><span class="ic"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="8" height="7" rx="2"/><rect x="13" y="4" width="8" height="7" rx="2"/><rect x="3" y="13" width="8" height="7" rx="2"/><rect x="13" y="13" width="8" height="7" rx="2"/></svg></span><h3>Several organizations</h3><p>One for each team or client, each with its own members, projects and data.</p></div>
        <div class="tile"><span class="ic"><svg viewBox="0 0 24 24"><path d="M12 3l7 3v5c0 4.5-3 8-7 10-4-2-7-5.5-7-10V6z"/><path d="M9 12l2 2 4-4"/></svg></span><h3>Permissions per person</h3><p>Start from Admin, Lead, Designer or Viewer, then switch single permissions on or off.</p></div>
        <div class="tile"><span class="ic"><svg viewBox="0 0 24 24"><path d="M4 5h16v11H8l-4 4z"/><path d="M8 9h8M8 12h5"/></svg></span><h3>Comments and files</h3><p>Updates on every task, with files stored on your own server.</p></div>
        <div class="tile"><span class="ic"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M16 12v1.5a2.5 2.5 0 005 0V12a9 9 0 10-3.5 7.1"/></svg></span><h3>@mentions</h3><p>Tag someone in a comment or description and they get an email.</p></div>
        <div class="tile"><span class="ic"><svg viewBox="0 0 24 24"><path d="M4 6h16v12H4z"/><path d="M4 7l8 6 8-6"/></svg></span><h3>Email invites</h3><p>New people get a link to set their own password. Resets work the same way.</p></div>
        <div class="tile"><span class="ic"><svg viewBox="0 0 24 24"><path d="M20 12a8 8 0 11-2.3-5.6"/><path d="M20 4v4h-4"/></svg></span><h3>Trash and restore</h3><p>Deleted tasks wait in the Trash until someone brings them back.</p></div>
      </div>
    </div>
  </section>

  <section class="build" id="build">
    <div class="wrap reveal build-in">
      <div class="build-text">
        <img class="build-icon" src="<?= e(asset('icon.svg')) ?>" alt="" width="48" height="48">
        <h2>Build your own LeanPM.</h2>
        <p>The whole project is on GitHub. It’s plain PHP and MariaDB, with no framework and no build step, so it runs on an ordinary shared hosting plan. Fork it, change it, make it yours.</p>
        <div class="build-cta">
          <a class="btn btn-lime btn-lg" href="<?= e($repo) ?>" rel="noopener">View on GitHub</a>
          <a class="btn btn-onDark btn-lg" href="<?= e($repo . '/blob/main/DEPLOY.md') ?>" rel="noopener">Hosting guide</a>
        </div>
        <ul class="stack-tags"><li>PHP 8.3</li><li>MariaDB / MySQL</li><li>SQLite locally</li><li>Vanilla JS</li><li>No dependencies</li></ul>
      </div>
      <div class="terminal" aria-label="Commands to run LeanPM on your computer">
        <div class="term-bar"><i></i><i></i><i></i><span>Run it on your computer</span></div>
<pre><code><span class="c"># get the code</span>
git clone <?= e($repo) ?>.git
cd LeanPM

<span class="c"># create the database, your owner account and demo data</span>
php bin/install.php --fresh --seed --owner-email=you@example.com

<span class="c"># start it, then open http://localhost:8090</span>
php -S localhost:8090 -t public public/router.php</code></pre>
      </div>
    </div>
  </section>
</main>

<footer class="foot">
  <div class="wrap foot-in">
    <span class="foot-brand"><img src="<?= e(asset('icon.svg')) ?>" alt="" width="22" height="22"> LeanPM, built by Akshat Srivastava</span>
    <nav aria-label="Footer"><a href="<?= e($login) ?>">Sign in</a><a href="<?= e($repo) ?>" rel="noopener">GitHub</a></nav>
  </div>
</footer>

<script>
// Sections fade up as they scroll into view.
(() => {
  const els = document.querySelectorAll('.reveal');
  if (!('IntersectionObserver' in window)) { els.forEach(e => e.classList.add('is-in')); return; }
  const io = new IntersectionObserver(entries => entries.forEach(en => { if (en.isIntersecting) { en.target.classList.add('is-in'); io.unobserve(en.target); } }), { threshold: .12 });
  els.forEach(e => io.observe(e));
})();
</script>
</body>
</html>
