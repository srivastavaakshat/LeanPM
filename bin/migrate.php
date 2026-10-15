<?php
/**
 * Apply new files from database/migrations/ (in name order) that haven't run yet.
 *   php bin/migrate.php
 * No SSH? Paste the new .sql file into phpMyAdmin → SQL instead, then it's done.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

require __DIR__ . '/sql_tools.php';

$files = glob(BASE_PATH . '/database/migrations/*.sql') ?: [];
sort($files);
$ran = 0;
foreach ($files as $f) {
    $name = basename($f);
    if (Database::one('SELECT filename FROM schema_migrations WHERE filename = ?', [$name])) {
        continue;
    }
    if (Database::driver() === 'sqlite' && str_contains($name, '.mysql.')) {
        echo "Skipping $name on SQLite (MySQL-only).\n";
    } else {
        $sql = file_get_contents($f);
        foreach (split_sql(Database::driver() === 'sqlite' ? mysql_to_sqlite($sql) : $sql) as $stmt) {
            Database::pdo()->exec($stmt);
        }
        echo "Applied $name\n";
    }
    Database::insert('schema_migrations', ['filename' => $name, 'applied_at' => now()]);
    $ran++;
}
echo $ran ? "Done.\n" : "Nothing to migrate.\n";
