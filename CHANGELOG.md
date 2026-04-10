# Changelog

All notable changes to PageCarbon are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

**Author:** [Maxim Alex](https://smnv.org) · [GitHub](https://github.com/mxmsmnv/PageCarbon)

---

## [1.6.0] — 2026-04-09

### Added

- **Real-world analogies panel** — all-time CO₂ total rendered as 12 everyday equivalents displayed as a `uk-card` grid (4 per row, 2 on mobile); analogies: car km, espressos, kettles boiled, phone charges, Netflix HD hours, emails sent, trees needed for 1 year, LED bulb hours, subway trips, songs streamed on Spotify, short-haul flights, Google searches
- Each analogy card uses an inline **Heroicons** (outline) or **Bootstrap Icons** SVG, coloured via `--pw-main-color`

### Changed

- Admin dashboard fully migrated to **AdminThemeUikit** native components: `uk-card`, `uk-grid`, `uk-child-width-*`, `uk-table`, `uk-button`, `uk-overflow-auto`, `uk-flex`, `uk-text-meta`, `uk-text-uppercase` — custom `#pcf-wrap` CSS grid removed
- Stat cards now have fixed `height: 80px` with `display:flex` centering — font size no longer affects row height
- **Chart.js** loaded on demand via dynamic `<script>` injection (cdn.jsdelivr.net); falls back to inline SVG bars if network unavailable; `animation: false` replaced with `{ duration: 500, easing: 'easeOutQuart' }`
- Bar chart colours read from `--pw-main-color` CSS custom property (hex parsed to RGB at runtime); SVG fallback uses the same colour
- Footer buttons: `uk-button-danger` applied to Clear all data; Export DOCX uses `uk-button-primary`; all buttons sized `uk-button-small`

---

## [1.5.0] — 2026-03-12

### Added

- **DOCX export** — one-click formatted report via `PageCarbonDocx` class (pure PHP, zero dependencies, `ZipArchive` only); report includes title block, 24 h summary, all-time summary, top 50 pages table, and rating scale; header + footer with page numbers
- **Frontend API**: `getStats(Page $page): ?array` — returns CO₂ stats (avg, min, max, exec time, response size, hits, rating, rating color) for a page from the raw table; human requests only; returns null if no data
- **Frontend API**: `renderBadge(Page $page, string $style): string` — returns ready-made HTML CO₂ badge in three styles (`full`, `compact`, `minimal`); returns empty string if no data
- `PageCarbonDocx.php` — standalone class; can be used independently via `buildFile()` / `download()` methods
- Admin **Export DOCX** button in the dashboard footer

### Changed

- Top pages list increased from 20 to **50** rows — both admin dashboard and DOCX report
- Footer buttons: removed `uk-button-small`, now rendered at standard UIkit button size
- Admin page name changed from `carbon-footprint` to `carbon` (URL: Setup → Carbon)
- All "Carbon Footprint" labels in the UI replaced with **PageCarbon**

---

## [1.0.0] — 2026-03-12

Initial public release.

### Added

- **Frontend API**: `getStats(Page $page): ?array` — returns CO₂ stats (avg, min, max, exec time, response size, hits, rating, rating color) for a page from the raw table; human requests only; returns null if no data
- **Frontend API**: `renderBadge(Page $page, string $style): string` — returns ready-made HTML CO₂ badge in three styles (`full`, `compact`, `minimal`); returns empty string if no data
- **DOCX export** — report via `PageCarbonDocx` class
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
- Dashboard sections: Last 24 hours, All time (raw + aggregate), Storage info, Top 50 pages table
- Hourly CO₂ bar chart for the last 24 h — Chart.js with SVG bar fallback when Chart.js is unavailable
- Human / bot split shown in the 24 h request count card
- All-time totals merge raw and hourly aggregate tables for a continuous history
- Storage info panel: raw row count, table size, oldest raw record, retention window, last maintenance timestamp
- Manual controls: **Flush buffer**, **Run maintenance**, **Clear all data**
- Configurable settings: carbon intensity (gCO₂/kWh), raw retention days, bot sample rate, excluded templates, enabled toggle
- `TRUNCATE` + cache key cleanup on Clear all data
- Full WireCache key cleanup on module uninstall; both DB tables dropped on uninstall