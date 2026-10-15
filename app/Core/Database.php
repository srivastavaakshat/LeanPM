<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

/**
 * Thin PDO wrapper. Every query goes through prepared statements.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo) {
            return self::$pdo;
        }
        $c = config('database');
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        if ($c['driver'] === 'sqlite') {
            self::$pdo = new PDO('sqlite:' . $c['sqlite_path'], null, null, $options);
            self::$pdo->exec('PRAGMA foreign_keys = ON');
            self::$pdo->exec('PRAGMA journal_mode = WAL');
        } else {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], $c['port'], $c['database'], $c['charset']);
            self::$pdo = new PDO($dsn, $c['username'], $c['password'], $options);
        }
        return self::$pdo;
    }

    public static function driver(): string
    {
        return config('database.driver');
    }

    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        foreach (array_values($params) === $params ? $params : [] as $i => $v) {
            $stmt->bindValue($i + 1, $v, self::type($v));
        }
        if (array_values($params) !== $params) {
            foreach ($params as $k => $v) {
                $stmt->bindValue(':' . ltrim((string) $k, ':'), $v, self::type($v));
            }
        }
        $stmt->execute();
        return $stmt;
    }

    private static function type(mixed $v): int
    {
        return match (true) {
            is_int($v)  => PDO::PARAM_INT,
            is_bool($v) => PDO::PARAM_INT,
            $v === null => PDO::PARAM_NULL,
            default     => PDO::PARAM_STR,
        };
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::query($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $cols),
            implode(', ', array_fill(0, count($cols), '?'))
        );
        self::query($sql, array_map(fn ($v) => is_bool($v) ? (int) $v : $v, array_values($data)));
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $set = implode(', ', array_map(fn ($c) => "$c = ?", array_keys($data)));
        $params = array_merge(array_map(fn ($v) => is_bool($v) ? (int) $v : $v, array_values($data)), $whereParams);
        return self::query("UPDATE $table SET $set WHERE $where", $params)->rowCount();
    }

    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Build "?, ?, ?" for IN clauses. */
    public static function placeholders(array $items): string
    {
        return implode(', ', array_fill(0, max(1, count($items)), '?'));
    }
}
