# Syncing this fork with SilverDay/daybreak

This fork ([omerdvd/daybreak](https://github.com/omerdvd/daybreak)) exists to
run the private, push-only [ntfy deployment](DEPLOYMENT_DAYBREAK_SERVER.md) —
it isn't a passive fork. GitHub doesn't auto-pull upstream changes; this is a
manual, occasional process. This doc is the "how" and the running log of when
it's been done.

## How

```bash
git remote add upstream https://github.com/SilverDay/daybreak.git   # once
git fetch upstream
git log --oneline main..upstream/main   # see what's actually new
git log --stat main..upstream/main      # see what files each commit touches
```

**Before merging, check each incoming commit for anything that touches code
this fork depends on** — at minimum: `CredentialVault`/`Config` (the ntfy
token encryption relies on these), `WebhookService`/`WebhookController`
(shouldn't exist upstream at all — if they ever do, that's a real conflict to
resolve carefully, not a routine merge), and migration filenames (numbering
can collide since both sides add migrations independently — check
`migrations/*.sql` for same-number-different-content files; a *filename*
collision would be a real problem, a same-number-different-name collision
like `022_site_notification.sql` vs `022_webhook_ntfy_format.sql` is fine,
`migrations/run.php` sorts and applies by filename, not by number).

```bash
git merge upstream/main --no-edit
```

**Then, before pushing anywhere** (this is not optional — a broken merge on
a server that's the actual point of this fork's existence is a real outage,
not an inconvenience):

1. Push to `origin/main`, then on the `daybreak` server:
   `git fetch -q && git reset --hard origin/main`
2. Apply any new migrations: `php migrations/run.php`
3. Run the full test suite: `php tests/run.php` — expect it to still fully
   pass, not just "mostly."
4. Confirm the webgui still loads: `curl -I https://daybreak.tail05f000.ts.net/`
5. **Run a real ntfy dispatch smoke test** — this is the check that actually
   matters for this fork specifically. It's not enough that tests pass;
   confirm `CredentialVault::decrypt()` can still decrypt the *already-stored*
   encrypted `daybreak-bot` token (a refactor to key derivation logic could
   pass unit tests using freshly-encrypted values while silently breaking
   decryption of data encrypted before the change). See
   [DEPLOYMENT_DAYBREAK_SERVER.md](DEPLOYMENT_DAYBREAK_SERVER.md) for the
   dispatch-a-synthetic-item pattern used for this throughout setup.

If any of the above look wrong, fix forward or revert the merge commit before
it reaches the server — don't leave a half-verified merge running the actual
push pipeline.

## Sync log

### 2026-08-06 — 6 commits merged, clean

`4735f0e`..`c6a2b26` on `SilverDay/daybreak:main`. All reviewed individually
before merging (see the commit messages themselves for full detail):

- **Security audit fixes**: `requireAdmin()`/`requireAuth()` now redirect
  with a flash-error reason instead of a bare 403 or silent redirect;
  `SuggestController` validates `feed_url` as http(s):// (closed a stored
  `javascript:` URI gap); `AuthService::changePassword()` now revokes other
  sessions/remember-tokens (previously only done on password *reset*, not
  voluntary *change*); `Config::requireAppKey()` — a shared throw-if-unset
  check, replacing three call sites that previously duplicated it (one of
  them, `CredentialVault`, being exactly what this fork's ntfy token
  encryption depends on); `bootstrap.php` gained explicit `log_errors`/
  `error_log` config plus a top-level `set_exception_handler` backstop.
- **RSS/Atom encoding fixes**: mis-encoded UTF-8 byte repair for feeds that
  actually declare UTF-8 (fixed the Okta feed returning 0 articles), then a
  follow-up scoping that repair to *only* UTF-8-declaring feeds after it
  double-encoded Golem's honestly-declared ISO-8859-1 feed.
- **New feature**: admin-toggleable site notification banner (not something
  this fork asked for, but harmless and additive — new migration
  `022_site_notification.sql`, coexists fine with this fork's own
  `022_webhook_ntfy_format.sql`, different filenames).

Merge was conflict-free (`git merge` needed no manual resolution — the two
codebases hadn't touched any of the same files). Verified on the server
post-merge: all 125 tests passed, new migration applied cleanly, webgui
loaded, and — the check that actually matters here — a real ntfy dispatch
to both topics succeeded, confirming the `CredentialVault` refactor didn't
break decryption of the already-stored `daybreak-bot` token.

Created `storage/logs/` on the server (`omer:www-data`, `g+rwxs`) for the
new `bootstrap.php` error-log path, which assumes the directory exists but
doesn't create it.
