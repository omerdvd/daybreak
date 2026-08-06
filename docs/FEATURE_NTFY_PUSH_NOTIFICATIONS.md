# Feature: ntfy push notifications for Critical/Patch Now alerts

Status: **implemented and deployed**. This doc now records the design as
built; see [DEPLOYMENT_DAYBREAK_SERVER.md](DEPLOYMENT_DAYBREAK_SERVER.md)
for the actual server it runs on and operational details (cron cadence,
ntfy topic/token setup, team on-boarding, outstanding items).

## Goal

Push notifications (via a self-hosted ntfy server) restricted to:

1. A specific application / product (matched by name)
2. The `Critical / Patch Now` source category only

Delivered to a small group of team members' iPhones/Android devices via
the ntfy app. No public website — this fork exists purely to power the
push pipeline; see the deployment doc for the full "why".

## Design: reusing the existing webhook system

Daybreak already had a generic outbound webhook system
(`user_webhooks` + `webhook_log`, dispatched from `AggregationService`
via `WebhookService::dispatch()` after every source fetch). `ntfy` was
added as a fifth payload format alongside `slack`/`discord`/`teams`/
`generic`, reusing everything except payload shape and delivery
mechanics:

- **Filter matching is unchanged.** `filter_json = {"terms":[...],
  "categories":[...], "sources":[...]}` already expressed exactly what
  was needed — `{"terms":["Fortinet","FortiOS"],"categories":
  ["critical"]}` filters to "critical alerts about this app" with zero
  new matching logic. `Critical / Patch Now` is `source_categories.slug
  = 'critical'`, seeded since the initial schema.
- **Delivery, retry, and logging are unchanged.** `deliverRaw()`/
  `attemptDeliveryRaw()` mirror the existing `deliver()`/
  `attemptDelivery()` pair; `retryFailed()` grew an `ntfy` branch using
  the same 24h/attempt=1 retry window as every other format.
- **Article links are unchanged** — every payload builder, `ntfy`
  included, links to `$item->url` (the original source article), never
  a Daybreak-hosted page.

### What's actually new

1. **Migration `022_webhook_ntfy_format.sql`**: adds `'ntfy'` to
   `user_webhooks.format`, plus a nullable `secret_enc` column.
2. **Non-JSON delivery path**: ntfy's publish API takes a raw text
   body plus headers (`Title`, `Priority`, `Tags`, `Click`), not a JSON
   envelope. `FetchClient` grew a `post(string $url, string $body,
   array $headers)` method (implemented in `FeedFetcher` and
   `FakeFetchClient`) alongside the existing `postJson()`, which stays
   as-is for the other four formats.
3. **Token storage: `CredentialVault`**, not URL-embedded. Decided in
   favor of encryption (AES-256-GCM, keyed off `APP_KEY`, the same
   service already used for the Kioju API key) over the simpler
   URL-embedded approach the other three formats use, since this token
   gates a personal push channel on an otherwise-unexposed instance —
   worth the slightly higher bar.
   `WebhookController::buildSecretEnc()` handles encrypt-on-set,
   preserve-on-blank-resubmit (the edit form never re-displays the
   token), and clear-on-format-switch.
4. **Priority mapping**: `urgent` (🚨 `rotating_light` tag) for the
   `critical` category, `default` (📰 `newspaper` tag) otherwise.
5. **UI**: `ntfy` in `WebhookController::ALLOWED_FORMATS` and the
   settings view — a token field (type=password, optional) alongside
   the existing terms/categories/sources filter builder, which needed
   no changes since it already supported everything required.

## Push notification formatting

Beyond the base payload, three refinements were added after real-device
testing surfaced them:

- **CRLF header injection guard**: `Title`/`Click` header values are
  sanitized (`sanitizeHeaderValue()`) since they ultimately originate
  from external, untrusted RSS/API feeds — stripped `\r`/`\n` before
  ever reaching an HTTP header.
- **Category-tag stripping**: some sources (Exploit-DB-style feeds)
  prefix titles with `[webapps]`/`[local]`/`[remote]`/etc.
  `stripCategoryTag()` removes any leading `[tag]` via a generic regex
  (not an enumerated list, so it also covers tags not seen yet) —
  cosmetic, notification-only; the stored article title and website
  display are untouched.
- **CVSS score extraction**: not a structured field on `articles`, but
  `NvdAdapter` and `GitHubAdvisoryAdapter` already embed it as text in
  the summary (`"CRITICAL (9.8) — ..."`, `"CVSS 9.8 · ..."`).
  `extractCvssLine()` pulls it into its own 🎯 line via regex when
  present; sources without CVSS data (CISA KEV, plain RSS) simply get
  no line, silently — "if possible," not a hard requirement.
- **Visible article link**: the URL is also appended as plain body
  text, not just the `Click` header (which opens it on tap) — a
  visible fallback for contexts where `Click` doesn't fire, and
  restructured so it (and the CVSS line) always survive truncation of
  the long-form description rather than only fitting if there's room.

## Deployment model (as built)

Confirmed as planned: this fork is **not** a public website. Full
provisioning record — hosting choice, network/firewall layers, SSH/MFA
setup, LAMP stack, TLS, cron, ntfy topic/token setup, team on-boarding
steps — lives in
[DEPLOYMENT_DAYBREAK_SERVER.md](DEPLOYMENT_DAYBREAK_SERVER.md).

## Resolved decisions (were open, now settled)

- [x] ntfy token storage → `CredentialVault`.
- [x] SSH MFA model → stacked TOTP (`pam_google_authenticator` +
      publickey), not 1Password-only — matches the ntfy/Minecraft
      boxes' server-side-second-factor bar.
- [x] Topic name → `daybreak-critical`.
- [x] Topic read-access model → one shared read-only token
      (`daybreak-readers`) for the whole team, not per-teammate tokens.

## Status: complete

Everything in the original checklist is done, including the `terms`
filter (the last open item) — see [Issue #1](https://github.com/omerdvd/daybreak/issues/1)
for the full checklist and the closing comment for how the term list
was built. The list itself isn't committed here: it's derived from a
private internal asset inventory and lives only in the webhook's
`filter_json` server-side, not in this repo.
