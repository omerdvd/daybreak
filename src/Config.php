<?php
declare(strict_types=1);

namespace Daybreak;

use PDO;
use PDOStatement;
use RuntimeException;

/**
 * Config: loads config/.env (KEY=VALUE) once.
 * Database: thin PDO wrapper. ALL data access goes through Database::query()
 * with bound parameters. Never interpolate input into SQL.
 */
final class Config
{
    private static array $values = [];
    private static bool $loaded = false;

    public static function load(string $envFile): void
    {
        if (self::$loaded) {
            return;
        }
        if (is_file($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
                self::$values[trim($k)] = trim($v, " \t\"'");
            }
        }
        self::$loaded = true;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return self::$values[$key] ?? getenv($key) ?: $default;
    }

    /**
     * APP_KEY as a required secret: throws if unset or still the documented
     * placeholder, instead of letting callers silently fall back to a known
     * default (which would defeat any hashing/encryption keyed on it).
     */
    public static function requireAppKey(): string
    {
        $key = (string) self::get('APP_KEY', '');
        if ($key === '' || $key === 'change-me-32-byte-random') {
            throw new RuntimeException('APP_KEY must be configured (see config/.env.example)');
        }
        return $key;
    }
}

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dsn = Config::get('DB_DSN');
            if ($dsn === null) {
                throw new RuntimeException('DB_DSN not configured');
            }
            self::$pdo = new PDO($dsn, Config::get('DB_USER'), Config::get('DB_PASS'), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return self::$pdo;
    }

    /** Run a parameterised statement and return the PDOStatement. */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function lastInsertId(): string
    {
        return self::pdo()->lastInsertId();
    }
}
