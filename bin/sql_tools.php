<?php
/** Shared by bin/install.php and bin/migrate.php. */
declare(strict_types=1);

/** Translate MySQL DDL into SQLite for zero-setup local development. */
function mysql_to_sqlite(string $sql): string
{
    $sql = preg_replace('/^\s*SET [^;]+;/mi', '', $sql);
    $sql = preg_replace('/\)\s*ENGINE=[^;]+;/i', ');', $sql);
    $sql = preg_replace('/\b(INT|BIGINT) UNSIGNED AUTO_INCREMENT PRIMARY KEY/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
    $sql = preg_replace('/\s+UNSIGNED\b/i', '', $sql);
    $sql = preg_replace('/UNIQUE KEY \w+ \(/i', 'UNIQUE (', $sql);
    $indexes = [];
    $sql = preg_replace_callback('/CREATE TABLE IF NOT EXISTS (\w+) \((.*?)\n\);/s', function ($m) use (&$indexes) {
        $keep = [];
        foreach (explode("\n", $m[2]) as $line) {
            if (preg_match('/^\s*KEY (\w+) \(([^)]+)\),?\s*$/', $line, $k)) {
                $indexes[] = "CREATE INDEX IF NOT EXISTS {$k[1]} ON {$m[1]} ({$k[2]});";
                continue;
            }
            $keep[] = $line;
        }
        $body = preg_replace('/,\s*$/', '', rtrim(implode("\n", $keep)));
        return "CREATE TABLE IF NOT EXISTS {$m[1]} ({$body}\n);";
    }, $sql);
    return $sql . "\n" . implode("\n", $indexes);
}

function split_sql(string $sql): array
{
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    return array_values(array_filter(array_map('trim', explode(';', $sql)), fn ($s) => $s !== ''));
}
