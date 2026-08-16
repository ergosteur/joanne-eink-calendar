# LibreJoanne — Architecture

Design intent behind the code. For setup and usage see [README.md](../README.md); for
working conventions see [CLAUDE.md](../CLAUDE.md).

LibreJoanne is a "lite" reimplementation of core meeting-room signage and personal
scheduling, targeted at the Visionect / Joan e-ink ecosystem.

## Mission

A distraction-free, high-contrast, bilingual interface for 6-inch 1024x768 e-ink
displays. The application prioritises high-visibility typography, refresh-friendly
motion (pagination rather than scrolling), and low bandwidth and processing overhead.

The display is the constraint that drives most decisions. A Visionect panel is
battery-powered and repaints by diffing the rendered page, so anything that changes
pixels — a ticking clock, an animation, a reordered list — costs battery. Prefer
static renders that change only when the underlying data changes.

## Architecture philosophy

- **Dynamic multi-tenancy.** Centralised room and user management in SQLite, so one
  deployment serves many devices with different configurations.
- **Role-based access control.** Multiple admin accounts plus standard users. Admins
  manage the whole system; standard users manage only their own calendars and
  security settings.
- **Privacy first.** Calendar URLs are stored encrypted (AES-256-CBC). Personal
  schedules sit behind unique, non-sequential access tokens.
- **Hardened by default.** SSRF validation on every outbound fetch; salted cache
  filenames so artifacts cannot be guessed from a known feed URL; a dashboard
  self-check that verifies `data/cache/` is not web-reachable; automatic fallback to
  `config.sample.php` so a fresh deployment still boots.
- **Contextual UI.** Room and dashboard views share one split-screen layout with
  context-aware typography, showing end times and durations. Global 12h/24h
  formatting with language-aware defaults. Rooms and users can override the "Now"
  status label.
- **Deep diagnostics.** Native Visionect telemetry (IP, battery, signal) via request
  headers and the `okular` JS object, plus a demo mode that reports dummy values when
  the `demo.ics.php` feed is active.
- **Resilient navigation.** Server-side caching plus client-side state lets a device
  browse configurable past and future horizons. `?userid=` implies personal context,
  which keeps deployed URLs short.
- **Zero-cache strategy.** Dynamic pages and API endpoints send strict `no-cache`
  headers so a CDN or browser never serves a stale render to a panel.
- **Automated cache management.** All caches live in `web/data/cache/` and are
  invalidated whenever configuration changes through the dashboard.

## Key subsystems

| Subsystem | File | Responsibility |
| --- | --- | --- |
| Parser | `web/app/calendar.php` | Merges multiple iCal feeds, handles DATE-only holidays and line folding, and can execute a local PHP template for generated events |
| Weather | `web/app/weather.php` | Open-Meteo backend with 8-day forecasting and strict input sanitisation |
| Geocoding | `web/app/geocoding.php` | City search proxy used by the dashboard's location picker |
| Aggregator | `web/app/rss.php` | Language-aware RSS/Atom aggregator serving shuffled, paged headlines |
| Dashboard | `web/app/manage.php` | Unified management UI for rooms, users, horizons and encrypted feeds, with login and RBAC |
| Data layer | `web/lib/db.php` | SQLite access, schema self-healing, encryption, and the shared SSRF gate |

## Roadmap

- **Interactive occupancy** — touch-based room check-ins and auto-release logic.
- **Google Workspace API** — direct integration for private resources and native booking.
- **Power optimisation** — scheduled deep-sleep states to extend battery and panel life.
