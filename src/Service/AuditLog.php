<?php
declare(strict_types=1);

namespace Daybreak\Service;

use Daybreak\Config;
use Daybreak\Database;
use Daybreak\Service\AuthService;

/** Thin wrapper for writing audit_log rows on every admin mutation. */
final class AuditLog
{
    public static function write(string $action, string $targetType = '', string $targetId = ''): void
    {
        $user  = AuthService::currentUser();
        $uid   = $user ? (int) $user['id'] : null;
        $ip    = $_SERVER['REMOTE_ADDR'] ?? '';
        $ipHash = hash('sha256', $ip . Config::requireAppKey());

        Database::query(
            'INSERT INTO audit_log (user_id, action, target_type, target_id, ip_hash)
             VALUES (?, ?, ?, ?, ?)',
            [$uid, $action, $targetType ?: null, $targetId !== '' ? $targetId : null, $ipHash]
        );
    }
}
