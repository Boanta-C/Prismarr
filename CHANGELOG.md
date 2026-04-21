# Changelog

All notable changes to Prismarr are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

Work toward the first public release of Prismarr. Entries here will be rolled
into `[1.0.0]` at publication time.

### Added
- **Admin settings page** at `/admin/settings` — edit service URLs and API keys
  without replaying the setup wizard. Per-service "test connection" button,
  live status pill, show/hide toggle for each service in the sidebar, and
  show/hide toggle for internal features (Calendar). Two-column layout with
  sticky section nav, designed to host future preference sections.
- **Branded error pages** for 403/404/500/503 rendered with the Prismarr
  chrome (sidebar, theme) instead of the default Symfony exception page.
  Upstream exception message is never exposed — only the status code, a
  friendly French title, and a CTA back to home.
- Password show/hide toggle in the setup wizard (admin step + qBittorrent
  download step) for users typing long API keys on small screens.
- `/api/health` now returns `{status, db, timestamp}` (ISO 8601) so
  external monitoring dashboards can track liveness over time.
- OCI image labels on the production Docker image (title, description,
  licenses, source, url, documentation, vendor) — surfaced on Docker Hub
  and via `docker inspect`.
- Smoke test coverage on every controller (`ControllersSmokeTest` with
  DataProvider over 9 media routes + login + health).
- Initial Prismarr application forked from IH-Argos (April 2026).
- FrankenPHP 1.3.6 single-container deployment with s6-overlay supervising
  the web server and the Symfony Messenger worker.
- Zero-config SQLite database, automatic secret generation on first boot.
- 7-step setup wizard: welcome → admin → TMDb → managers (Radarr + Sonarr) →
  indexers (Prowlarr + Jellyseerr) → downloads (qBittorrent + Gluetun) → finish.
- Media integrations:
  - Radarr (~169 client methods, 143 routes, 37 templates)
  - Sonarr (~160 client methods, 142 routes, 30 templates)
  - Prowlarr (~70 methods, 15 templates)
  - Jellyseerr (~60 methods, 13 templates)
  - qBittorrent (~45 methods, VPN card, session card, magnet + torrent file upload)
  - TMDb discovery page (hero, recommendations, 7 scrollable sections, watchlist)
  - Integrated calendar with month grid, tooltips, per-type colours
- Hotkey global search (Ctrl+K) with debounced local + online (TMDb / TheTVDB) results.
- Quick-add modal (movies via Radarr, series via Sonarr) accessible from every page.
- Dynamic CSP header built from configured service URLs.
- Login rate-limiter (5 attempts per IP + username / 15 minutes, 25 per IP globally).
- Trusted proxies support for deployments behind Traefik / nginx / Caddy / Cloudflare Tunnel.
- `/api/health` endpoint (JSON status + DB ping) for Docker healthcheck.
- Profiler access guard that returns 403 for non-RFC1918 clients when `APP_ENV=dev`.
- Admin recovery command: `php bin/console app:user:reset-password <email>`.
- Dynamic welcome homepage: auto-redirect to the first configured service.
- Doctrine migrations baseline (replaces `doctrine:schema:create`).
- PHPUnit test suite (~100 tests, services + subscribers + controllers + entities + Twig extensions).
- `make check` target: PHP lint + Twig lint + full PHPUnit suite.

### Security
- Container runs as non-root (`www-data` via `s6-setuidgid`); s6-overlay keeps
  PID 1 as root only as required.
- SSRF protection on user-provided URLs: protocol whitelist, cloud-metadata
  blocklist, `CURLOPT_REDIR_PROTOCOLS`.
- XSS dead-code removal (`extra_fields|raw` removed from schema modal).
- CSRF tokens on every sensitive form.
- `#[IsGranted('ROLE_ADMIN')]` on the six controllers that manage external
  services (Radarr, Sonarr, Prowlarr, Jellyseerr, qBittorrent, Media).
- Login throttling via `symfony/rate-limiter`.
- Dev-only `_profiler` / `_wdt` routes return 403 for remote clients.
- `Strict-Transport-Security` and `Permissions-Policy` response headers
  emitted by Caddy (HSTS no-op on plain HTTP but picked up by an HTTPS
  reverse proxy that forwards response headers).
- Session cookie marked `httponly` explicitly (in addition to
  `secure: auto` + `samesite: lax`).
- `QBittorrentClient` now implements `ResetInterface`, preventing the
  qBittorrent session cookie from leaking across users when the
  FrankenPHP worker is re-used.

### Changed
- Migrated from a multi-container stack (PHP-FPM + nginx + Redis) to a single
  FrankenPHP container with filesystem cache and sessions.
- Retired `api-platform/core` and `lexik/jwt-authentication-bundle` — unused.
- Multi-stage-like Dockerfile: `.build-deps` purged after PHP extensions compile.
  `git` and `zip` also moved into `.build-deps` and purged after `composer install`.
- Composer version pinned (`composer:2` → `composer:2.8`) to avoid drift
  across rebuilds.
- Final image trimmed from 577 MB to 282 MB, then another ~10 MB after
  purging the Composer build-time deps.
- Settings live in the `setting` DB table, not in `.env.local` — managed by
  the wizard, persistent across container recreations.
- Home page chooses the first configured service (TMDb → Radarr → Sonarr → qBit
  → welcome fallback) instead of hardcoding `/decouverte`.
- Sidebar "Paramètres" link moved to the footer area next to logout (admin-only).
- "Modifier la configuration" banner button points to `/admin/settings` now
  rather than replaying the setup wizard.
- Session files moved from `var/sessions/` to `var/data/sessions/` so they
  persist inside the one Docker volume mounted in production and survive
  `docker compose up -d --force-recreate`.
- Gluetun HTTP timeout bumped from 4 s to 8 s (connect 2 s → 3 s) — the
  previous values were too aggressive on slow VPN handshakes.

### Fixed
- Media clients (Radarr, Sonarr, Prowlarr, Jellyseerr, qBittorrent, TMDb,
  Gluetun) implement `ResetInterface` so FrankenPHP worker instances
  reload the API key/URL between requests. Previously, an admin updating
  a service via `/admin/settings` had to wait for the worker to recycle
  (10–30 min) before the new value was picked up.
- `AdminSettings::save()` also clears `cache.app` so stale TMDb responses
  aren't served after a key change.
- `SetupController::guardAdminExists()` now returns `?RedirectResponse`
  and every call site uses the return value — previously the redirect
  was issued but the method kept running, potentially double-rendering
  the wizard step.
- `GluetunClient::reset()` now also zeroes the three cache timestamps
  (`publicIpCacheAt`, `statusCacheAt`, `portCacheAt`); previously reset
  would keep stale entries alive for the rest of the TTL.

### Contributor

- `CONTRIBUTING.md` adds a six-category "Definition of Done" checklist and
  five non-negotiable golden rules. `make check` must be green before every commit.
- New `tests/AbstractWebTestCase` base class boots a real kernel with an
  isolated SQLite file, seeds an admin + the `setup_completed` flag, and
  logs in the admin — foundation for functional tests that need a live
  request/response cycle.
- `make test` now passes `-e APP_ENV=test` to `docker exec`; previously
  the container's `APP_ENV=dev` was overriding the `APP_ENV` directive
  declared in `phpunit.dist.xml`.

## Template for future versions

<!-- Copy this block above [Unreleased] when cutting a release. -->

<!--
## [X.Y.Z] — YYYY-MM-DD

### Added
### Changed
### Deprecated
### Removed
### Fixed
### Security
### Contributor

[X.Y.Z]: https://github.com/joshuabv2005/prismarr/compare/vPREV...vX.Y.Z
-->
