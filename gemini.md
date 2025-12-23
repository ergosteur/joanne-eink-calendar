# LibreJoanne (AI Context)

LibreJoanne is a "lite" reimplementation of core meeting room signage and personal scheduling features, specifically targeted at the Visionect / e-ink ecosystem.

## Project Mission
To provide a distraction-free, high-contrast, and bilingual interface for 6-inch 1024x768 e-ink displays. The application prioritizes high-visibility typography, minimal refresh-friendly animations (pagination vs. scrolling), and low-bandwidth/low-processing overhead.

## Architecture Philosophy
- **Dynamic Multi-Tenancy**: Centralized room and user management via a SQLite database, allowing a single deployment to serve multiple devices with diverse configurations.
- **Role-Based Access Control (RBAC)**: Supports multiple admin accounts and standard users. Admins manage the entire system (rooms/users), while standard users can log in to manage their own personal calendars and security settings.
- **Privacy First**: All calendar URLs are stored encrypted (AES-256-CBC). Personal schedules are protected behind unique access tokens.
- **Hardened Security**:
    - **SSRF Protection**: Strict URL validation for all external feeds (iCal/RSS).
    - **Predictive Defense**: Salted cache filenames to prevent unauthorized artifact guessing.
    - **Self-Diagnostics**: Automated dashboard check to verify server-side directory protection for the `data/cache/` subdirectory.
    - **Configuration Fallback**: Graceful automatic fallback to `config.sample.php` to ensure a working initial state for new deployments.
- **Contextual UI**: Unified Room and Dashboard views into a shared split-screen layout while maintaining context-aware typography. Displays enriched event data including end times and durations. Supports global 12h/24h time formatting with language-aware defaults. Supports personalized "Now" status labels via a "Status Label" (display_name) override for both rooms and users.
- **Deep Diagnostics**: Native support for Visionect telemetry (IP, Battery, Signal) via headers and the `okular` JS object. Includes a **Demo Mode** that provides dummy values (69%) when the `demo.ics.php` calendar is active.
- **Resilient Navigation**: Server-side caching combined with client-side state tracking allows users to browse up to 30 days of history and future schedules. Access tokens (`?userid=`) automatically trigger personal context, simplifying URL deployment.
- **Zero-Cache Strategy**: Core dynamic pages and API endpoints enforce strict `no-cache` headers to prevent stale data served by CDNs (Cloudflare) or browsers.
- **Automated Cache Management**: Centralized cache storage in `web/data/cache/`. The system automatically invalidates and refreshes local data caches whenever configurations are changed via the dashboard.

## Key Subsystems
- **Parser**: A robust iCal parser (`web/app/calendar.php`) that merges multiple feeds, handles DATE-only holidays, and corrects line folding. Supports internal execution of local PHP templates (e.g., `demo.ics.php`) for dynamic event generation.
- **Weather Engine**: A custom local weather backend (`web/app/weather.php`) using Open-Meteo with 8-day forecasting and integrated city searching. Includes strict input sanitization.
- **Aggregator**: A language-aware RSS aggregator (`web/app/rss.php`) serving shuffled, paged headlines.
- **Management Dashboard**: A unified UI (`web/app/manage.php`) for managing rooms, users, horizons, and encrypted feeds with integrated login and RBAC.

## Roadmap & Expansion
- **Interactive Occupancy**: Touch-based room check-ins and auto-release logic.
- **Google Workspace API**: Direct integration for private resources and native booking.
- **Power Optimization**: Implementation of scheduled "Deep Sleep" states to extend e-ink lifespan.
