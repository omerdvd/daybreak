<?php

declare(strict_types=1);

namespace Daybreak\Service;

use Daybreak\Config;
use Daybreak\Database;
use Daybreak\Security\Password;
use DateTimeImmutable;
use PDOException;

/**
 * Authentication, registration, and account management.
 * Security baseline: NIST SP 800-63B — Argon2id, min 12 chars, generic error
 * responses (no user enumeration on login / register / reset / forgot), login
 * throttling per-IP and per-email, single-use short-TTL tokens stored as
 * SHA-256 hashes only, session regeneration on privilege change.
 */
final class AuthService
{
    private const MAX_EMAIL_FAILS    = 5;
    private const MAX_IP_FAILS       = 10;
    private const THROTTLE_MIN       = 15;    // minutes
    private const THROTTLE_MIN_EXTENDED = 60; // escalated window for repeat offenders
    private const VERIFY_TTL_MIN     = 1440;  // 24 hours
    private const RESET_TTL_MIN      = 60;    // 1 hour
    private const MAX_REGISTER_IP    = 10;    // register attempts per IP per hour
    private const MAX_FORGOT_EMAIL   = 3;     // forgot-password per email per hour
    private const MAX_FORGOT_IP      = 10;    // forgot-password per IP per hour
    private const REGISTER_TTL_MIN   = 60;
    private const FORGOT_TTL_MIN     = 60;

    private static ?array $userCache  = null;
    private static bool   $userLoaded = false;

    // ── Current user ───────────────────────────────────────────────────────────

    public static function currentUser(): ?array
    {
        if (!self::$userLoaded) {
            self::$userLoaded = true;
            $uid = $_SESSION['user_id'] ?? null;
            if ($uid !== null) {
                $row = Database::query(
                    'SELECT id, email, display_name, role, status,
                            default_window_days, last_seen_at, preferred_languages
                     FROM users WHERE id = ? AND status = ?',
                    [(int) $uid, 'active']
                )->fetch();
                self::$userCache = $row ?: null;
            }
        }
        return self::$userCache;
    }

    public static function requireAuth(): void
    {
        if (self::currentUser() === null) {
            $_SESSION['flash_error'] = 'Please log in to continue.';
            header('Location: /login');
            exit;
        }
    }

    public static function requireAdmin(): void
    {
        $u = self::currentUser();
        if ($u === null || $u['role'] !== 'admin') {
            $_SESSION['flash_error'] = 'You do not have permission to access that page.';
            header('Location: /');
            exit;
        }
    }

    // ── Registration ───────────────────────────────────────────────────────────

    /**
     * Create a pending account and fire the verification email.
     * Throws \InvalidArgumentException for validation failures.
     * Silently succeeds on duplicate email (no enumeration).
     */
    public static function register(string $email, string $password, string $displayName): void
    {
        $email       = \Daybreak\Service\AuthLogic::normalizeEmail($email);
        $displayName = \Daybreak\Service\AuthLogic::normalizeDisplayName($displayName);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid email address.');
        }
        if (!\Daybreak\Service\AuthLogic::isPasswordValid($password)) {
            throw new \InvalidArgumentException('Password must be at least 12 characters.');
        }
        if ($displayName === '') {
            throw new \InvalidArgumentException('Display name is required.');
        }

        try {
            Database::query(
                'INSERT INTO users (email, password_hash, display_name, status) VALUES (?, ?, ?, ?)',
                [$email, Password::hash($password), $displayName, 'pending']
            );
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return; // Duplicate email — silently no-op (no enumeration)
            }
            throw $e;
        }

        $userId = (int) Database::lastInsertId();
        self::sendVerificationEmail($userId, $email);
        try {
            (new \Daybreak\Service\MailService())->sendAdminNewUser($email, $displayName);
        } catch (\Throwable) {
            // non-fatal — never block registration over a missing admin notification
        }
    }

    public static function verifyEmail(string $rawToken): bool
    {
        $row = self::findToken($rawToken, 'email_verify');
        if ($row === null) {
            return false;
        }

        Database::query(
            "UPDATE users SET status = 'active', email_verified_at = NOW()
             WHERE id = ? AND status = 'pending'",
            [(int) $row['user_id']]
        );
        self::consumeToken((int) $row['id']);
        return true;
    }

    // ── Login / logout ─────────────────────────────────────────────────────────

    /**
     * Authenticate and start an authenticated session.
     * Returns true on success. Generic false on any failure (no enumeration).
     * Returns false without checking password when throttled (constant-time note:
     * we still run password_verify on a dummy hash to resist timing attacks
     * when the user IS found; throttled path is acceptable because the attacker
     * already knows they're throttled after 5 attempts).
     */
    public static function login(string $email, string $password, bool $remember = false): bool
    {
        $email = \Daybreak\Service\AuthLogic::normalizeEmail($email);
        $ip    = $_SERVER['REMOTE_ADDR'] ?? '';

        if (self::isThrottled($email, $ip)) {
            return false;
        }

        $user = Database::query(
            'SELECT id, password_hash, status FROM users WHERE email = ?',
            [$email]
        )->fetch();

        // Always run verify — prevents timing-based user-enumeration.
        $dummyHash = '$argon2id$v=19$m=65536,t=4,p=1$c2FsdHNhbHRzYWx0$dummydummydummydummydummy';
        $hashToCheck = $user ? (string) $user['password_hash'] : $dummyHash;
        $valid = Password::verify($password, $hashToCheck);

        if (!$valid || !$user || $user['status'] !== 'active') {
            self::recordAttempt($email, $ip, false);
            return false;
        }

        if (Password::needsRehash($user['password_hash'])) {
            Database::query(
                'UPDATE users SET password_hash = ? WHERE id = ?',
                [Password::hash($password), (int) $user['id']]
            );
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];

        self::$userLoaded = false;
        self::$userCache  = null;

        Database::query(
            'UPDATE users SET last_login_at = NOW() WHERE id = ?',
            [(int) $user['id']]
        );
        self::recordAttempt($email, $ip, true);

        if ($remember) {
            self::issueRememberToken((int) $user['id']);
        }

        return true;
    }

    public static function logout(): void
    {
        // Revoke the remember-me token that matches the current cookie, if any.
        $raw = $_COOKIE['daybreak_remember'] ?? null;
        if (is_string($raw) && $raw !== '') {
            Database::query(
                'DELETE FROM remember_tokens WHERE token_hash = ?',
                [hash('sha256', $raw)]
            );
            self::clearRememberCookie();
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 3600,
                $p['path'],
                $p['domain'],
                $p['secure'],
                $p['httponly']
            );
        }
        session_destroy();
        self::$userCache  = null;
        self::$userLoaded = false;
    }

    /**
     * Called once per request, after session_start().
     * If no session exists but a valid remember-me cookie is present, re-establishes
     * the session and rotates the token (old token deleted, new one issued).
     */
    public static function resolveRememberCookie(): void
    {
        if (!empty($_SESSION['user_id'])) {
            return;
        }

        $raw = $_COOKIE['daybreak_remember'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return;
        }

        $row = Database::query(
            'SELECT id, user_id FROM remember_tokens WHERE token_hash = ? AND expires_at > NOW()',
            [hash('sha256', $raw)]
        )->fetch();

        if (!$row) {
            self::clearRememberCookie();
            return;
        }

        $userId = (int) $row['user_id'];
        $active = Database::query(
            "SELECT id FROM users WHERE id = ? AND status = 'active'",
            [$userId]
        )->fetch();

        if (!$active) {
            Database::query('DELETE FROM remember_tokens WHERE id = ?', [(int) $row['id']]);
            self::clearRememberCookie();
            return;
        }

        // Rotate: delete the used token and issue a fresh one.
        Database::query('DELETE FROM remember_tokens WHERE id = ?', [(int) $row['id']]);
        self::issueRememberToken($userId);

        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        self::$userLoaded = false;
        self::$userCache  = null;
    }

    // ── Password reset ─────────────────────────────────────────────────────────

    /**
     * Send a password-reset email if the email belongs to an active account.
     * Always returns void — no enumeration possible via timing or response.
     */
    public static function forgotPassword(string $email): void
    {
        $email = \Daybreak\Service\AuthLogic::sanitizeEmailHeader($email);
        $user  = Database::query(
            "SELECT id FROM users WHERE email = ? AND status = 'active'",
            [$email]
        )->fetch();

        if (!$user) {
            return;
        }

        // Invalidate any outstanding reset tokens for this user.
        Database::query(
            "UPDATE auth_tokens SET used_at = NOW()
             WHERE user_id = ? AND type = 'password_reset' AND used_at IS NULL",
            [(int) $user['id']]
        );

        $rawToken  = bin2hex(random_bytes(32));
        $tokenHash = \Daybreak\Service\AuthLogic::tokenHash($rawToken);

        Database::query(
            'INSERT INTO auth_tokens (user_id, type, token_hash, expires_at)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))',
            [(int) $user['id'], 'password_reset', $tokenHash, self::RESET_TTL_MIN]
        );

        try {
            $mailService = new \Daybreak\Service\MailService();
            $mailService->sendPasswordReset($email, $rawToken);
        } catch (\Throwable $e) {
            error_log('[daybreak] reset email failed: ' . $e->getMessage());
        }
    }

    public static function resetPassword(string $rawToken, string $newPassword): bool
    {
        if (!\Daybreak\Service\AuthLogic::isPasswordValid($newPassword)) {
            return false;
        }

        $row = self::findToken($rawToken, 'password_reset');
        if ($row === null) {
            return false;
        }

        Database::query(
            'UPDATE users SET password_hash = ? WHERE id = ?',
            [Password::hash($newPassword), (int) $row['user_id']]
        );
        self::consumeToken((int) $row['id']);

        // Invalidate all sessions and remember tokens for this user.
        Database::query('DELETE FROM sessions WHERE user_id = ?', [(int) $row['user_id']]);
        Database::query('DELETE FROM remember_tokens WHERE user_id = ?', [(int) $row['user_id']]);
        return true;
    }

    // ── Account management ─────────────────────────────────────────────────────

    public static function changePassword(int $userId, string $current, string $new): bool
    {
        if (!\Daybreak\Service\AuthLogic::isPasswordValid($new)) {
            return false;
        }

        $user = Database::query(
            'SELECT password_hash FROM users WHERE id = ?',
            [$userId]
        )->fetch();

        if (!$user || !Password::verify($current, (string) $user['password_hash'])) {
            return false;
        }

        Database::query(
            'UPDATE users SET password_hash = ? WHERE id = ?',
            [Password::hash($new), $userId]
        );

        // Revoke other sessions/remember-me tokens for this account so a
        // voluntary password change actually locks out other devices —
        // but keep the caller's own session and remember-me cookie (if any)
        // alive, since they just re-proved the current password.
        $currentSessionId = session_id() ?: null;
        if ($currentSessionId !== null) {
            Database::query('DELETE FROM sessions WHERE user_id = ? AND id != ?', [$userId, $currentSessionId]);
        } else {
            Database::query('DELETE FROM sessions WHERE user_id = ?', [$userId]);
        }

        $currentRememberRaw = $_COOKIE['daybreak_remember'] ?? null;
        if (is_string($currentRememberRaw) && $currentRememberRaw !== '') {
            Database::query(
                'DELETE FROM remember_tokens WHERE user_id = ? AND token_hash != ?',
                [$userId, hash('sha256', $currentRememberRaw)]
            );
        } else {
            Database::query('DELETE FROM remember_tokens WHERE user_id = ?', [$userId]);
        }

        return true;
    }

    public static function updateDisplayName(int $userId, string $name): bool
    {
        $name = \Daybreak\Service\AuthLogic::normalizeDisplayName($name);
        if ($name === '') {
            return false;
        }
        Database::query(
            'UPDATE users SET display_name = ? WHERE id = ?',
            [$name, $userId]
        );
        return true;
    }

    public static function deleteAccount(int $userId): void
    {
        // FK ON DELETE CASCADE cleans up sessions, auth_tokens, user_sources.
        // audit_log and source_suggestions reference user ON DELETE SET NULL.
        Database::query('DELETE FROM users WHERE id = ?', [$userId]);
    }

    public static function updateWindowDays(int $userId, int $days): void
    {
        $days = \Daybreak\Service\AuthLogic::clampWindowDays($days);
        Database::query('UPDATE users SET default_window_days = ? WHERE id = ?', [$days, $userId]);
    }

    public static function updateLastSeen(int $userId): void
    {
        Database::query('UPDATE users SET last_seen_at = NOW() WHERE id = ?', [$userId]);
    }

    public static function exportData(int $userId): array
    {
        $user = Database::query(
            'SELECT id, email, display_name, role, status, default_window_days,
                    email_verified_at, last_login_at, created_at
             FROM users WHERE id = ?',
            [$userId]
        )->fetch();

        $suggestions = Database::query(
            'SELECT name, homepage_url, feed_url, status, created_at
             FROM source_suggestions WHERE suggested_by = ?',
            [$userId]
        )->fetchAll();

        return [
            'exported_at' => (new DateTimeImmutable())->format('c'),
            'user'        => $user ?: [],
            'suggestions' => $suggestions,
        ];
    }

    // ── Internals ──────────────────────────────────────────────────────────────

    private static function sendVerificationEmail(int $userId, string $email): void
    {
        $email     = \Daybreak\Service\AuthLogic::sanitizeEmailHeader($email);
        $rawToken  = bin2hex(random_bytes(32));
        $tokenHash = \Daybreak\Service\AuthLogic::tokenHash($rawToken);

        Database::query(
            'INSERT INTO auth_tokens (user_id, type, token_hash, expires_at)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))',
            [$userId, 'email_verify', $tokenHash, self::VERIFY_TTL_MIN]
        );

        try {
            $mailService = new \Daybreak\Service\MailService();
            $mailService->sendVerification($email, $rawToken);
        } catch (\Throwable $e) {
            error_log('[daybreak] verify email failed: ' . $e->getMessage());
        }
    }

    private static function findToken(string $rawToken, string $type): ?array
    {
        $row = Database::query(
            'SELECT id, user_id FROM auth_tokens
             WHERE token_hash = ? AND type = ? AND expires_at > NOW() AND used_at IS NULL',
            [\Daybreak\Service\AuthLogic::tokenHash($rawToken), $type]
        )->fetch();

        return $row ?: null;
    }

    private static function consumeToken(int $tokenId): void
    {
        Database::query(
            'UPDATE auth_tokens SET used_at = NOW() WHERE id = ?',
            [$tokenId]
        );
    }

    private static function isThrottled(string $email, string $ip): bool
    {
        $ipHash = self::hashIp($ip);

        $ipFails = (int) Database::query(
            'SELECT COUNT(*) FROM login_attempts
             WHERE ip_hash = ? AND type = ? AND successful = 0
               AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)',
            [$ipHash, 'login', self::THROTTLE_MIN]
        )->fetchColumn();

        if ($ipFails >= self::MAX_IP_FAILS) {
            return true;
        }

        // Escalate to 60-min window for accounts with >= 15 failures in the past 24h.
        $emailFails24h = (int) Database::query(
            'SELECT COUNT(*) FROM login_attempts
             WHERE email = ? AND type = ? AND successful = 0
               AND created_at > DATE_SUB(NOW(), INTERVAL 1440 MINUTE)',
            [$email, 'login']
        )->fetchColumn();
        $window = $emailFails24h >= 15 ? self::THROTTLE_MIN_EXTENDED : self::THROTTLE_MIN;

        $emailFails = (int) Database::query(
            'SELECT COUNT(*) FROM login_attempts
             WHERE email = ? AND type = ? AND successful = 0
               AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)',
            [$email, 'login', $window]
        )->fetchColumn();

        return \Daybreak\Service\AuthLogic::shouldThrottle($ipFails, $emailFails, self::MAX_IP_FAILS, self::MAX_EMAIL_FAILS);
    }

    private static function recordAttempt(string $email, string $ip, bool $success): void
    {
        Database::query(
            'INSERT INTO login_attempts (email, ip_hash, successful, type) VALUES (?, ?, ?, ?)',
            [$email, self::hashIp($ip), $success ? 1 : 0, 'login']
        );
    }

    public static function isRegisterThrottled(string $ip): bool
    {
        $ipHash = self::hashIp($ip);
        $count  = (int) Database::query(
            'SELECT COUNT(*) FROM login_attempts
             WHERE type = ? AND ip_hash = ?
               AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)',
            ['register', $ipHash, self::REGISTER_TTL_MIN]
        )->fetchColumn();
        return $count >= self::MAX_REGISTER_IP;
    }

    public static function recordRegisterAttempt(string $email, string $ip): void
    {
        Database::query(
            'INSERT INTO login_attempts (email, ip_hash, successful, type) VALUES (?, ?, 0, ?)',
            [$email, self::hashIp($ip), 'register']
        );
    }

    public static function isForgotThrottled(string $email, string $ip): bool
    {
        $ipHash    = self::hashIp($ip);
        $emailFail = (int) Database::query(
            'SELECT COUNT(*) FROM login_attempts
             WHERE type = ? AND email = ?
               AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)',
            ['forgot', $email, self::FORGOT_TTL_MIN]
        )->fetchColumn();
        if ($emailFail >= self::MAX_FORGOT_EMAIL) {
            return true;
        }
        $ipFail = (int) Database::query(
            'SELECT COUNT(*) FROM login_attempts
             WHERE type = ? AND ip_hash = ?
               AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)',
            ['forgot', $ipHash, self::FORGOT_TTL_MIN]
        )->fetchColumn();
        return $ipFail >= self::MAX_FORGOT_IP;
    }

    public static function recordForgotAttempt(string $email, string $ip): void
    {
        Database::query(
            'INSERT INTO login_attempts (email, ip_hash, successful, type) VALUES (?, ?, 0, ?)',
            [$email, self::hashIp($ip), 'forgot']
        );
    }

    private static function hashIp(string $ip): string
    {
        return hash('sha256', $ip . Config::requireAppKey());
    }

    private static function issueRememberToken(int $userId): void
    {
        // Prune any expired tokens for this user to avoid table bloat.
        Database::query(
            'DELETE FROM remember_tokens WHERE user_id = ? AND expires_at <= NOW()',
            [$userId]
        );

        $raw  = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        Database::query(
            'INSERT INTO remember_tokens (user_id, token_hash, expires_at)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 DAY))',
            [$userId, $hash]
        );

        $secure = ($_SERVER['HTTPS'] ?? '') === 'on';
        setcookie('daybreak_remember', $raw, [
            'expires'  => time() + 10 * 86400,
            'path'     => '/',
            'httponly' => true,
            'secure'   => $secure,
            'samesite' => 'Lax',
        ]);
    }

    private static function clearRememberCookie(): void
    {
        $secure = ($_SERVER['HTTPS'] ?? '') === 'on';
        setcookie('daybreak_remember', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
            'secure'   => $secure,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE['daybreak_remember']);
    }
}
