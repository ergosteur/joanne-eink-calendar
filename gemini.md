# LibreJoanne (AI Context)

LibreJoanne is a "lite" reimplementation of core meeting room signage and personal scheduling features, specifically targeted at the Visionect / e-ink ecosystem.

## Project Mission
To provide a distraction-free, high-contrast, and bilingual interface for 6-inch 1024x768 e-ink displays. The application prioritizes high-visibility typography, minimal refresh-friendly animations (pagination vs. scrolling), and low-bandwidth/low-processing overhead.

## Architecture Philosophy
- **Dynamic Multi-Tenancy**: Centralized room and user management via a SQLite database, allowing a single deployment to serve multiple devices with diverse configurations.
- **Privacy First**: All calendar URLs are stored encrypted (AES-256-CBC). Personal schedules are protected behind unique access tokens.
- **Contextual UI**: Adaptive terminology ("Meeting" vs "Event") and layouts (Room, Dashboard, Grid) based on the target audience.
- **Deep Diagnostics**: Native support for Visionect telemetry (IP, Battery, Signal) via headers and the `okular` JS object.
- **Resilient Navigation**: Server-side caching combined with client-side state tracking allows users to browse up to 30 days of history and future schedules.

## Key Subsystems
- **Parser**: A robust iCal parser (`web/app/calendar.php`) that merges multiple feeds, handles DATE-only holidays, and corrects line folding and timezone shifts.
- **Weather Engine**: A custom local weather backend (`web/app/weather.php`) using Open-Meteo with 8-day forecasting and integrated city searching.
- **Aggregator**: A language-aware RSS aggregator (`web/app/rss.php`) serving shuffled, paged headlines.
- **Management Dashboard**: A unified UI (`web/app/manage.php`) for managing rooms, users, horizons, and encrypted feeds.

## Roadmap & Expansion
- **Interactive Occupancy**: Touch-based room check-ins and auto-release logic.
- **Google Workspace API**: Direct integration for private resources and native booking.
- **Power Optimization**: Implementation of scheduled "Deep Sleep" states to extend e-ink lifespan.
