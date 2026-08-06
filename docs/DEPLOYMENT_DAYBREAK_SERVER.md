# Deployment record: the `daybreak` server

This documents the actual private, push-only Daybreak deployment — a
record of what was built and why, for anyone (including future-me)
maintaining this box. Companion to
[FEATURE_NTFY_PUSH_NOTIFICATIONS.md](FEATURE_NTFY_PUSH_NOTIFICATIONS.md),
which covers the application-level design.

## Why this deployment model

This fork exists purely to power push notifications for a small team —
**it is not a public website**. See
[FEATURE_NTFY_PUSH_NOTIFICATIONS.md](FEATURE_NTFY_PUSH_NOTIFICATIONS.md#deployment-model-decided)
for the full rationale. In short: `bin/fetch.php` (cron-driven) does
fetch → dedup → webhook dispatch entirely independent of the web
frontend, article links in notifications already point at the original
source (never a Daybreak-hosted page), and team members only need the
ntfy app + topic — never Daybreak itself. The optional admin webgui is
Tailscale-only, never public.

## Hosting

- **Linode Nanode**, **Atlanta, GA** (Newark NJ — the closest match to
  the existing Minecraft/Linode server's region — was at capacity at
  provisioning time; Atlanta is still low-latency US East).
- Ubuntu 24.04.4 LTS, hostname `daybreak`.
- 1 vCPU / ~1 GB RAM / 25 GB disk — same tier as the self-hosted ntfy
  box; comfortably sufficient for a cron-driven fetcher with no public
  traffic.
- Joined to the existing Tailscale tailnet (`tail05f000.ts.net`) as
  `daybreak` (`100.71.128.39`), key expiry disabled.

## Network / access model

**Public inbound: none.** A Linode Cloud Firewall (`daybreak-fw`) is
attached with `inbound_policy: DROP`, zero explicit inbound rules, and
`outbound_policy: ACCEPT`. Verified: the public IP times out on port 22
from the outside; only Tailscale reaches this box. (Tailscale itself
needs no inbound firewall rule — it's outbound-initiated, and worked
immediately over DERP relay even before any inbound rule existed.)

Layered under that (defense-in-depth, since the Cloud Firewall already
drops everything):

- **UFW**: default-deny incoming. Allows the home IPv4/IPv6 on port 22
  (kept for now, mostly redundant since the Cloud Firewall already
  blocks public inbound — retained as a second layer) and all traffic
  on `tailscale0`.
- **`home-fw-sync`** (`/usr/local/bin/home-fw-sync.sh` +
  `home-fw-sync.service`/`.timer`, every 5 min): re-resolves
  `home.omeruthi.online`'s A/AAAA and rewrites the UFW allow rules on
  change — same pattern as the Minecraft server's `home-dns-sync.sh`,
  needed because the home IPv6 rotates even though IPv4 is static.
- **fail2ban**: `sshd` jail, `bantime=1h findtime=10m maxretry=3`.

### SSH access

- Key: 1Password item `daybreak-linode-ssh` (Ed25519), installed for
  user `omer`.
- **Stacked MFA**: `AuthenticationMethods publickey,keyboard-interactive`
  in `sshd_config`, `pam_google_authenticator` in `/etc/pam.d/sshd`
  (with `@include common-auth` commented out, not double-included —
  same gotcha as the ntfy server's setup notes). TOTP enrolled for
  `omer`; secret and emergency scratch codes live in 1Password, not
  reproduced here.
- `sudo` for `omer` is password-protected (not passwordless) — a
  deliberate choice: SSH access alone (key + TOTP) isn't sufficient to
  reach root, a compromised/hijacked already-authenticated SSH session
  still needs the sudo password too.
- `~/.ssh/config` entry uses the Tailscale MagicDNS name
  (`daybreak.tail05f000.ts.net`), `IdentitiesOnly yes`, and
  `ControlMaster auto` / `ControlPersist 4h` — connection multiplexing
  so one key+TOTP login covers a working session's worth of commands
  instead of prompting per-command.
- Root SSH login and password auth are both effectively unreachable
  (root has no TOTP secret configured, so it fails the
  keyboard-interactive stage) — Lish console remains the console-level
  fallback, independent of sshd config entirely.

### Sudo caching for automation

`/etc/sudoers.d/omer-session-cache`: `Defaults:omer !tty_tickets` +
`timestamp_timeout=240`. Widens sudo's credential cache from
per-tty to per-session (4h, matching `ControlPersist`) — established
once per working session via an interactive `sudo -v`, after which
non-interactive commands over the multiplexed SSH connection can use
`sudo` without a password prompt for the rest of that window.

## Hardening

- **Lynis**: hardening index 60 → 64 after a custom profile
  (`/etc/lynis/custom.prf`) with reasoned skips matching the pattern
  already used on the ntfy/Minecraft boxes: `SSH-7408` (false positive —
  Lynis can't see the Cloud Firewall from inside the host),
  `FILE-6310`/`AUTH-9282`/`BOOT-5122`/`LOGG-2154`/`BANN-7126`/
  `BANN-7130`/`BOOT-5264`/`TOOL-5002` (not worth retrofitting on a
  single-admin cloud VM), `HRDN-7230` (rkhunter — left undecided,
  same RAM-constraint reasoning as the ntfy box). **AIDE was
  explicitly skipped** for this box (unlike ntfy) — lower value here
  given the narrower attack surface (no public listener at all).
- `/etc/sysctl.d/99-hardening.conf`: martian logging, redirect/source-
  route rejection (v4+v6), SYN cookies, `kptr_restrict`,
  `dmesg_restrict`, `unprivileged_bpf_disabled`, `bpf_jit_harden`.
- `/etc/modprobe.d/blacklist-rare-protocols.conf`: `dccp`/`sctp`/`rds`/
  `tipc` blacklisted.
- `debsums` daily integrity check enabled (`CRON_CHECK=daily`,
  unquoted — a quoted value fails Lynis's check, per the ntfy box's
  own notes).
- `unattended-upgrades`: security-origin packages only, auto-reboot
  `03:30 Asia/Jerusalem`.
- Legal banner set on `/etc/issue` / `/etc/issue.net`.

## Application stack

- PHP 8.3.6, MariaDB 10.11.14, Apache 2.4.58 — exact match to the
  README's pinned versions, all straight from Ubuntu 24.04's default
  repos (no PPA needed).
- MariaDB bound to `127.0.0.1` only (default). Schema `daybreak`,
  least-privilege user `daybreak_app` (`SELECT/INSERT/UPDATE/DELETE/
  CREATE/ALTER/DROP/INDEX/REFERENCES` scoped to `daybreak.*` only —
  broader than pure DML because `migrations/run.php` runs schema
  changes as this same user per the app's own `.env.example`
  convention).
- App deployed to `/srv/daybreak` (outside the web root, per the
  repo's own architecture — `config/.env` lives above `public/`).
  Owned `omer:www-data`, group-readable, `storage/` group-writable
  with setgid so new files inherit the `www-data` group.
- `config/.env`: `APP_BASE_URL=https://daybreak.tail05f000.ts.net`,
  DB credentials, a generated `APP_KEY` (also the key
  `CredentialVault` derives its AES-256-GCM key from — see the ntfy
  feature doc). `chmod 640 omer:www-data`.
- Apache bound **only** to the Tailscale interface IP
  (`Listen 100.71.128.39:80`/`443` in `ports.conf`, default site
  disabled) — belt-and-suspenders on top of the Cloud Firewall; even a
  local misconfiguration can't expose it publicly since nothing binds
  the public interface at all. `daybreak.conf` vhost: HTTP→HTTPS
  redirect, HSTS, immutable asset caching, `DocumentRoot
  /srv/daybreak/public`.
- TLS via `tailscale cert` (`/etc/tailscale-certs/daybreak.{crt,key}`,
  `www-data:www-data 640`, weekly renewal via
  `/etc/cron.weekly/tailscale-cert-renew` reloading Apache) — a real
  Let's Encrypt-issued cert for the MagicDNS name, despite the site
  never being publicly reachable. Needed because the app's own CSP
  (`upgrade-insecure-requests`) and conditional HSTS header assume
  HTTPS even over a private network.
- One owner account exists purely for the `user_webhooks.user_id` FK
  (`omer@omeruthi.online`, `role=admin`, `status=active`, random
  password never intended for login — the webgui isn't the point of
  this deployment, see above).

## Cron

```cron
*/30 * * * * php /srv/daybreak/bin/fetch.php >> /var/log/daybreak-fetch.log 2>&1
0 3 * * * php /srv/daybreak/bin/prune.php >> /var/log/daybreak-prune.log 2>&1
```

30-minute fetch interval (down from the README's suggested 5 minutes —
deliberate for this deployment: nothing here is time-critical to the
minute, and less frequent polling means less load and noise). Logs
rotated weekly, 8 weeks retained, via `/etc/logrotate.d/daybreak`.

**First-run backfill gotcha, for next time:** on a genuinely empty
`articles` table, every currently-active item from every source in a
dispatched-category (`critical` here) looks "new" and gets pushed —
this flooded ~100 notifications and hit the self-hosted ntfy server's
own publish rate limit (HTTP 429) on first deploy. The 429s were
cleanly recoverable (`WebhookService::retryFailed()` would have retried
them within 24h) but were instead deliberately suppressed
(`webhook_log` rows manually marked `retry_failed`) to avoid a second
wave of stale-CVE notifications. If redeploying from scratch: consider
running the very first `bin/fetch.php` with `WEBHOOK` dispatch
disabled, or accept the one-time flood.

### Fetch-cron health check

The whole point of this deployment is reliable delivery — a silently
broken fetch cron would defeat it with zero signal, the same failure
shape that's already bitten this homelab before (`pvescheduler`
stopping silently for ~5 days on Proxmox, fail2ban disabled for 6 days
on Minecraft). Closed with `/usr/local/bin/daybreak-fetch-health.sh`,
run via `daybreak-fetch-health.timer` (systemd oneshot, every 10 min,
matching the standing "timer, not cron" convention for new
automation):

- **Self-heals**: kills any `fetch.php` process that's been running
  longer than 15 minutes (abnormal for a 30-min cadence job — almost
  certainly hung and holding `GET_LOCK`, which would otherwise block
  every subsequent cron tick indefinitely) before checking anything
  else.
- **Alerts** (to the existing `communication` topic — `daybreak-bot`
  granted write access there too, alongside its other topics) if
  `fetch_log` has no activity in the last 50 minutes (one missed
  30-min tick + buffer) or has no entries at all.
- **Rate-limited to one alert per hour** while unhealthy
  (`/var/lib/daybreak-fetch-health/last_alert`) — avoids spamming a
  push every 10 minutes for the same ongoing outage.
- Verified live: forced a stale-state test run (real push received,
  confirmed the alert path — not just the happy path — actually
  works) and confirmed the cooldown suppresses a second alert fired
  immediately after.

## ntfy integration

Two topics on the existing self-hosted ntfy server
(`ntfy.omeruthi.online`) — **not** a new ntfy server, reusing the one
documented in the homelab connectivity project notes:

- **`daybreak-critical-COO`** — the company topic. Originally named
  `daybreak-critical`, renamed once the public topic was added and this
  one's role narrowed to "company apps only" (see
  [#5](https://github.com/omerdvd/daybreak/issues/5)). ntfy topics
  aren't literally renamed as objects — this was done by granting
  access on the new name and revoking (`ntfy access --reset`) the old
  one for both `daybreak-bot` and `daybreak-readers`. Read access:
  `daybreak-readers` shared account only (real password, not a token —
  see the on-boarding steps below for why).
- **`daybreak-critical-public`** — unfiltered, every Critical/Patch Now
  article, no `terms` narrowing. Read access: anonymous
  (`ntfy access everyone daybreak-critical-public read-only`) —
  **reading** only; publishing stays locked to `daybreak-bot` on both
  topics, same as always. Open anonymous *write* would let anyone spoof
  fake "critical" alerts into it.

Publish URLs used in both webhook configs use the **public** domain
(`https://ntfy.omeruthi.online/...`), not the Tailscale one. This was a
deliberate, non-obvious choice: Daybreak's own `SsrfGuard` explicitly
denies the Tailscale CGNAT range (`100.64.0.0/10`), so
`ntfy.tail05f000.ts.net` would be blocked outright by the app's own
outbound-fetch protection. The public domain is already the
established pattern elsewhere (Home Assistant's `rest_command`
integration uses it too), so this isn't a new exception.

- `daybreak-bot`: write-only scoped ntfy user/token, granted write
  access to both topics (plus `backups`, for the encrypted-backup
  failure alert). The token is stored **encrypted** in
  `user_webhooks.secret_enc` via `CredentialVault` (AES-256-GCM, keyed
  off `APP_KEY`) — decrypted in-process only when building the
  `Authorization` header for delivery. Never stored or logged in
  plaintext. Both webhook rows currently reuse the same encrypted
  token value.
- `daybreak-readers`: read-only scoped ntfy account for the company
  topic, shared across the whole team (one account, not per-person) —
  the public topic needs no credential at all. Subscribing via the
  mobile apps uses its real account **password**, not its API token
  (the apps' Basic Auth flow only accepts real passwords — confirmed
  via real-device testing after an initial wrong assumption otherwise).
  See the team on-boarding steps below.
- ntfy publishes are throttled to 1/5s in `WebhookService` (matches the
  server's `visitor-request-limit-replenish`) — see "Push notification
  formatting" below for why.
- Both accounts' account passwords (as opposed to their tokens) were
  set to throwaway random values at creation — they authenticate via
  token only and the passwords are never used or distributed.

### Team subscription steps (ntfy app, iOS/Android)

**Company topic** (`daybreak-critical-COO`, app/software-filtered once
the `terms` list arrives):

1. Add subscription → **Topic**: `daybreak-critical-COO`.
2. "Use a different server" → `https://ntfy.omeruthi.online`.
3. In the app's **Settings → Users** section (not a per-subscription
   prompt — the ntfy apps tie one login per *server*, applied to every
   topic on it), add a user: **Username** `daybreak-readers`,
   **Password** the real `daybreak-readers` account password (see
   below — **not** its API token; confirmed via real-device testing
   that the mobile apps' Basic Auth flow only accepts the actual
   account password, not a token substituted as the password. An
   earlier version of this doc claimed otherwise — wrong, fixed here).
4. Subscribe.

**Public topic** (`daybreak-critical-public`, unfiltered, no auth
needed): same steps, but skip step 3 entirely — no credentials to
enter.

To revoke a team member's access, change the shared account's password
(`sudo ntfy user change-pass daybreak-readers` — needs the value piped
twice, e.g. `printf '%s\n%s\n' "$NEWPASS" "$NEWPASS" | sudo ntfy user
change-pass daybreak-readers`, since the prompt reads it twice for
confirmation) and redistribute the new password to everyone who should
still have access. Since it's shared, this affects everyone at once —
a known tradeoff of the shared-credential model chosen over per-person
accounts, and the reason the original ask was "initially one single
token, later individual usernames/passwords."

## Current webhook configuration

Two `user_webhooks` rows, both `format='ntfy'`, both currently
`{"categories":["critical"]}` (no `terms` narrowing yet — see below):

| id | Topic | Access | Purpose |
|---|---|---|---|
| 1 | `daybreak-critical-COO` | `daybreak-readers` shared account (password, not token) | Company topic — will get the `terms` filter once the app/software list arrives |
| 2 | `daybreak-critical-public` | Anonymous/no-auth read (`ntfy access everyone daybreak-critical-public read-only`) | Public topic — stays unfiltered by design, every Critical/Patch Now article |

Both are published to **only** by the `daybreak-bot` write-only token
(granted write access to both topics) — the public topic's "no auth"
applies to reading, never to publishing; anonymous write would let
anyone spoof fake "critical" alerts into it.

**The `terms` filter (specific applications/products) for the company
topic (id=1) is not yet set** — the app/software list was still
pending as of this deployment. Adding it is a one-line SQL update to
`filter_json`, no code or redeploy needed (row 2, the public topic,
should stay `{"categories":["critical"]}` — no `terms` by design):

```sql
UPDATE user_webhooks
SET filter_json = '{"terms":["App A","App B"],"categories":["critical"]}'
WHERE id = 1;
```

### ntfy publish throttling

Every Critical/Patch Now article now fires two publishes (one per
topic) instead of one, which doubles pressure on the self-hosted ntfy
server's rate limit. Investigated and found the limit
(`visitor-request-limit-burst: 60`, replenished at 1 token/5s) is
**shared across the whole homelab's ntfy usage**, not per-integration —
ntfy sits behind Caddy without `behind-proxy` configured, so every
publisher's traffic is seen as coming from `127.0.0.1`. (Not fixed
here — a separate, bigger change to the already-hardened ntfy server,
outside this app's scope; flagged for a future decision.)

`WebhookService` now paces its own ntfy publishes at exactly the
replenish rate (1 per 5s, `NTFY_MIN_INTERVAL_S`) so daybreak alone can
never out-consume what's regenerating, leaving the burst allowance
free for other integrations. This is also the systemic fix for the
first-run backfill flood noted above (previously just suppressed
manually, not fixed at the cause).

## Push notification formatting

Beyond the base `ntfyPayload()` design (see the feature doc), the
following presentation tweaks were added after real-world testing:

- **Category-tag stripping**: some sources (Exploit-DB-style feeds)
  prefix titles with `[webapps]`/`[local]`/`[remote]`/etc. — stripped
  via a generic leading-bracket-tag regex, cosmetic-only (the stored
  article title and website display are unaffected).
- **CVSS extraction**: not a structured field on `articles`, but
  `NvdAdapter` and `GitHubAdvisoryAdapter` already embed it as text in
  the summary. A regex pulls it into a dedicated 🎯 line when present;
  sources without CVSS data get no line, silently.
- **Visible article link**: the article URL is appended as plain text
  in the body, in addition to the `Click` header (which makes tapping
  the notification open it directly) — a visible fallback for contexts
  where `Click` doesn't fire.
- **Priority**: `urgent` (red icon, bypasses most DND configs) for the
  `critical` category, `default` otherwise. Tag: 🚨 (`rotating_light`)
  vs 📰 (`newspaper`).

## Backups

`/root/bin/daybreak-backup.sh`, run via `daybreak-backup.timer`
(systemd oneshot, daily `04:00 Israel time`, per the standing
"systemd timer, not cron" convention) — same shape as
`mc-backup.sh`/`ntfy-backup.sh`:

- `mysqldump --single-transaction --quick` (not table-locking — the
  `daybreak_app` DB user has no `LOCK TABLES` grant, and this is the
  standard non-blocking approach for an all-InnoDB schema anyway) +
  a tarball of `config/.env` and this box's own scripts/units.
- GPG-encrypted to the existing shared public key
  (`A0AA01041D2C1467CBC9CC6751A6F94709393AB6`, imported from `ntfy`,
  ultimate trust — private key never touches this box, same as
  everywhere else).
- Uploaded via the **same rclone `Google-Drive:` remote/OAuth token
  already authorized on `ntfy`** — copied server-to-server (GPG public
  key + rclone config) rather than a fresh consent flow, since it's
  the same Google account and refresh tokens work fine used from
  multiple hosts simultaneously.
- MD5-verified post-upload (`rclone md5sum`, not `rclone check` — same
  single-file-path bug noted on the other boxes), 14-day remote
  retention (`rclone delete --min-age 14d`).
- Status recorded to `/var/lib/daybreak-backup-status`
  (`STATUS|timestamp|size|duration`, same shape as the other boxes).
- **Failure-only** ntfy alert, to the existing `backups` topic (the
  `daybreak-bot` token was granted write access there too, alongside
  its `daybreak-critical-COO` scope) — verified working via a real
  failure hit during setup (missing `LOCK TABLES` grant, fixed by
  switching to `--single-transaction`).

Deliberately **not** wired into a daily digest (the other boxes fold
backup status into `daily-digest.sh`, catching a silently-stopped timer
even without an explicit failure) — tracked as a follow-up in
[omerdvd/daybreak#2](https://github.com/omerdvd/daybreak/issues/2)
rather than built now, since this is a single-purpose box and a
digest integration is a bigger lift than felt justified at initial
setup.

## Outstanding / not yet done

- [ ] `terms` filter (specific app/software list) — pending from the
      user.
- [ ] Add `daybreak` to `tailscale-mesh-monitor.sh`'s peer list.
- [ ] Backup success visibility (digest or equivalent) —
      [omerdvd/daybreak#2](https://github.com/omerdvd/daybreak/issues/2).
