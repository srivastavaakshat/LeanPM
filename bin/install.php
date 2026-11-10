<?php
/**
 * LeanPM installer. Run once over SSH (Hostinger) or locally.
 *
 *   php bin/install.php --owner-email=you@example.com --owner-name="Akshat Srivastava"
 *   php bin/install.php --fresh --seed --owner-email=you@example.com   (local only: wipes everything first)
 *
 *  --seed   adds a demo "Acme Studio" organization with sample members and tasks
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

// 3. Demo data
if (isset($opts['seed'])) {
    if (DB::value("SELECT COUNT(*) FROM organizations WHERE slug = 'acme-studio'")) {
        $out('Demo organization already exists; skipped.');
        exit(0);
    }
    DB::transaction(function () use ($owner, $out) {
        $now = time();
        $at = fn (int $daysAgo, int $h = 11) => date('Y-m-d H:i:s', $now - $daysAgo * 86400 + ($h - 11) * 3600);
        $day = fn (int $offset) => date('Y-m-d', $now + $offset * 86400);

        $orgId = DB::insert('organizations', ['name' => 'Acme Studio', 'slug' => 'acme-studio', 'created_by' => $owner['id'], 'created_at' => $at(40)]);
        DB::insert('memberships', ['org_id' => $orgId, 'user_id' => $owner['id'], 'preset' => 'admin', 'permissions' => json_encode(Perms::all()), 'all_projects' => 1, 'active' => 1, 'joined_at' => $at(40)]);

        $people = [];
        foreach ([['Ravi Dutta', 'ravi@example.com', '#84cc16', 'designer'], ['Ayushi Mehra', 'ayushi@example.com', '#db2777', 'lead'], ['Neel Kapoor', 'neel@example.com', '#0891b2', 'viewer']] as [$n, $e, $c, $preset]) {
            $uid = DB::insert('users', ['name' => $n, 'email' => $e, 'password_hash' => password_hash(random_password(20), PASSWORD_DEFAULT), 'color' => $c, 'is_owner' => 0, 'must_change_password' => 1, 'created_at' => $at(39)]);
            DB::insert('memberships', ['org_id' => $orgId, 'user_id' => $uid, 'preset' => $preset, 'permissions' => json_encode(Perms::normalize(Perms::PRESETS[$preset]['perms'])), 'all_projects' => 1, 'active' => 1, 'joined_at' => $at(39)]);
            $people[$n] = $uid;
        }
        $AS = (int) $owner['id'];
        $RD = $people['Ravi Dutta'];
        $AY = $people['Ayushi Mehra'];

        $pid = DB::insert('projects', ['org_id' => $orgId, 'name' => 'Creative & Design Hub', 'created_by' => $owner['id'], 'created_at' => $at(40)]);
        Tasks::createDefaultStatuses($pid);
        \App\Services\Fields::addTemplate($pid, 'design');
        $st = array_column(Tasks::statuses($pid), 'id', 'name');

        // [title, status, assignees, priority, due offset, created days ago, completed days ago]
        $tasks = [
            ['Homepage redesign, part 2', 'In Progress', [$RD, $AS], null, 6, 2, null],
            ['Q4 launch: social media assets', 'In Progress', [], 'high', 1, 9, null],
            ['Ideas to improve the pricing page', 'In Progress', [$AS], null, null, 6, null],
            ['Product page 360° viewer', 'In Progress', [$RD], null, null, 4, null],
            ['Refresh the score card component', 'In Progress', [$RD], null, 10, 3, null],
            ['Brand refresh intro video', 'For Review', [], 'urgent', -1, 15, null],
            ['New HTML ad unit formats', 'For Review', [$AS], 'high', 3, 5, null],
            ['Navigation testing', 'For Review', [], null, null, 7, null],
            ['Homepage promotional banner', 'Revisions', [], 'normal', -2, 12, null],
            ['Ad design guidelines for the mobile app', 'Revisions', [$AY], null, 5, 8, null],
            ['Ad design guidelines for the blog', 'Approved', [$AY], null, null, 30, 20],
            ['Event landing page', 'Approved', [$RD], null, null, 45, 33],
            ['Games section design', 'Approved', [$RD], 'high', null, 25, 14],
            ['Match preview page', 'Approved', [$RD], null, null, 60, 52],
            ['Team profile pages', 'Approved', [$AS], null, null, 20, 11],
            ['Ad placement designs in Figma', 'Approved', [$AY], 'normal', null, 18, 9],
            ['Feature images for the tools section', 'Approved', [$RD, $AY], null, null, 70, 61],
        ];
        $ids = [];
        foreach ($tasks as $i => [$title, $status, $assignees, $prio, $due, $created, $completed]) {
            $id = DB::insert('tasks', [
                'project_id' => $pid, 'status_id' => $st[$status], 'title' => $title, 'priority' => $prio, 'position' => $i,
                'due_date' => $due === null ? null : $day($due), 'created_by' => $AS,
                'created_at' => $at($created), 'updated_at' => $at($completed ?? 0), 'completed_at' => $completed === null ? null : $at($completed, 17),
            ]);
            foreach ($assignees as $u) {
                DB::insert('task_assignees', ['task_id' => $id, 'user_id' => $u]);
            }
            DB::insert('activity', ['task_id' => $id, 'user_id' => $AS, 'action' => 'created', 'created_at' => $at($created)]);
            $ids[$title] = $id;
        }

        // One task with a description, subtasks and a comment.
        $main = $ids['Homepage redesign, part 2'];
        DB::update('tasks', ['description' => '<h3>Info page: 40% drop-off</h3><p>Can we keep people engaged before they leave?</p><h3>“For you” section on Android</h3><p>Redesign this.</p>'], 'id = ?', [$main]);
        foreach (['Info tab', 'Check the “For you” section'] as $j => $sub) {
            $sid = DB::insert('tasks', ['project_id' => $pid, 'parent_id' => $main, 'status_id' => $st['Requested'], 'title' => $sub, 'position' => $j, 'created_by' => $AS, 'created_at' => $at(2, 15), 'updated_at' => $at(2, 15)]);
            DB::insert('activity', ['task_id' => $sid, 'user_id' => $AS, 'action' => 'created', 'created_at' => $at(2, 15)]);
            DB::insert('activity', ['task_id' => $main, 'user_id' => $AS, 'action' => 'subtask_added', 'to_value' => $sub, 'created_at' => $at(2, 15)]);
        }
        DB::insert('comments', ['task_id' => $main, 'user_id' => $AS, 'body' => 'Check with Ayushi on the data: why was the “For you” section added?', 'created_at' => $at(2, 13)]);
        DB::insert('activity', ['task_id' => $main, 'user_id' => $AS, 'action' => 'status', 'from_value' => 'Requested', 'to_value' => 'In Progress', 'created_at' => $at(2, 13)]);

        $out('Demo organization "Acme Studio" added with 3 sample members (they can’t sign in until you reset their passwords).');
    });
}

$out('Done.');
