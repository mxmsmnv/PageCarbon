# PageCarbon

A ProcessWire module that tracks per-page resource usage and estimates the CO₂ emissions of every front-end request. Adds a **Setup → PageCarbon** page in the admin with live statistics, an hourly chart, and a ranked page table.

**Author:** [Maxim Alex](https://smnv.org)
**Version:** 1.0.0
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
- Top 20 pages table: CO₂ avg, range, exec time, response size, hits, rating, last seen
- Manual controls: Flush buffer, Run maintenance, Clear all data
- Storage info panel: raw row count, table size, retention window, last maintenance timestamp

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

## Bot detection

The following User-Agent fragments are treated as bots:

`bot`, `crawl`, `spider`, `slurp`, `facebookexternalhit`, `semrush`, `ahrefsbot`, `mj12bot`, `dotbot`, `yandex`, `bingpreview`, `ia_archiver`, `archive.org`, `bytespider`, `gptbot`, `anthropic`, `claudebot`, `google-extended`, `petalbot`, `dataforseobot`, `seznambot`

## Requirements

- ProcessWire ≥ 3.0.227
- PHP ≥ 8.1
- MySQL / MariaDB

## License

MIT
