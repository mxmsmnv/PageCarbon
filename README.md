# PageCarbon

A ProcessWire module that tracks per-page resource usage and estimates the CO₂ emissions of every front-end request. Adds a **Setup → PageCarbon** page in the admin with live statistics, an hourly chart, and a ranked page table.

**Author:** [Maxim Alex](https://smnv.org)
**Version:** 1.5.0
**GitHub:** [mxmsmnv/PageCarbon](https://github.com/mxmsmnv/PageCarbon)

## Features

- Estimates CO₂ emissions per request using the Sustainable Web Design Model v4
- Rates each page **A / B / C / D** based on milligrams of CO₂
- Tracks response size, PHP execution time, and peak memory usage
- **WireCache buffer** — metrics accumulate in memory; batch INSERT to DB once per hour (zero per-request DB writes)
- **Bot sampling** — only 1-in-N bot requests are recorded; human requests always recorded in full
- **90-day raw retention** — raw rows are pruned automatically; historical data is preserved forever in a compact hourly aggregate table
- **Daily maintenance** runs automatically: aggregates raw data into `page_carbon_hourly`, then prunes old raw rows
- Hourly CO₂ bar chart for the last 24 hours (Chart.js with SVG fallback)
- All-time totals combine raw + aggregate tables seamlessly
- Top 50 pages table: CO₂ avg, range, exec time, response size, hits, rating, last seen
- Manual controls: Flush buffer, Run maintenance, Clear all data
- Storage info panel: raw row count, table size, retention window, last maintenance timestamp
- **DOCX export** — one-click formatted report via pure-PHP `PageCarbonDocx` (no Composer, no Node.js)
- **Frontend API** — `getStats($page)` and `renderBadge($page)` for use in templates

## Installation

1. Copy the `PageCarbon` folder to `/site/modules/`
2. In the ProcessWire admin: **Modules → Refresh → Install**
3. Two tables are created automatically:
   - `page_carbon` — raw request rows
   - `page_carbon_hourly` — permanent hourly aggregates
4. Go to **Setup → PageCarbon**, visit a few front-end pages, then press **Flush buffer** — data appears immediately

## Module files

```
PageCarbon/
├── PageCarbon.module.php   # Main module (extends Process)
├── PageCarbonConfig.php    # Config inputfields
├── PageCarbonDocx.php      # Zero-dependency DOCX report generator
├── CHANGELOG.md
└── README.md
```

## CO₂ formula

Based on **Sustainable Web Design Model v4** (Wholegrain Digital, 2024):

```
energy_kWh = (response_bytes × 0.00000000006)   // 0.06 kWh/GB — network + server + device
           + (exec_ms        × 0.000000003)       // CPU penalty  ≈ 3 W server
           + (peak_mem_MB    × 0.0000004)          // RAM penalty  — DRAM idle

CO₂_mg = energy_kWh × carbon_intensity × 1000
```

Default carbon intensity: **436 gCO₂eq/kWh** (world average). Adjust in module settings.

## Page rating

| Rating | CO₂ per request | Notes |
|--------|-----------------|-------|
| 🟢 **A** | < 100 mg | Excellent |
| 🟡 **B** | 100–300 mg | Good |
| 🟠 **C** | 300–700 mg | Needs improvement |
| 🔴 **D** | ≥ 700 mg | Heavy |

Reference: the average web page produces ~500 mg CO₂ per view (Website Carbon Calculator, 2024).

## Settings

| Setting | Default | Description |
|---------|---------|-------------|
| Enabled | true | Pause collection without dropping tables |
| Grid carbon intensity | 436 | gCO₂eq/kWh for your region. See electricitymaps.com |
| Raw data retention | 90 days | Raw rows older than this are deleted during maintenance |
| Bot sampling rate | 10 | Record 1 of every N bot requests (1 = record all) |
| Exclude templates | `search, sitemap` | Templates whose pages are skipped |

## Storage strategy

| Table | Content | Size estimate | Lifetime |
|-------|---------|---------------|----------|
| `page_carbon` | Raw request rows | ~9 MB / 1k req/day / 30 days | Pruned after retention window |
| `page_carbon_hourly` | Hourly averages per page | ~15 MB / year at 10k req/day | Permanent |

Bot sampling (default 1/10) reduces raw table volume significantly on high-traffic or heavily crawled sites.

## DOCX export

Click **Export DOCX** in the admin footer to download a formatted report. The report is generated entirely in PHP using `PageCarbonDocx.php` — a zero-dependency class that builds the `.docx` via `ZipArchive` (PHP built-in). No Composer, no Node.js required.

The report includes:

- Title block with site URL, date, and carbon intensity
- Last 24-hour summary table (requests, human/bot split, CO₂, rating, exec time, response size)
- All-time summary table (totals, collecting since, retention, intensity)
- Top 50 pages by CO₂ with range, time, size, and rating badge (new page)
- Rating scale reference table
- Header (site name + date, right-aligned) and footer (page X of Y)

## Frontend API

Use in templates to display per-page CO₂ data.

### `getStats(Page $page): ?array`

Returns stats from the raw table for the given page (human requests only). Returns `null` if no data.

Keys: `avg_co2_mg`, `min_co2_mg`, `max_co2_mg`, `avg_ms`, `avg_kb`, `hits`, `last_seen`, `rating` (A/B/C/D), `rating_color` (#hex).

```php
$pc    = $modules->get('PageCarbon');
$stats = $pc->getStats($page);
if($stats) {
    echo $stats['avg_co2_mg'] . ' mg CO₂  ·  Rating ' . $stats['rating'];
}
```

### `renderBadge(Page $page, string $style = 'full'): string`

Returns a ready-made HTML badge. Returns `''` if no data.

Styles: `full` (default), `compact`, `minimal`.

```php
$pc = $modules->get('PageCarbon');

echo $pc->renderBadge($page);             // full — card with all stats
echo $pc->renderBadge($page, 'compact');  // single-line pill
echo $pc->renderBadge($page, 'minimal'); // inline label only
```

## Bot detection

The following User-Agent fragments are treated as bots:

`bot`, `crawl`, `spider`, `slurp`, `facebookexternalhit`, `semrush`, `ahrefsbot`, `mj12bot`, `dotbot`, `yandex`, `bingpreview`, `ia_archiver`, `archive.org`, `bytespider`, `gptbot`, `anthropic`, `claudebot`, `google-extended`, `petalbot`, `dataforseobot`, `seznambot`

## Requirements

- ProcessWire ≥ 3.0.227
- PHP ≥ 8.1 with `ZipArchive` extension (enabled by default in most PHP builds)
- MySQL / MariaDB

## License

MIT
