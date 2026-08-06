<?php
declare(strict_types=1);

namespace Daybreak\Security;

use Daybreak\Config;
use Daybreak\Database;
use SessionHandlerInterface;

/**
 * Stores PHP sessions in the `sessions` table.
 * Register with session_set_save_handler() BEFORE session_start().
 * Reads $_SESSION['user_id'] during write() to populate the user_id FK column,
 * enabling server-side session management (e.g. invalidating all sessions on
 * password change).
 */
final class DbSessionHandler implements SessionHandlerInterface
{
    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $row = Database::query(
            'SELECT payload FROM sessions
             WHERE id = ? AND last_activity > DATE_SUB(NOW(), INTERVAL ? SECOND)',
            [$id, (int) ini_get('session.gc_maxlifetime')]
        )->fetch();

        return $row ? (string) $row['payload'] : '';
    }

    public function write(string $id, string $data): bool
    {
        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        $ipHash = isset($_SERVER['REMOTE_ADDR'])
            ? hash('sha256', $_SERVER['REMOTE_ADDR'] . Config::requireAppKey())
            : null;
        $ua = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

        Database::query(
            'INSERT INTO sessions (id, user_id, ip_hash, user_agent, payload, last_activity)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
               user_id = VALUES(user_id),
               payload = VALUES(payload),
               last_activity = NOW()',
            [$id, $userId, $ipHash, $ua, $data]
        );
        return true;
    }

    public function destroy(string $id): bool
    {
        Database::query('DELETE FROM sessions WHERE id = ?', [$id]);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        $stmt = Database::query(
            'DELETE FROM sessions WHERE last_activity < DATE_SUB(NOW(), INTERVAL ? SECOND)',
            [$max_lifetime]
        );
        return $stmt->rowCount();
    }
}
