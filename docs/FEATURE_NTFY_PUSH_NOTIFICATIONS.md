# Feature plan: ntfy push notifications for Critical/Patch Now alerts

Status: **planned, not yet implemented**. This doc captures the design and
deployment decisions made so far, to be picked up as an implementation task.

## Goal

Push notifications (via a self-hosted ntfy server) restricted to:

1. A specific application / product (matched by name, e.g. "Fortinet",
   "Citrix", "Exchange")
2. The `Critical / Patch Now` source category only

Delivered to a small group of team members' iPhones/Android devices via the
ntfy app. No public website is required — this fork exists purely to power
the push pipeline.

## Why this is a natural fit for the existing webhook system

Daybreak already has a generic outbound webhook system
(`user_webhooks` + `webhook_log`, dispatched from `AggregationService`
via `WebhookService::dispatch()` after every source fetch — see
`src/Service/WebhookService.php`). It already supports:

- Per-webhook `filter_json = {"terms":[...], "categories":[...], "sources":[...]}`,
  ANDed together.
- `categories` filters on `source_categories.slug` — `Critical / Patch Now`
  is already seeded as `slug='critical'` in `migrations/001_initial_schema.sql`.
- `terms` does a case-insensitive substring match against article
  title + summary — this is how "specific application" filtering will work,
  since articles have no structured product/tag field. A webhook with
  `{"terms":["Fortinet","FortiOS"],"categories":["critical"]}` already
  expresses "critical alerts about this app" with zero new matching logic.
- Pluggable payload format per destination
  (`slack` / `discord` / `teams` / `generic`), added incrementally
  (see migration `021_webhook_teams_format.sql` for the template to follow
  when adding `ntfy`).
- Delivery, retry (`retryFailed()`), and logging (`webhook_log`) are all
  format-agnostic already.
- Every existing payload builder links to `$item->url` — the **original**
  source article URL from the RSS/API adapter, never a Daybreak-hosted
  page. This is required (links must stay pointing at upstream sources)
  and is already true by construction — nothing to change here.

## What's actually new

1. **Migration**: add `'ntfy'` to `user_webhooks.format` ENUM
   (same shape as migration 021's `teams` addition).
2. **Non-JSON delivery path**: ntfy's publish API takes a raw text body
   plus headers (`Title`, `Priority`, `Tags`, `Click`, `Actions`) —
   not a JSON payload like the other four formats. `FetchClient::postJson()`
   needs a sibling method (or a headers-capable variant) for this.
3. **Auth for a private server**: the target ntfy server requires a
   Bearer token for protected topics. Options considered:
   - Embed the token in the publish URL (matches how Slack/Discord/Teams
     webhook secrets already live in `user_webhooks.url` — consistent,
     but plaintext-in-DB, same trust level as the other three formats).
   - Store it via the existing `CredentialVault` service (already used
     elsewhere, e.g. the Kioju API key) — better hygiene, since this
     token gates a personal push channel and the instance is otherwise
     unexposed.
   - **Decision: not yet finalized** — leaning towards `CredentialVault`
     given this instance won't be public-facing anyway, but open until
     implementation starts.
4. **Priority mapping**: `Critical / Patch Now` articles should map to
   ntfy `Priority: urgent` (5, phone-alert-worthy); everything else to
   default priority. Purely a payload-builder concern, not a filter
   change.
5. **UI**: add `ntfy` to `WebhookController::ALLOWED_FORMATS`, extend the
   webhooks settings view. The `terms` + `categories` filter builder
   already exists in that UI for the other formats — this is mostly reuse.

## Deployment model (decided)

This fork will **not** be published as a public website. Rationale:

- The push pipeline (`bin/fetch.php`, cron-driven) is fully decoupled from
  the web frontend (`public/index.php`) — cron does fetch → dedup →
  webhook dispatch with no HTTP listener involved at all.
- Article links in notifications already point at the original source,
  not at any Daybreak-hosted page (see above) — so there's no user-facing
  reason to expose a website.
- Team members only need the ntfy app + topic subscription (optionally
  with a read token) — they never touch Daybreak itself.
- The only Daybreak "user" needed is the owning account for the
  `user_webhooks` row (an FK requirement, not a UX requirement).

**Hosting**: a fresh Linode Nanode, Newark NJ region (closest to NY,
matching the existing Minecraft/Linode server's region), joined to the
existing Tailscale tailnet. The web UI (optional, admin-only convenience
for editing filters without SQL) stays **Tailscale-only**, never publicly
exposed — no public vhost, no ports 80/443 open on the Linode Cloud
Firewall. TLS is still provided via `tailscale cert` for the MagicDNS
hostname (same pattern as the existing self-hosted ntfy server), since
the app's CSP (`upgrade-insecure-requests`) and HSTS/cookie handling
assume HTTPS even over a private network.

Push delivery itself goes through the existing self-hosted ntfy server
(`ntfy.omeruthi.online`, Linode London 2) via internal Tailscale
publishing, using a new dedicated topic and a scoped write-only bot
token (matching the existing `aide-bot` / `mesh-monitor-bot` pattern),
not an admin-scoped token.

### Provisioning checklist for the new box

- Sudo user `omer`, timezone `Asia/Jerusalem`, Ubuntu 24.04 LTS.
- SSH key-only via 1Password (public key + Touch ID autologin); MFA model
  (1Password-only vs. stacked TOTP like the ntfy server) still open.
- Tailscale join, own identity, key expiry disabled.
- Dual-layer firewall: Linode Cloud Firewall + UFW, IPv4 **and** IPv6,
  default-deny inbound except SSH over Tailscale.
- fail2ban on `sshd`, ban notifications wired to ntfy.
- PHP 8.3 / MariaDB 10.11+ / Apache 2.4 stack (per README), MariaDB bound
  to localhost, least-privilege `daybreak_app` DB user, `config/.env`
  above the Apache docroot per the repo's own convention.
- `tailscale cert` for the MagicDNS hostname, weekly renewal cron.
- Cron: `bin/fetch.php` every 5 min, maintenance cron daily (per README).
- A health check watching that the fetch cron is actually succeeding
  (self-healing restart + alert), since a silent failure here defeats
  the point of a critical-alerting pipeline.
- SSH-login-notify via PAM, same as the other three homelab boxes.
- Daily encrypted DB + config backup (GPG + rclone to Google Drive),
  matching the existing `mc-backup.sh` / `ntfy-backup.sh` pattern.
- Lynis hardening pass, tracked over time (AIDE explicitly skipped for
  this box for now).
- Add the new node to the existing `tailscale-mesh-monitor.sh` peer list.

## Open decisions before implementation starts

- [ ] `ntfy` token storage: `CredentialVault` vs. URL-embedded.
- [ ] SSH MFA model on the new box: 1Password-only vs. stacked
      `pam_google_authenticator`.
- [ ] Final ntfy topic name.
- [ ] Whether the topic is public-read or per-teammate read tokens.
