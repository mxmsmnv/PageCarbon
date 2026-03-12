# Changelog

All notable changes to PageCarbon are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

**Author:** [Maxim Alex](https://smnv.org) · [GitHub](https://github.com/mxmsmnv/PageCarbon)

---

## [1.0.0] — 2026-03-12

Initial public release.

### Added

- CO₂ estimation per front-end request using Sustainable Web Design Model v4
- A / B / C / D page rating based on milligrams of CO₂ per request
- Metrics captured per request: response size (KB), PHP execution time (ms), peak memory (MB)
- **WireCache buffer** — metrics accumulate without touching the database; batch INSERT fires once per hour or when the buffer reaches 500 rows
- **Bot detection** — User-Agent matched against 21 known bot patterns; bots flagged with `is_bot` column
- **Bot sampling** — configurable 1-of-N recording rate for bots to limit table growth on crawled sites (default: 1/10)
- **Two-table storage strategy**:
  - `page_carbon` — raw request rows, retained for a configurable number of days (default: 90)
  - `page_carbon_hourly` — permanent hourly aggregates per page/bot flag, kept forever
- **Daily maintenance** job (runs automatically on hourly flush): aggregates raw rows older than 24 h into the hourly table, then deletes raw rows beyond the retention window
- Admin page under **Setup → PageCarbon** (Process module with auto-created admin page)
- Dashboard sections: Last 24 hours, All time (raw + aggregate), Storage info, Top 20 pages table
- Hourly CO₂ bar chart for the last 24 h — Chart.js with SVG bar fallback when Chart.js is unavailable
- Human / bot split shown in the 24 h request count card
- All-time totals merge raw and hourly aggregate tables for a continuous history
- Storage info panel: raw row count, table size, oldest raw record, retention window, last maintenance timestamp
- Manual controls: **Flush buffer**, **Run maintenance**, **Clear all data**
- Configurable settings: carbon intensity (gCO₂/kWh), raw retention days, bot sample rate, excluded templates, enabled toggle
- `TRUNCATE` + cache key cleanup on Clear all data
- Full WireCache key cleanup on module uninstall; both DB tables dropped on uninstall
