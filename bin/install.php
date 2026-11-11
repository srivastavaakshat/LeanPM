<?php
/**
 * LeanPM installer. Run once over SSH (Hostinger) or locally.
 *
 *   php bin/install.php --owner-email=you@example.com --owner-name="Akshat Srivastava"
 *   php bin/install.php --fresh --seed --owner-email=you@example.com   (local only: wipes everything first)
 *
 *  --seed   adds demo organizations ("Juniper Studio", "Fieldnotes Magazine") with sample members, tasks and notes
 *  --fresh  drops every table first. Refused when env = production.
 * The Owner's password is printed once (and saved to storage/local-admin.txt when env = local).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database as DB;
use App\Services\Perms;
use App\Services\Tasks;

require __DIR__ . '/sql_tools.php';

$opts = getopt('', ['fresh', 'seed', 'owner-email:', 'owner-name:']);
$driver = DB::driver();
$out = fn (string $s) => fwrite(STDOUT, $s . "\n");

if (isset($opts['fresh'])) {
    if (config('env') === 'production') {
        exit("Refusing --fresh in production. It would delete all data.\n");
    }
    $tables = ['password_tokens', 'notes_entries', 'notes_docs', 'task_field_values', 'project_fields', 'email_queue', 'activity', 'attachments', 'comments', 'task_assignees', 'tasks', 'statuses', 'membership_projects', 'projects', 'memberships', 'organizations', 'users', 'login_attempts', 'schema_migrations'];
    DB::pdo()->exec($driver === 'mysql' ? 'SET FOREIGN_KEY_CHECKS = 0' : 'PRAGMA foreign_keys = OFF');
    foreach ($tables as $t) {
        DB::pdo()->exec("DROP TABLE IF EXISTS $t");
    }
    DB::pdo()->exec($driver === 'mysql' ? 'SET FOREIGN_KEY_CHECKS = 1' : 'PRAGMA foreign_keys = ON');
    $out('Dropped existing tables.');
}

// 1. Tables
$sql = file_get_contents(BASE_PATH . '/database/schema.sql');
foreach (split_sql($driver === 'sqlite' ? mysql_to_sqlite($sql) : $sql) as $stmt) {
    DB::pdo()->exec($stmt);
}
$out('Tables ready (' . $driver . ').');

// Existing migration files are already part of schema.sql.
foreach (glob(BASE_PATH . '/database/migrations/*.sql') ?: [] as $f) {
    $name = basename($f);
    if (!DB::one('SELECT filename FROM schema_migrations WHERE filename = ?', [$name])) {
        DB::insert('schema_migrations', ['filename' => $name, 'applied_at' => now()]);
    }
}

// 2. Owner
$email = mb_strtolower(trim((string) ($opts['owner-email'] ?? '')));
$owner = DB::one('SELECT * FROM users WHERE is_owner = 1');
if (!$owner) {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        exit("Add --owner-email=you@example.com to create the Owner account.\n");
    }
    $password = random_password(16);
    $ownerId = DB::insert('users', [
        'name' => trim((string) ($opts['owner-name'] ?? '')) ?: 'Owner', 'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'color' => '#14213D',
        'is_owner' => 1, 'must_change_password' => 0, 'created_at' => now(),
    ]);
    $owner = DB::one('SELECT * FROM users WHERE id = ?', [$ownerId]);
    $out('');
    $out("Owner account: {$email}");
    $out("Password:      {$password}");
    $out('Save it in your password manager now. It is not shown again (change it in the app under Your account).');
    $out('');
    if (config('env') === 'local') {
        file_put_contents(BASE_PATH . '/storage/local-admin.txt', "{$email}\n{$password}\n");
    }
} else {
    $out("Owner already exists ({$owner['email']}).");
}

// 3. Demo data: a fictional design studio with realistic projects, people, tasks and meeting notes.
if (isset($opts['seed'])) {
    if (DB::value("SELECT COUNT(*) FROM organizations WHERE slug = 'juniper-studio'")) {
        $out('Demo organization already exists; skipped.');
        exit(0);
    }
    DB::transaction(function () use ($owner, $out) {
        $now = time();
        // A time $daysAgo days back, at $h:$m.
        $at = fn (float $daysAgo, int $h = 11, int $m = 0) => date('Y-m-d', (int) ($now - $daysAgo * 86400)) . sprintf(' %02d:%02d:00', $h, $m);
        $day = fn (int $offset) => date('Y-m-d', $now + $offset * 86400);
        $AS = (int) $owner['id'];

        $org = function (string $name, string $slug, int $age) use ($owner, $at): int {
            $id = DB::insert('organizations', ['name' => $name, 'slug' => $slug, 'created_by' => $owner['id'], 'created_at' => $at($age)]);
            DB::insert('memberships', ['org_id' => $id, 'user_id' => $owner['id'], 'preset' => 'admin', 'permissions' => json_encode(Perms::all()), 'all_projects' => 1, 'active' => 1, 'joined_at' => $at($age)]);
            return $id;
        };
        $orgId = $org('Juniper Studio', 'juniper-studio', 60);

        // People (they can't sign in until someone resets their password; locally Ravi gets a known one).
        $people = [];
        foreach ([
            ['Ayushi Mehra', 'ayushi@example.com', '#db2777', 'lead'],
            ['Ravi Dutta', 'ravi@example.com', '#65a30d', 'designer'],
            ['Priya Nair', 'priya@example.com', '#7c3aed', 'designer'],
            ['Kabir Sethi', 'kabir@example.com', '#0891b2', 'designer'],
            ['Neel Kapoor', 'neel@example.com', '#ea580c', 'viewer'],
        ] as [$n, $e, $c, $preset]) {
            $uid = DB::insert('users', ['name' => $n, 'email' => $e, 'password_hash' => password_hash(random_password(20), PASSWORD_DEFAULT), 'color' => $c, 'is_owner' => 0, 'must_change_password' => 1, 'created_at' => $at(59)]);
            DB::insert('memberships', ['org_id' => $orgId, 'user_id' => $uid, 'preset' => $preset, 'permissions' => json_encode(Perms::normalize(Perms::PRESETS[$preset]['perms'])), 'all_projects' => 1, 'active' => 1, 'joined_at' => $at(59)]);
            $people[strtok($n, ' ')] = $uid;
        }
        ['Ayushi' => $AY, 'Ravi' => $RD, 'Priya' => $PN, 'Kabir' => $KS] = $people;
        DB::query('UPDATE users SET last_login_at = ? WHERE id IN (' . DB::placeholders([$AY, $RD, $PN, $KS]) . ')', [$at(0, 9, 40), $AY, $RD, $PN, $KS]);

        $project = function (int $orgId, string $name, string $template, int $age) use ($AS, $at): array {
            $pid = DB::insert('projects', ['org_id' => $orgId, 'name' => $name, 'created_by' => $AS, 'created_at' => $at($age)]);
            Tasks::createDefaultStatuses($pid, $template);
            \App\Services\Fields::addTemplate($pid, $template);
            return [$pid, array_column(Tasks::statuses($pid), 'id', 'name'), array_column(\App\Services\Fields::forProject($pid), 'id', 'name')];
        };
        $ids = [];
        // [title, stage, assignees, priority, due (days from today), created (days ago), completed (days ago), link]
        $add = function (array $p, array $rows, ?int $parent = null) use (&$ids, $AS, $at, $day) {
            [$pid, $st, $fields] = $p;
            foreach ($rows as $i => $r) {
                [$title, $stage, $who, $prio, $due, $created, $completed] = $r;
                $id = DB::insert('tasks', [
                    'project_id' => $pid, 'parent_id' => $parent, 'status_id' => $st[$stage], 'title' => $title, 'priority' => $prio, 'position' => $i,
                    'start_date' => $due === null ? null : $day(min($due - 4, -1)), 'due_date' => $due === null ? null : $day($due),
                    'created_by' => $AS, 'created_at' => $at($created, 10 + $i % 6, 5 * $i % 60),
                    'updated_at' => $at($completed ?? max(0, $created - 2), 15), 'completed_at' => $completed === null ? null : $at($completed, 17),
                ]);
                foreach ($who as $u) {
                    DB::insert('task_assignees', ['task_id' => $id, 'user_id' => $u]);
                }
                if (!empty($r[7]) && $fields) {
                    DB::insert('task_field_values', ['task_id' => $id, 'field_id' => reset($fields), 'value' => $r[7]]);
                }
                DB::insert('activity', ['task_id' => $id, 'user_id' => $AS, 'action' => 'created', 'created_at' => $at($created, 10)]);
                if ($completed !== null) {
                    DB::insert('activity', ['task_id' => $id, 'user_id' => $who[0] ?? $AS, 'action' => 'status', 'from_value' => 'In Progress', 'to_value' => $stage, 'created_at' => $at($completed, 17)]);
                }
                $ids[$title] = $id;
            }
        };
        $figma = fn (string $file, string $node) => "https://www.figma.com/design/{$file}?node-id={$node}";

        $web = $project($orgId, 'Website Revamp', 'design', 45);
        $add($web, [
            ['Homepage hero: three directions', 'In Progress', [$AS], 'urgent', 0, 4, null, $figma('kQ2rT8Website-Revamp', '1204-388')],
            ['Pricing page redesign', 'In Progress', [$PN], 'high', 3, 6, null, $figma('kQ2rT8Website-Revamp', '1310-52')],
            ['Navigation and mega menu', 'For Review', [$RD], 'high', 1, 9, null, $figma('kQ2rT8Website-Revamp', '988-1420')],
            ['Case study page template', 'For Review', [$KS], null, 4, 7, null, $figma('kQ2rT8Website-Revamp', '1402-77')],
            ['Footer and newsletter signup', 'Revisions', [$RD], 'normal', -1, 11, null, $figma('kQ2rT8Website-Revamp', '1455-9')],
            ['Blog index and article layout', 'Requested', [], 'normal', 9, 2, null],
            ['Contact form: error and success states', 'Requested', [$PN], 'low', 12, 1, null],
            ['404 and empty states', 'Requested', [], null, null, 1, null],
            ['Design system: buttons and inputs', 'Approved', [$AY, $AS], 'high', null, 32, 13, $figma('Lm7Juniper-DS', '12-3')],
            ['Moodboard and art direction', 'Approved', [$AS], null, null, 42, 29],
            ['Responsive grid and breakpoints', 'Approved', [$KS], null, null, 27, 16, $figma('Lm7Juniper-DS', '88-140')],
            ['Icon set: 48 product icons', 'Approved', [$RD], 'normal', null, 22, 8, $figma('Lm7Juniper-DS', '301-12')],
        ]);
        $hero = $ids['Homepage hero: three directions'];
        DB::update('tasks', ['description' => '<h3>Brief</h3><p>Three directions for the new homepage hero. The client wants it to feel premium but still friendly, and the product has to be visible above the fold.</p><ul><li>A: bold type, no imagery</li><li>B: product-led, cropped hardware shot</li><li>C: editorial photo with a short story</li></ul><p>Present all three on the Thursday client call.</p>'], 'id = ?', [$hero]);
        $add($web, [
            ['Direction A: bold type', 'Approved', [$AS], null, null, 4, 2],
            ['Direction B: product-led', 'In Progress', [$AS], null, 0, 4, null],
            ['Direction C: editorial photo', 'Requested', [$PN], null, 1, 4, null],
        ], $hero);

        $app = $project($orgId, 'Mobile App 2.0', 'project', 38);
        $add($app, [
            ['Onboarding flow: four screens', 'In Progress', [$RD, $AY], 'high', 5, 6, null],
            ['Settings and profile screens', 'In Progress', [$PN], null, 6, 4, null],
            ['Push notification permission prompt', 'To Do', [$PN], 'normal', 8, 3, null],
            ['App Store screenshots', 'To Do', [$AS], 'normal', 14, 2, null],
            ['Dark mode token audit', 'Blocked', [$KS], 'high', 2, 9, null],
            ['Empty state illustrations', 'Backlog', [], 'low', null, 2, null],
            ['Widget for the home screen', 'Backlog', [], null, null, 1, null],
            ['Sign in with Google and Apple', 'Done', [$RD], 'high', null, 21, 7],
            ['Tab bar icons', 'Done', [$KS], null, null, 18, 10],
        ]);

        $brand = $project($orgId, 'Brand Refresh', 'design', 50);
        $add($brand, [
            ['Social media templates', 'In Progress', [$PN], 'high', 2, 7, null, $figma('Pz4Brand-Refresh', '640-21')],
            ['Brand guidelines PDF', 'For Review', [$AY], 'normal', 6, 12, null],
            ['Pitch deck template', 'Requested', [$KS], 'normal', 10, 3, null],
            ['Event booth banner', 'Revisions', [$RD], 'urgent', -2, 9, null, $figma('Pz4Brand-Refresh', '702-8')],
            ['Logo lockups and clear space', 'Approved', [$AS], null, null, 36, 21, $figma('Pz4Brand-Refresh', '1-2')],
            ['Colour palette: navy and lime', 'Approved', [$AY], null, null, 34, 23, $figma('Pz4Brand-Refresh', '4-90')],
        ]);

        // The Inbox: tasks made in meeting notes, not sorted into a project yet.
        $inboxId = DB::insert('projects', ['org_id' => $orgId, 'name' => 'Inbox', 'is_inbox' => 1, 'created_by' => $AS, 'created_at' => $at(60)]);
        foreach ([['To do', '#8b8f98', 'open'], ['Doing', '#0ea5e9', 'active'], ['Done', '#22c55e', 'done']] as $i => [$n, $c, $t]) {
            DB::insert('statuses', ['project_id' => $inboxId, 'name' => $n, 'color' => $c, 'type' => $t, 'position' => $i]);
        }
        $add([$inboxId, array_column(Tasks::statuses($inboxId), 'id', 'name'), []], [
            ['Send the three hero options to the client', 'To do', [$AS], 'high', 1, 0, null],
            ['Book five user tests for onboarding', 'To do', [$AY], null, 4, 0, null],
        ]);

        // Comments
        $comment = function (string $task, int $who, string $body, float $ago, int $h) use ($ids, $at) {
            DB::insert('comments', ['task_id' => $ids[$task], 'user_id' => $who, 'body' => $body, 'created_at' => $at($ago, $h)]);
        };
        $comment('Homepage hero: three directions', $AY, 'Direction B is closest to the brief. Can we try it with the product shot cropped a little tighter?', 1, 16);
        $comment('Homepage hero: three directions', $AS, 'On it. I’ll share the update before the client call.', 1, 17);
        $comment('Navigation and mega menu', $RD, 'Mega menu is in Figma with hover and keyboard states. Ready for review.', 1, 12);
        $comment('Dark mode token audit', $KS, 'Blocked until the new colour tokens land from Brand Refresh.', 3, 14);
        $comment('Footer and newsletter signup', $AY, 'Signup field needs an error state, and the legal links are too small on mobile.', 2, 15);
        $comment('Social media templates', $PN, 'Instagram and LinkedIn sizes done. Doing stories next.', 0, 10);

        // Meeting notes
        $chip = fn (string $t) => '<span class="task-ref" data-task="' . $ids[$t] . '">' . e($t) . '</span>';
        $doc = function (string $title, string $visibility, ?int $projectId, array $entries) use ($orgId, $AS, $at) {
            $d = DB::insert('notes_docs', ['org_id' => $orgId, 'owner_id' => $AS, 'title' => $title, 'visibility' => $visibility, 'default_project_id' => $projectId, 'created_at' => $at(30), 'updated_at' => $at(0)]);
            foreach ($entries as [$ago, $h, $etitle, $body]) {
                DB::insert('notes_entries', ['doc_id' => $d, 'author_id' => $AS, 'meeting_at' => $at($ago, $h), 'title' => $etitle, 'body' => $body, 'created_at' => $at($ago, $h), 'updated_at' => $at($ago, $h + 1)]);
            }
        };
        $doc('Design stand-up', 'org', $web[0], [
            [0, 10, 'Stand-up', '<p>Hero: direction B is the favourite. Tighten the product crop before Thursday.</p><p>' . $chip('Send the three hero options to the client') . '</p><p>Ravi’s mega menu is ready for review.</p><p>' . $chip('Navigation and mega menu') . '</p><p>Kabir is blocked on dark mode until the brand colours are final.</p><p>' . $chip('Dark mode token audit') . '</p>'],
            [7, 10, 'Weekly kickoff', '<p>Goals for the week: hero directions, pricing page, onboarding screens.</p><p>' . $chip('Homepage hero: three directions') . '</p><p>' . $chip('Onboarding flow: four screens') . '</p><p>Brand colours signed off by the client.</p><p>' . $chip('Colour palette: navy and lime') . '</p>'],
        ]);
        $doc('Weekly client call', 'private', null, [
            [3, 15, 'Thursday call', '<p>Client loved the icon set. Wants user testing on onboarding before launch.</p><p>' . $chip('Book five user tests for onboarding') . '</p><p>Launch moved to the 28th.</p>'],
        ]);

        // A second, smaller organization (a client with its own data).
        $org2 = $org('Fieldnotes Magazine', 'fieldnotes', 20);
        $mag = $project($org2, 'Issue 12 Layout', 'design', 20);
        $add($mag, [
            ['Cover options', 'For Review', [$AS], 'high', 2, 6, null],
            ['Feature spread: city gardens', 'In Progress', [$AS], null, 5, 4, null],
            ['Ad pages', 'Requested', [], null, 9, 2, null],
        ]);

        // Locally, Ravi (a Designer) can sign in for permission testing.
        if (config('env') === 'local') {
            $pw = random_password(16);
            DB::update('users', ['password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'must_change_password' => 0], 'id = ?', [$RD]);
            file_put_contents(BASE_PATH . '/storage/local-test-member.txt', "ravi@example.com\n{$pw}\n");
        }
        $out('Demo organizations "Juniper Studio" and "Fieldnotes Magazine" added, with 5 sample members.');
    });
}
