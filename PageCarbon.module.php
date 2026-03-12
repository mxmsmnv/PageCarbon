<?php

/**
 * PageCarbon
 *
 * Tracks per-page resource usage and estimates CO₂ emissions.
 * Adds a "Carbon Footprint" page under Setup in the PW admin.
 *
 * Storage strategy:
 *   - Raw rows kept 90 days (configurable retention), then deleted
 *   - Daily aggregation job compresses raw data into hourly averages
 *     stored in `page_carbon_hourly` (kept forever)
 *   - Bot sampling: only 1-in-N bot requests are recorded (configurable)
 *   - WireCache buffer: batch INSERT to DB every hour (or 500 rows max)
 *
 * @author  Maxim Alex <maxim@smnv.org> (smnv.org)
 * @link    https://github.com/mxmsmnv/PageCarbon
 * @version 1.0.0
 */
class PageCarbon extends Process implements Module, ConfigurableModule {

	// ── Module info ──────────────────────────────────────────────────────────

	public static function getModuleInfo(): array {
		return [
			'title'    => 'PageCarbon',
			'version'  => 100,
			'summary'  => 'Tracks per-page CO₂ emissions. WireCache buffer, bot sampling, 90-day raw retention with permanent hourly aggregates.',
			'author'   => 'Maxim Alex',
			'href'     => 'https://github.com/mxmsmnv/PageCarbon',
			'icon'     => 'leaf',
			'autoload' => true,
			'singular' => true,
			'requires' => ['ProcessWire>=3.0.227', 'PHP>=8.1'],
			'page'     => [
				'name'   => 'carbon-footprint',
				'parent' => 'setup',
				'title'  => 'PageCarbon',
			],
		];
	}

	// ── Constants ─────────────────────────────────────────────────────────────

	/** Raw data table — rows older than retention days are deleted */
	const TABLE         = 'page_carbon';

	/** Hourly aggregate table — permanent, compact */
	const TABLE_HOURLY  = 'page_carbon_hourly';

	const DEFAULT_CARBON_INTENSITY = 436;
	const ENERGY_PER_BYTE          = 0.00000000006;

	const CACHE_BUFFER_KEY  = 'PageCarbon_buffer';
	const CACHE_FLUSH_TS    = 'PageCarbon_last_flush';
	const CACHE_MAINT_TS    = 'PageCarbon_last_maint';

	/** Seconds between batch INSERTs (1 hour) */
	const FLUSH_INTERVAL    = 3600;

	/** Flush early when buffer reaches this many rows */
	const FLUSH_MAX_ROWS    = 500;

	/** Run maintenance (aggregation + pruning) at most once per day */
	const MAINT_INTERVAL    = 86400;

	// ── Known bot UA fragments ────────────────────────────────────────────────

	const BOT_PATTERNS = [
		'bot', 'crawl', 'spider', 'slurp', 'facebookexternalhit',
		'semrush', 'ahrefsbot', 'mj12bot', 'dotbot', 'yandex',
		'bingpreview', 'ia_archiver', 'archive.org', 'bytespider',
		'gptbot', 'anthropic', 'claudebot', 'google-extended',
		'petalbot', 'dataforseobot', 'seznambot',
	];

	// ── Internal state ────────────────────────────────────────────────────────

	protected float $startTime   = 0;
	protected int   $startMemory = 0;

	// ── Lifecycle ─────────────────────────────────────────────────────────────

	public function init(): void {
		parent::init();

		$this->startTime   = defined('PROCESSWIRE_BOOT_TIME') ? (float) PROCESSWIRE_BOOT_TIME : microtime(true);
		$this->startMemory = memory_get_usage(true);

		$this->addHookAfter('Page::render', $this, 'hookPageRender');
	}

	// ── Process::execute ─────────────────────────────────────────────────────

	public function ___execute(): string {
		$this->headline('PageCarbon');
		$this->browserTitle('PageCarbon');

		$input = $this->wire('input');
		$cache = $this->wire('cache');
		$db    = $this->wire('database');

		// Clear all data
		if($input->post('pcf_clear') === '1') {
			$db->exec("TRUNCATE TABLE `" . self::TABLE . "`");
			$db->exec("TRUNCATE TABLE `" . self::TABLE_HOURLY . "`");
			$cache->delete(self::CACHE_BUFFER_KEY);
			$cache->delete(self::CACHE_FLUSH_TS);
			$cache->delete(self::CACHE_MAINT_TS);
			$this->wire('session')->message('All Carbon Footprint data cleared.');
			$this->wire('session')->redirect('./');
		}

		// Manual buffer flush
		if($input->post('pcf_flush') === '1') {
			$raw    = $cache->get(self::CACHE_BUFFER_KEY);
			$buffer = $raw ? json_decode($raw, true) : [];
			if(!empty($buffer)) {
				$this->flushBuffer($buffer);
				$cache->save(self::CACHE_BUFFER_KEY, json_encode([]), WireCache::expireNever);
				$cache->save(self::CACHE_FLUSH_TS, (string) time(), WireCache::expireNever);
				$this->wire('session')->message(count($buffer) . ' buffered records flushed to the database.');
			} else {
				$this->wire('session')->message('Buffer is empty — nothing to flush.');
			}
			$this->wire('session')->redirect('./');
		}

		// Manual maintenance run
		if($input->post('pcf_maint') === '1') {
			$this->runMaintenance(true);
			$this->wire('session')->message('Maintenance complete: aggregation + pruning done.');
			$this->wire('session')->redirect('./');
		}

		try {
			// ── Last 24 h — from raw table ────────────────────────────────────
			$last24 = $db->query("
				SELECT
					COUNT(*)                          AS total_requests,
					SUM(CASE WHEN is_bot=1 THEN 1 ELSE 0 END) AS bot_requests,
					ROUND(SUM(co2_mg) / 1000, 4)     AS total_co2_g,
					ROUND(AVG(co2_mg), 2)              AS avg_co2_mg,
					ROUND(AVG(exec_ms), 1)             AS avg_ms,
					ROUND(AVG(response_kb), 1)         AS avg_kb
				FROM `" . self::TABLE . "`
				WHERE created >= NOW() - INTERVAL 24 HOUR
			")->fetch(\PDO::FETCH_ASSOC);

			// ── All-time totals — combine raw + hourly aggregate ──────────────
			$alltime = $db->query("
				SELECT
					SUM(requests)                         AS total_requests,
					ROUND(SUM(co2_mg_sum) / 1000000, 6)  AS total_co2_kg,
					ROUND(SUM(co2_mg_sum) / NULLIF(SUM(requests), 0), 2) AS avg_co2_mg,
					MIN(hour_start)                       AS since
				FROM `" . self::TABLE_HOURLY . "`
			")->fetch(\PDO::FETCH_ASSOC);

			// Supplement all-time with raw rows not yet aggregated
			$rawExtra = $db->query("
				SELECT
					COUNT(*)                          AS cnt,
					ROUND(SUM(co2_mg) / 1000000, 10) AS co2_kg_raw,
					ROUND(SUM(co2_mg), 4)             AS co2_mg_sum,
					MIN(created)                      AS since_raw
				FROM `" . self::TABLE . "`
			")->fetch(\PDO::FETCH_ASSOC);

			$totalRequests = (int)$alltime['total_requests'] + (int)$rawExtra['cnt'];
			$totalCO2kg    = round((float)$alltime['total_co2_kg'] + (float)$rawExtra['co2_kg_raw'], 6);
			$avgCO2mg      = $totalRequests
				? round(((float)$alltime['avg_co2_mg'] * (int)$alltime['total_requests']
				       + (float)$rawExtra['co2_mg_sum'])
				       / $totalRequests, 2)
				: 0;
			$since = min(
				array_filter([$alltime['since'], $rawExtra['since_raw']])
			) ?: null;

			// ── Top 20 pages — from raw table (recent 90 days) ───────────────
			$retention = max(1, (int) ($this->get('retention_days') ?: 90));
			$pages = $db->query("
				SELECT
					page_path, page_title,
					COUNT(*)                  AS hits,
					ROUND(AVG(co2_mg), 2)     AS avg_co2,
					ROUND(MIN(co2_mg), 2)     AS min_co2,
					ROUND(MAX(co2_mg), 2)     AS max_co2,
					ROUND(AVG(exec_ms), 1)    AS avg_ms,
					ROUND(AVG(response_kb),1) AS avg_kb,
					MAX(created)              AS last_seen
				FROM `" . self::TABLE . "`
				WHERE is_bot = 0
				  AND created >= NOW() - INTERVAL {$retention} DAY
				GROUP BY page_path, page_title
				ORDER BY avg_co2 DESC
				LIMIT 20
			")->fetchAll(\PDO::FETCH_ASSOC);

			// ── Hourly trend — last 24 h, raw table ──────────────────────────
			$trend = $db->query("
				SELECT
					DATE_FORMAT(created, '%H:00')    AS hour,
					ROUND(SUM(co2_mg) / 1000, 4)    AS co2_g,
					COUNT(*)                          AS hits
				FROM `" . self::TABLE . "`
				WHERE created >= NOW() - INTERVAL 24 HOUR
				GROUP BY DATE_FORMAT(created, '%Y-%m-%d %H')
				ORDER BY created ASC
			")->fetchAll(\PDO::FETCH_ASSOC);

			// ── Raw table stats ───────────────────────────────────────────────
			$rawStats = $db->query("
				SELECT
					COUNT(*)                 AS row_count,
					MIN(created)             AS oldest,
					ROUND(
						data_length + index_length, 0
					) AS bytes
				FROM information_schema.TABLES, `" . self::TABLE . "`
				WHERE table_schema = DATABASE()
				  AND table_name   = '" . self::TABLE . "'
				GROUP BY table_schema
				LIMIT 1
			")->fetch(\PDO::FETCH_ASSOC);

		} catch(\Exception $e) {
			return '<div class="uk-alert uk-alert-danger" uk-alert><p>Database error: ' . htmlspecialchars($e->getMessage()) . '</p></div>';
		}

		$noData = !$totalRequests && !(int)($last24['total_requests'] ?? 0);
		if($noData) {
			return '<div class="uk-alert uk-alert-primary" uk-alert>
				<p>🌿 <strong>No data yet.</strong> Visit a few front-end pages, then refresh — metrics will appear here within an hour (or press Flush buffer).</p>
			</div>';
		}

		// ── Prepare display values ────────────────────────────────────────────
		$sinceFmt    = $since ? date('d M Y, H:i', strtotime($since)) : '—';
		$totalBadge  = $this->co2Badge((float) ($last24['avg_co2_mg'] ?? 0));
		$botReq      = (int) ($last24['bot_requests'] ?? 0);
		$humanReq    = (int) ($last24['total_requests'] ?? 0) - $botReq;
		$botSample   = max(1, (int) ($this->get('bot_sample_rate') ?: 10));
		$intensity   = (int) ($this->get('carbon_intensity') ?: self::DEFAULT_CARBON_INTENSITY);
		$moduleUrl   = $this->wire('config')->urls->admin . 'module/edit?name=PageCarbon';

		// Buffer status
		$rawBuf      = $this->wire('cache')->get(self::CACHE_BUFFER_KEY);
		$bufCount    = $rawBuf ? count(json_decode($rawBuf, true) ?: []) : 0;
		$lastFlushTs = (int) ($this->wire('cache')->get(self::CACHE_FLUSH_TS) ?: 0);
		$nextFlush   = $lastFlushTs ? date('H:i', $lastFlushTs + self::FLUSH_INTERVAL) : 'soon';

		// Table size
		$rawRowCount  = number_format((int) ($rawStats['row_count'] ?? 0));
		$rawTableMB   = isset($rawStats['bytes']) ? round($rawStats['bytes'] / 1048576, 2) : '?';
		$rawOldest    = isset($rawStats['oldest']) ? date('d M Y', strtotime($rawStats['oldest'])) : '—';

		// Last maint
		$lastMaintTs  = (int) ($this->wire('cache')->get(self::CACHE_MAINT_TS) ?: 0);
		$lastMaintFmt = $lastMaintTs ? date('d M Y, H:i', $lastMaintTs) : 'never';

		// Page rows
		$pageRows = '';
		foreach($pages as $p) {
			$badge    = $this->co2Badge((float) $p['avg_co2']);
			$lastSeen = $p['last_seen'] ? date('d M Y, H:i', strtotime($p['last_seen'])) : '—';
			$pageRows .= "
				<tr>
					<td>
						<a href='{$p['page_path']}' target='_blank' rel='noopener'><strong>{$p['page_title']}</strong></a>
						<br><span style='font-size:11px;color:#aaa'>{$p['page_path']}</span>
					</td>
					<td class='pcf-tr'>{$p['avg_co2']}</td>
					<td class='pcf-tr' style='color:#aaa;font-size:12px'>{$p['min_co2']} – {$p['max_co2']}</td>
					<td class='pcf-tr'>{$p['avg_ms']}</td>
					<td class='pcf-tr'>{$p['avg_kb']}</td>
					<td class='pcf-tr'>{$p['hits']}</td>
					<td style='text-align:center'>{$badge}</td>
					<td style='font-size:12px;color:#aaa'>{$lastSeen}</td>
				</tr>";
		}

		// Trend chart — fill all 24 h
		$trendMap = [];
		foreach($trend as $t) $trendMap[$t['hour']] = (float) $t['co2_g'];
		$allHours = []; $allVals = [];
		for($h = 0; $h < 24; $h++) {
			$label = sprintf('%02d:00', $h);
			$allHours[] = $label;
			$allVals[]  = $trendMap[$label] ?? 0;
		}
		$trendJSON   = json_encode($allVals);
		$trendLabels = json_encode($allHours);
		$hasAnyData  = (bool) array_sum($allVals);

		return <<<HTML
<style>
#pcf-wrap {
	--pcf-val: 1.5rem;
	--pcf-lbl: 0.72rem;
}
#pcf-wrap .pcf-card {
	background:#fff;
	border:1px solid #e5e5e5;
	border-radius:4px;
	padding:16px 12px 14px;
	text-align:center;
	display:flex;
	flex-direction:column;
	align-items:center;
	justify-content:center;
	min-height:78px;
	box-sizing:border-box;
}
#pcf-wrap .pcf-val  { font-size:var(--pcf-val);font-weight:700;line-height:1.15;color:#1a1a1a;white-space:nowrap }
#pcf-wrap .pcf-lbl  { font-size:var(--pcf-lbl);color:#999;margin-top:5px;line-height:1.3 }
#pcf-wrap .pcf-section {
	font-size:0.68rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;
	color:#bbb;border-bottom:1px solid #e5e5e5;padding-bottom:6px;margin:24px 0 12px;
}
#pcf-wrap .pcf-section:first-child { margin-top:0 }
#pcf-wrap .pcf-grid { display:grid;gap:10px }
#pcf-wrap .pcf-g5   { grid-template-columns:repeat(5,1fr) }
#pcf-wrap .pcf-g4   { grid-template-columns:repeat(4,1fr) }
#pcf-wrap .pcf-g3   { grid-template-columns:repeat(3,1fr) }
@media(max-width:900px){
	#pcf-wrap .pcf-g5,#pcf-wrap .pcf-g4 { grid-template-columns:repeat(3,1fr) }
}
@media(max-width:600px){
	#pcf-wrap .pcf-g5,#pcf-wrap .pcf-g4,#pcf-wrap .pcf-g3 { grid-template-columns:repeat(2,1fr) }
}
#pcf-wrap .pcf-chart {
	background:#fff;border:1px solid #e5e5e5;border-radius:4px;
	padding:12px 14px 10px;margin-top:10px;
}
#pcf-wrap table.pcf-table { width:100%;border-collapse:collapse;font-size:13px;margin-top:10px }
#pcf-wrap table.pcf-table th {
	text-align:left;padding:8px 10px;font-size:11px;font-weight:600;
	text-transform:uppercase;letter-spacing:.04em;color:#aaa;
	border-bottom:2px solid #e5e5e5;white-space:nowrap
}
#pcf-wrap table.pcf-table td  { padding:9px 10px;border-bottom:1px solid #f0f0f0;vertical-align:middle }
#pcf-wrap table.pcf-table tbody tr:hover td { background:#fafafa }
#pcf-wrap table.pcf-table a   { color:#007aff;text-decoration:none }
#pcf-wrap table.pcf-table a:hover { text-decoration:underline }
#pcf-wrap .pcf-tr  { text-align:right }
#pcf-wrap .pcf-footer {
	display:flex;justify-content:space-between;align-items:flex-end;
	flex-wrap:wrap;gap:10px;margin-top:20px;padding-top:14px;border-top:1px solid #e5e5e5
}
#pcf-wrap .pcf-meta { font-size:11px;color:#aaa;line-height:1.8 }
#pcf-wrap .pcf-btns { display:flex;gap:8px;flex-shrink:0;flex-wrap:wrap }
#pcf-wrap .pcf-info {
	background:#f7f9fc;border:1px solid #e5e5e5;border-radius:4px;
	padding:12px 14px;font-size:12px;color:#666;line-height:1.7;margin-top:10px
}
#pcf-wrap .pcf-info strong { color:#333 }
</style>

<div id="pcf-wrap">

	<!-- ── Last 24 hours ── -->
	<div class="pcf-section">Last 24 hours</div>
	<div class="pcf-grid pcf-g5">
		<div class="pcf-card">
			<div class="pcf-val">{$last24['total_requests']}</div>
			<div class="pcf-lbl">Requests<br><span style="color:#bbb">{$humanReq} human · {$botReq} bot</span></div>
		</div>
		<div class="pcf-card">
			<div class="pcf-val">{$last24['total_co2_g']} g</div>
			<div class="pcf-lbl">CO₂ total</div>
		</div>
		<div class="pcf-card">
			<div class="pcf-val">{$last24['avg_co2_mg']} mg &nbsp;{$totalBadge}</div>
			<div class="pcf-lbl">CO₂ / request avg</div>
		</div>
		<div class="pcf-card">
			<div class="pcf-val">{$last24['avg_ms']} ms</div>
			<div class="pcf-lbl">Avg exec time</div>
		</div>
		<div class="pcf-card">
			<div class="pcf-val">{$last24['avg_kb']} KB</div>
			<div class="pcf-lbl">Avg response size</div>
		</div>
	</div>

	<!-- Hourly chart -->
	<div class="pcf-chart">
		<div style="font-size:11px;color:#bbb;margin-bottom:8px">CO₂ g/hour — last 24 h</div>
		<div style="height:72px;position:relative">
			<canvas id="pcf-chart" style="position:absolute;inset:0;width:100%;height:100%"></canvas>
			<div id="pcf-chart-svg" style="position:absolute;inset:0"></div>
		</div>
	</div>

	<!-- ── All time ── -->
	<div class="pcf-section" style="margin-top:28px">All time (raw + aggregated)</div>
	<div class="pcf-grid pcf-g4">
		<div class="pcf-card">
			<div class="pcf-val">{$totalRequests}</div>
			<div class="pcf-lbl">Total requests</div>
		</div>
		<div class="pcf-card">
			<div class="pcf-val">{$totalCO2kg} kg</div>
			<div class="pcf-lbl">CO₂ total</div>
		</div>
		<div class="pcf-card">
			<div class="pcf-val">{$avgCO2mg} mg</div>
			<div class="pcf-lbl">CO₂ / request avg</div>
		</div>
		<div class="pcf-card">
			<div class="pcf-val" style="font-size:1rem">{$sinceFmt}</div>
			<div class="pcf-lbl">Collecting since</div>
		</div>
	</div>

	<!-- ── Storage info ── -->
	<div class="pcf-section" style="margin-top:28px">Storage</div>
	<div class="pcf-grid pcf-g3">
		<div class="pcf-card">
			<div class="pcf-val" style="font-size:1.1rem">{$rawRowCount}</div>
			<div class="pcf-lbl">Raw rows in DB<br><span style="color:#bbb">oldest: {$rawOldest}</span></div>
		</div>
		<div class="pcf-card">
			<div class="pcf-val" style="font-size:1.1rem">{$rawTableMB} MB</div>
			<div class="pcf-lbl">Raw table size<br><span style="color:#bbb">retention: {$retention} days</span></div>
		</div>
		<div class="pcf-card">
			<div class="pcf-val" style="font-size:1rem">{$lastMaintFmt}</div>
			<div class="pcf-lbl">Last maintenance<br><span style="color:#bbb">aggr. + pruning</span></div>
		</div>
	</div>

	<!-- ── Top pages table ── -->
	<div class="pcf-section" style="margin-top:28px">Top pages by CO₂ — human requests, last {$retention} days</div>
	<div style="overflow-x:auto">
		<table class="pcf-table">
			<thead>
				<tr>
					<th>Page</th>
					<th class="pcf-tr">CO₂ avg (mg)</th>
					<th class="pcf-tr">Range (mg)</th>
					<th class="pcf-tr">Time (ms)</th>
					<th class="pcf-tr">Size (KB)</th>
					<th class="pcf-tr">Hits</th>
					<th style="text-align:center">Rating</th>
					<th>Last seen</th>
				</tr>
			</thead>
			<tbody>{$pageRows}</tbody>
		</table>
	</div>

	<!-- ── Footer ── -->
	<div class="pcf-footer">
		<div class="pcf-meta">
			<span>Model: <a href="https://sustainablewebdesign.org/estimating-digital-emissions/" target="_blank" rel="noopener" style="color:#007aff">Sustainable Web Design v4</a>
			&middot; Intensity: {$intensity} gCO₂/kWh
			&middot; A &lt;100 mg &middot; B &lt;300 mg &middot; C &lt;700 mg &middot; D ≥700 mg</span><br>
			<span>Buffer: <strong style="color:#333">{$bufCount}</strong> pending rows &middot; Next auto-flush at <strong style="color:#333">{$nextFlush}</strong> (hourly) &middot; Bot sampling: 1/{$botSample}</span>
		</div>
		<div class="pcf-btns">
			<a href="{$moduleUrl}" class="uk-button uk-button-primary uk-button-small">⚙ Settings</a>
			<form method="post" style="margin:0">
				<input type="hidden" name="pcf_flush" value="1">
				<button type="submit" class="uk-button uk-button-primary uk-button-small">↑ Flush buffer</button>
			</form>
			<form method="post" style="margin:0">
				<input type="hidden" name="pcf_maint" value="1">
				<button type="submit" class="uk-button uk-button-primary uk-button-small">⚙ Run maintenance</button>
			</form>
			<form method="post" style="margin:0" onsubmit="return confirm('Delete ALL data including aggregates? This cannot be undone.')">
				<input type="hidden" name="pcf_clear" value="1">
				<button type="submit" class="uk-button uk-button-primary uk-button-small">✕ Clear all data</button>
			</form>
		</div>
	</div>

</div>

<script>
(function() {
	var vals    = {$trendJSON};
	var labels  = {$trendLabels};
	var hasData = {$hasAnyData};
	var canvas  = document.getElementById('pcf-chart');
	var svgWrap = document.getElementById('pcf-chart-svg');
	if (!canvas) return;

	if (!hasData) {
		canvas.style.display = 'none';
		if (svgWrap) svgWrap.innerHTML = '<div style="height:100%;display:flex;align-items:center;justify-content:center;font-size:11px;color:#ccc">No requests in the last 24 hours yet</div>';
		return;
	}

	function drawChart() {
		if (typeof Chart === 'undefined') return false;
		canvas.style.display = 'block';
		new Chart(canvas.getContext('2d'), {
			type: 'bar',
			data: {
				labels: labels,
				datasets: [{
					data: vals,
					backgroundColor: 'rgba(30,150,90,0.22)',
					borderColor: 'rgba(30,150,90,0.7)',
					borderWidth: 1,
					borderRadius: 2,
					borderSkipped: false
				}]
			},
			options: {
				plugins: { legend: { display: false }, tooltip: {
					callbacks: { label: function(c) { return c.parsed.y.toFixed(4) + ' g CO₂'; } }
				}},
				scales: {
					x: { grid: { display: false }, ticks: { font: { size: 9 }, maxTicksLimit: 12, maxRotation: 0 } },
					y: { grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { font: { size: 9 }, maxTicksLimit: 4 } }
				},
				animation: false, responsive: true, maintainAspectRatio: false
			}
		});
		return true;
	}

	function drawSVG() {
		canvas.style.display = 'none';
		var w = 800, h = 60, n = vals.length;
		var max = Math.max.apply(null, vals.concat([0.001]));
		var barW = Math.max(1, Math.floor(w / n) - 2);
		var bars = vals.map(function(v, i) {
			var bh = Math.max(2, Math.round((v / max) * (h - 4)));
			return '<rect x="' + Math.round(i*(w/n)) + '" y="' + (h-bh) + '" width="' + barW + '" height="' + bh + '" fill="rgba(30,150,90,0.4)" rx="1"/>';
		}).join('');
		if (svgWrap) svgWrap.innerHTML = '<svg viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none" style="width:100%;height:100%;display:block">' + bars + '</svg>';
	}

	if (!drawChart()) setTimeout(function() { if (!drawChart()) drawSVG(); }, 800);
})();
</script>
HTML;
	}

	// ── Hook: collect metrics ─────────────────────────────────────────────────

	public function hookPageRender(\ProcessWire\HookEvent $event): void {
		/** @var \ProcessWire\Page $page */
		$page = $event->object;

		if($page->template == 'admin') return;
		if(!$this->get('enabled')) return;

		$skip = array_filter(array_map('trim', explode(',', (string) $this->get('skip_templates'))));
		if($skip && in_array($page->template->name, $skip)) return;

		// Detect bot
		$ua    = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
		$isBot = false;
		foreach(self::BOT_PATTERNS as $pattern) {
			if(str_contains($ua, $pattern)) { $isBot = true; break; }
		}

		// Bot sampling: record only 1 of every N bot requests
		if($isBot) {
			$rate = max(1, (int) ($this->get('bot_sample_rate') ?: 10));
			if($rate > 1 && (mt_rand(1, $rate) !== 1)) return;
		}

		$html       = (string) $event->return;
		$responseKB = mb_strlen($html, '8bit') / 1024;
		$execMs     = round((microtime(true) - $this->startTime) * 1000, 2);
		$peakMemMB  = round(memory_get_peak_usage(true) / 1048576, 3);
		$co2mg      = $this->estimateCO2($responseKB, $execMs, $peakMemMB);

		$this->bufferPush([
			'page_id'     => (int) $page->id,
			'page_path'   => substr($page->path, 0, 255),
			'page_title'  => substr($page->title ?: $page->name, 0, 255),
			'response_kb' => round($responseKB, 3),
			'exec_ms'     => $execMs,
			'peak_mem_mb' => $peakMemMB,
			'sql_count'   => 0,
			'co2_mg'      => $co2mg,
			'is_bot'      => (int) $isBot,
			'created'     => date('Y-m-d H:i:s'),
		]);
	}

	// ── WireCache buffer ──────────────────────────────────────────────────────

	protected function bufferPush(array $row): void {
		$cache = $this->wire('cache');

		$raw    = $cache->get(self::CACHE_BUFFER_KEY);
		$buffer = $raw ? json_decode($raw, true) : [];
		if(!is_array($buffer)) $buffer = [];

		$buffer[] = $row;

		$lastFlush   = (int) ($cache->get(self::CACHE_FLUSH_TS) ?: 0);
		$shouldFlush = (time() - $lastFlush >= self::FLUSH_INTERVAL)
		            || (count($buffer) >= self::FLUSH_MAX_ROWS);

		if($shouldFlush) {
			$this->flushBuffer($buffer);
			$cache->save(self::CACHE_BUFFER_KEY, json_encode([]), WireCache::expireNever);
			$cache->save(self::CACHE_FLUSH_TS,   (string) time(), WireCache::expireNever);
			// Run daily maintenance opportunistically on flush
			$this->runMaintenance();
		} else {
			$cache->save(self::CACHE_BUFFER_KEY, json_encode($buffer), WireCache::expireNever);
		}
	}

	protected function flushBuffer(array $buffer): void {
		if(empty($buffer)) return;

		$db = $this->wire('database');
		if(!$db) return;

		try {
			$ph = []; $vals = [];
			foreach($buffer as $i => $r) {
				$ph[] = "(:pid{$i},:pp{$i},:pt{$i},:rk{$i},:em{$i},:pm{$i},:sc{$i},:co{$i},:ib{$i},:cr{$i})";
				$vals["pid{$i}"] = $r['page_id'];
				$vals["pp{$i}"]  = $r['page_path'];
				$vals["pt{$i}"]  = $r['page_title'];
				$vals["rk{$i}"]  = $r['response_kb'];
				$vals["em{$i}"]  = $r['exec_ms'];
				$vals["pm{$i}"]  = $r['peak_mem_mb'];
				$vals["sc{$i}"]  = $r['sql_count'];
				$vals["co{$i}"]  = $r['co2_mg'];
				$vals["ib{$i}"]  = $r['is_bot'];
				$vals["cr{$i}"]  = $r['created'];
			}
			$stmt = $db->prepare(
				"INSERT INTO `" . self::TABLE . "`
				 (page_id,page_path,page_title,response_kb,exec_ms,peak_mem_mb,sql_count,co2_mg,is_bot,created)
				 VALUES " . implode(',', $ph)
			);
			foreach($vals as $k => $v) $stmt->bindValue(':' . $k, $v);
			$stmt->execute();
		} catch(\Exception $e) {
			// Never break the site
		}
	}

	// ── Maintenance: aggregate + prune ────────────────────────────────────────

	/**
	 * Aggregates raw rows older than 24 h into hourly buckets,
	 * then deletes raw rows beyond the retention window.
	 * Runs at most once per day unless $force = true.
	 */
	protected function runMaintenance(bool $force = false): void {
		$cache = $this->wire('cache');

		$lastMaint = (int) ($cache->get(self::CACHE_MAINT_TS) ?: 0);
		if(!$force && (time() - $lastMaint < self::MAINT_INTERVAL)) return;

		$db        = $this->wire('database');
		$retention = max(1, (int) ($this->get('retention_days') ?: 90));

		try {
			// Aggregate raw rows older than 24 h (not yet aggregated) into hourly table
			// Using INSERT ... ON DUPLICATE KEY UPDATE to merge safely
			$db->exec("
				INSERT INTO `" . self::TABLE_HOURLY . "`
					(page_path, page_title, hour_start, requests, co2_mg_sum, co2_mg_avg,
					 exec_ms_avg, response_kb_avg, is_bot)
				SELECT
					page_path,
					page_title,
					DATE_FORMAT(created, '%Y-%m-%d %H:00:00') AS hour_start,
					COUNT(*)                                   AS requests,
					ROUND(SUM(co2_mg), 4)                      AS co2_mg_sum,
					ROUND(AVG(co2_mg), 4)                      AS co2_mg_avg,
					ROUND(AVG(exec_ms), 2)                     AS exec_ms_avg,
					ROUND(AVG(response_kb), 3)                 AS response_kb_avg,
					is_bot
				FROM `" . self::TABLE . "`
				WHERE created < NOW() - INTERVAL 24 HOUR
				GROUP BY page_path, page_title, hour_start, is_bot
				ON DUPLICATE KEY UPDATE
					requests        = requests        + VALUES(requests),
					co2_mg_sum      = co2_mg_sum      + VALUES(co2_mg_sum),
					co2_mg_avg      = ROUND((co2_mg_sum + VALUES(co2_mg_sum)) / (requests + VALUES(requests)), 4),
					exec_ms_avg     = ROUND((exec_ms_avg * requests + VALUES(exec_ms_avg) * VALUES(requests)) / (requests + VALUES(requests)), 2),
					response_kb_avg = ROUND((response_kb_avg * requests + VALUES(response_kb_avg) * VALUES(requests)) / (requests + VALUES(requests)), 3)
			");

			// Delete raw rows older than retention window
			$db->exec("
				DELETE FROM `" . self::TABLE . "`
				WHERE created < NOW() - INTERVAL {$retention} DAY
			");

		} catch(\Exception $e) {
			// Maintenance failure is non-critical
		}

		$cache->save(self::CACHE_MAINT_TS, (string) time(), WireCache::expireNever);
	}

	// ── CO₂ estimation ────────────────────────────────────────────────────────

	public function estimateCO2(float $responseKB, float $execMs, float $peakMemMB): float {
		$intensity = max(1, (int) ($this->get('carbon_intensity') ?: self::DEFAULT_CARBON_INTENSITY));

		$transferEnergy = ($responseKB * 1024) * self::ENERGY_PER_BYTE;
		$cpuEnergy      = $execMs * 0.000000003;
		$memEnergy      = $peakMemMB * 0.0000004;

		return round(($transferEnergy + $cpuEnergy + $memEnergy) * $intensity * 1000, 4);
	}

	// ── CO₂ badge ─────────────────────────────────────────────────────────────

	protected function co2Badge(float $mg): string {
		if($mg < 100) return '<span class="uk-badge" style="background:#38a169;padding:3px 8px">A</span>';
		if($mg < 300) return '<span class="uk-badge" style="background:#d69e2e;padding:3px 8px">B</span>';
		if($mg < 700) return '<span class="uk-badge" style="background:#dd6b20;padding:3px 8px">C</span>';
		return '<span class="uk-badge" style="background:#e53e3e;padding:3px 8px">D</span>';
	}

	// ── Install / Uninstall ───────────────────────────────────────────────────

	public function ___install(): void {
		parent::___install();

		$db = $this->wire('database');

		// Raw data table
		$db->exec("
			CREATE TABLE IF NOT EXISTS `" . self::TABLE . "` (
				`id`           INT UNSIGNED      NOT NULL AUTO_INCREMENT,
				`page_id`      INT UNSIGNED      NOT NULL DEFAULT 0,
				`page_path`    VARCHAR(255)      NOT NULL DEFAULT '',
				`page_title`   VARCHAR(255)      NOT NULL DEFAULT '',
				`response_kb`  DECIMAL(10,3)     NOT NULL DEFAULT 0,
				`exec_ms`      DECIMAL(10,2)     NOT NULL DEFAULT 0,
				`peak_mem_mb`  DECIMAL(10,3)     NOT NULL DEFAULT 0,
				`sql_count`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				`co2_mg`       DECIMAL(12,4)     NOT NULL DEFAULT 0,
				`is_bot`       TINYINT(1)        NOT NULL DEFAULT 0,
				`created`      DATETIME          NOT NULL,
				PRIMARY KEY (`id`),
				KEY `page_path` (`page_path`),
				KEY `created`   (`created`),
				KEY `is_bot`    (`is_bot`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
		");

		// Hourly aggregate table — permanent
		$db->exec("
			CREATE TABLE IF NOT EXISTS `" . self::TABLE_HOURLY . "` (
				`id`              INT UNSIGNED   NOT NULL AUTO_INCREMENT,
				`page_path`       VARCHAR(255)   NOT NULL DEFAULT '',
				`page_title`      VARCHAR(255)   NOT NULL DEFAULT '',
				`hour_start`      DATETIME       NOT NULL,
				`requests`        INT UNSIGNED   NOT NULL DEFAULT 0,
				`co2_mg_sum`      DECIMAL(14,4)  NOT NULL DEFAULT 0,
				`co2_mg_avg`      DECIMAL(12,4)  NOT NULL DEFAULT 0,
				`exec_ms_avg`     DECIMAL(10,2)  NOT NULL DEFAULT 0,
				`response_kb_avg` DECIMAL(10,3)  NOT NULL DEFAULT 0,
				`is_bot`          TINYINT(1)     NOT NULL DEFAULT 0,
				PRIMARY KEY (`id`),
				UNIQUE KEY `uq_page_hour_bot` (`page_path`(100), `hour_start`, `is_bot`),
				KEY `hour_start` (`hour_start`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
		");
	}

	public function ___uninstall(): void {
		parent::___uninstall();

		$db = $this->wire('database');
		$db->exec("DROP TABLE IF EXISTS `" . self::TABLE . "`");
		$db->exec("DROP TABLE IF EXISTS `" . self::TABLE_HOURLY . "`");

		$cache = $this->wire('cache');
		$cache->delete(self::CACHE_BUFFER_KEY);
		$cache->delete(self::CACHE_FLUSH_TS);
		$cache->delete(self::CACHE_MAINT_TS);
	}
}