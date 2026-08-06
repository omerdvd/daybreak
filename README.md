# Daybreak

[![PHP](https://img.shields.io/badge/PHP-8.3-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![MariaDB](https://img.shields.io/badge/MariaDB-10.11%2B-003545?logo=mariadb&logoColor=white)](https://mariadb.org/)
[![Apache](https://img.shields.io/badge/Apache-2.4-D22128?logo=apache&logoColor=white)](https://httpd.apache.org/)
[![Tests](https://img.shields.io/badge/tests-native%20suite-2ea44f)](tests/run.php)
[![CI](https://github.com/omerdvd/daybreak/actions/workflows/tests.yml/badge.svg)](https://github.com/omerdvd/daybreak/actions/workflows/tests.yml)

Daybreak is a self-hosted security news aggregator for fast daily monitoring.
It combines curated security headlines, ransomware activity, and CVE updates in
one server-rendered dashboard.

## At A Glance

| Item | Value |
|---|---|
| Stack | PHP 8.3, MariaDB 10.11+, Apache 2.4 |
| Rendering model | Server-rendered templates (no SPA build pipeline) |
| Public pages | Feed, Sources analytics, legal pages |
| Authenticated pages | Personal feed, source preferences, account settings, source suggestions |
| Admin pages | Source management, moderation queue, feed health, user admin, audit log |
| Adapters | `rss_atom`, `json_api`, `ransomlook`, `nvd` |

## Quick Links

- Specification: `docs/SPEC.md`
- Implementation plan: `docs/IMPLEMENTATION.md`
- Apache vhost example: `deploy/apache-vhost.conf`
- Test runner: `tests/run.php`

## Core Features

### Public experience

- Main feed with category and time-window filtering
- Sources analytics page
- Ransomware activity widget
- CVE widget with severity cues
- Legal pages (Imprint, Terms, Privacy)

### User experience

- Registration and email verification
- Login, password reset, account settings
- Source preference management
- Personalized feed behavior
- Source suggestion submission

### Admin experience

- Source CRUD and fetch controls
- Suggestion moderation queue
- Feed health and operations dashboard
- User administration and audit log

## Security Highlights

- Prepared statements for database access
- CSRF checks on state-changing requests
- SSRF-safe outbound fetching via guarded fetcher
- Sanitized feed summaries and escaped output
- Strict security headers on responses

## Quick Start

```bash
cp config/.env.example config/.env
# then edit DB and SMTP settings in config/.env

# create DB and least-privileged DB user first, then:
php migrations/run.php
php bin/fetch.php --force
php bin/prune.php

# configure Apache DocumentRoot to public/
# see deploy/apache-vhost.conf
```

## Runtime Commands

Initial/Manual fetch:

```bash
php bin/fetch.php --force
```

Manual maintenance prune:

```bash
php bin/prune.php
```

Cron (every 5 minutes):

```cron
*/5 * * * * php /srv/vhosts/daybreak.silverday.de/bin/fetch.php
```

Maintenance cron (daily at 03:00):

```cron
0 3 * * * php /srv/vhosts/daybreak.silverday.de/bin/prune.php
```

Run tests:

```bash
php tests/run.php
```

## CI Notes

GitHub Actions runs:

- `php tests/run.php`
- `php -l` for `bin/`, `public/`, `src/`, and `tests/`

## Attribution

Ransomlook data is sourced from ransomlook.io and attributed in the UI under
CC BY 4.0.
