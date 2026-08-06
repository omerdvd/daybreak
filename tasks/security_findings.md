## [FINDING] Suggestion feed_url not scheme-validated
- **Date**: 2026-08-06
- **Severity**: Medium
- **Location**: `src/Controller/SuggestController.php` (`handle()`), rendered at `src/View/admin/suggestions/list.php:34`
- **Type**: Missing Input Validation
- **Description**: `homepage_url` was validated as `http(s)://` via `filter_var(...FILTER_VALIDATE_URL)` + regex, but a directly-submitted `feed_url` skipped that check and was stored/rendered as a raw `href` in the admin suggestions panel — any authenticated user could submit `feed_url = javascript:...`. Likely mitigated in practice by the app's nonce-based CSP (no `unsafe-inline`), but shouldn't rely on that alone.
- **Recommendation**: Validate `feed_url` the same way as `homepage_url`.
- **Status**: Fixed — same `filter_var`/`^https?://` check now applied to `feed_url` when non-empty.

## [FINDING] changePassword() did not revoke other sessions/remember tokens
- **Date**: 2026-08-06
- **Severity**: Low
- **Location**: `src/Service/AuthService.php` (`changePassword()`)
- **Type**: Session Management Weakness
- **Description**: `resetPassword()` (forgot-password flow) revoked all sessions/remember tokens for the account; `changePassword()` (settings page) did not, so a stolen device with an active session/remember-me cookie would survive a voluntary password change.
- **Recommendation**: Revoke other sessions/remember tokens on password change, preserving the caller's own current session/cookie.
- **Status**: Fixed — verified with a test user that the current session and current remember-me token survive while all others are revoked; full test suite still passes (110/110).

## [FINDING] Silent APP_KEY fallback in IP-hashing call sites
- **Date**: 2026-08-06
- **Severity**: Low
- **Location**: `src/Service/AuthService.php::hashIp()`, `src/Service/AuditLog.php::write()`, `src/Security/DbSessionHandler.php::write()`
- **Type**: Insecure Default
- **Description**: All three used `Config::get('APP_KEY', 'daybreak')`, silently degrading to a known hardcoded key if `APP_KEY` was ever left unset — unlike `CredentialVault::key()`, which correctly throws on an unset/placeholder key.
- **Recommendation**: Fail loudly instead of silently using a known key.
- **Status**: Fixed — added `Config::requireAppKey()` (throws if unset or still the documented placeholder) and switched all three call sites plus `CredentialVault` to use it. Verified: throws on `''` and `'change-me-32-byte-random'`, succeeds with the real configured key.

## [FINDING] No backstop for errors outside the front controller's try/catch
- **Date**: 2026-08-06
- **Severity**: Low
- **Location**: `src/bootstrap.php`
- **Type**: Missing Defensive Control / Logging Gap
- **Description**: `public/index.php` already wraps `$router->dispatch()` in a try/catch that logs and returns a generic message, but anything thrown outside that window (session/header setup, CLI scripts) had no explicit handler, and PHP's own `log_errors`/`error_log` ini state was never set by the app — logging depended entirely on ambient host `php.ini`.
- **Recommendation**: Explicitly configure `log_errors`/`error_log`, and register a top-level `set_exception_handler` as a last-resort backstop that always logs full detail server-side and never echoes it (SAPI-aware: no HTML on CLI).
- **Status**: Fixed — added both to `bootstrap.php`. Verified via a real (non-`-r`) PHP script: full exception detail (including a simulated secret) appears in `storage/logs/php-error.log`, nothing is echoed on CLI SAPI, and the web-SAPI branch echoes only the hardcoded literal `Internal error`.

## [FINDING] GitHub PAT hardcoded in git remote URL
- **Date**: 2026-08-06
- **Severity**: High
- **Location**: `.git/config` — `remote.origin.url`
- **Type**: Hardcoded Credential
- **Description**: The `origin` remote is configured as `https://ghp_***@github.com/SilverDay/daybreak.git` — a GitHub personal access token embedded directly in the URL. It's stored in plaintext in `.git/config` (readable by anyone with local/shell access) and is echoed in full by any `git remote -v`, `git config -l`, or similar command, including into terminal history and tool logs.
- **Recommendation**: Rotate this token now that it has been displayed in a tool transcript, then reconfigure the remote without embedding a credential in the URL — use SSH (`git@github.com:SilverDay/daybreak.git`) with a deploy key, or a plain HTTPS URL backed by a git credential helper / cached credential store. Never put tokens directly in `remote.url`.
- **Status**: Open — flagged to user, not remediated (requires user's decision to rotate the token and choice of auth method)

## [FINDING] CSRF Empty-Token Acceptance Edge Case
- **Date**: 2026-06-12
- **Severity**: Medium
- **Location**: src/Security/Csrf.php
- **Type**: CSRF Validation Weakness
- **Description**: `Csrf::check()` previously compared the submitted token against `$_SESSION['csrf'] ?? ''`. If no session token existed and an empty token was submitted, `hash_equals('', '')` succeeded.
- **Recommendation**: Require a non-empty server-side token and non-empty submitted token before comparison; reject otherwise.
- **Status**: Fixed
