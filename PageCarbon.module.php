<?php

/**
 * PageCarbon
 *
 * Tracks per-page resource usage and estimates CO₂ emissions.
 * Adds a PageCarbon page under Setup in the PW admin.
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
 * @version 1.6.1
 */
class PageCarbon extends Process implements Module, ConfigurableModule {

	// ── Module info ───────────────────────────────────────────────────────────

	public static function getModuleInfo(): array {
		return [
			'title'    => 'PageCarbon',
			'version'  => 161,
			'summary'  => 'Tracks per-page CO₂ emissions. WireCache buffer, bot sampling, 90-day raw retention with permanent hourly aggregates.',
			'author'   => 'Maxim Alex',
			'href'     => 'https://github.com/mxmsmnv/PageCarbon',
			'icon'     => 'leaf',
			'autoload' => true,
			'singular' => true,
			'requires' => ['ProcessWire>=3.0.227', 'PHP>=8.1'],
			'page'     => [
				'name'   => 'carbon',
				'parent' => 'setup',
				'title'  => 'PageCarbon',
			],
		];
	}

	// ── Constants ─────────────────────────────────────────────────────────────

	/** Raw data table — rows older than retention days are deleted */
	const TABLE        = 'page_carbon';

	/** Hourly aggregate table — permanent, compact */
	const TABLE_HOURLY = 'page_carbon_hourly';

	const DEFAULT_CARBON_INTENSITY = 436;
	const ENERGY_PER_BYTE          = 0.00000000006;

	const CACHE_BUFFER_KEY = 'PageCarbon_buffer';
	const CACHE_FLUSH_TS   = 'PageCarbon_last_flush';
	const CACHE_MAINT_TS   = 'PageCarbon_last_maint';
	const CACHE_AGG_TS     = 'PageCarbon_last_agg_until';

	/** Seconds between batch INSERTs (1 hour) */
	const FLUSH_INTERVAL = 3600;

	/** Flush early when buffer reaches this many rows */
	const FLUSH_MAX_ROWS = 500;

	/** Run maintenance (aggregation + pruning) at most once per day */
	const MAINT_INTERVAL = 86400;

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

	// ── Process::execute ──────────────────────────────────────────────────────

	public function ___execute(): string {
		$this->headline('PageCarbon');
		$this->browserTitle('PageCarbon');

		$input = $this->wire('input');
		$cache = $this->wire('cache');
		$db    = $this->wire('database');
		$session = $this->wire('session');

		$hasPostAction = $input->post('pcf_clear') === '1'
			|| $input->post('pcf_flush') === '1'
			|| $input->post('pcf_maint') === '1'
			|| $input->post('pcf_export') === '1';
		if($hasPostAction && !$this->hasValidCsrfToken()) {
			$session->error('Security check failed (CSRF token). Please try again.');
			$session->redirect('./');
		}

		// Export DOCX report
		if($input->post('pcf_export') === '1') {
			return $this->___executeExport();
		}

		// Clear all data
		if($input->post('pcf_clear') === '1') {
			$db->exec("TRUNCATE TABLE `" . self::TABLE . "`");
			$db->exec("TRUNCATE TABLE `" . self::TABLE_HOURLY . "`");
			$cache->delete(self::CACHE_BUFFER_KEY);
			$cache->delete(self::CACHE_FLUSH_TS);
			$cache->delete(self::CACHE_MAINT_TS);
			$cache->delete(self::CACHE_AGG_TS);
			$this->wire('session')->message('All PageCarbon data cleared.');
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
					COUNT(*)                                  AS total_requests,
					SUM(CASE WHEN is_bot=1 THEN 1 ELSE 0 END) AS bot_requests,
					ROUND(SUM(co2_mg) / 1000, 4)              AS total_co2_g,
					ROUND(AVG(co2_mg), 2)                     AS avg_co2_mg,
					ROUND(AVG(exec_ms), 1)                    AS avg_ms,
					ROUND(AVG(response_kb), 1)                AS avg_kb
				FROM `" . self::TABLE . "`
				WHERE created >= NOW() - INTERVAL 24 HOUR
			")->fetch(\PDO::FETCH_ASSOC);

			// ── All-time totals — hourly aggregate ────────────────────────────
			$alltime = $db->query("
				SELECT
					SUM(requests)                                              AS total_requests,
					ROUND(SUM(co2_mg_sum) / 1000000, 6)                       AS total_co2_kg,
					ROUND(SUM(co2_mg_sum) / NULLIF(SUM(requests), 0), 2)      AS avg_co2_mg,
					MIN(hour_start)                                            AS since
				FROM `" . self::TABLE_HOURLY . "`
			")->fetch(\PDO::FETCH_ASSOC);

			// Supplement with raw rows not yet aggregated
			$rawExtraStmt = $db->prepare("
				SELECT
					COUNT(*)                          AS cnt,
					ROUND(SUM(co2_mg) / 1000000, 10) AS co2_kg_raw,
					ROUND(SUM(co2_mg), 4)             AS co2_mg_sum,
					MIN(created)                      AS since_raw
				FROM `" . self::TABLE . "`
				WHERE created >= :agg_from
			");
			$rawExtraStmt->execute([':agg_from' => $this->getRawOverlapStart()]);
			$rawExtra = $rawExtraStmt->fetch(\PDO::FETCH_ASSOC);

			$totalRequests = (int) $alltime['total_requests'] + (int) $rawExtra['cnt'];
			$totalCO2kg    = round((float) $alltime['total_co2_kg'] + (float) $rawExtra['co2_kg_raw'], 6);
			$avgCO2mg      = $totalRequests
				? round(((float) $alltime['avg_co2_mg'] * (int) $alltime['total_requests']
					   + (float) $rawExtra['co2_mg_sum'])
					   / $totalRequests, 2)
				: 0;
			$sinceArr = array_filter([$alltime['since'], $rawExtra['since_raw']]);
			$since    = $sinceArr ? min($sinceArr) : null;

			// ── Top 50 pages — human, raw table ──────────────────────────────
			$retention = max(1, (int) ($this->get('retention_days') ?: 90));
			$pages = $db->query("
				SELECT
					page_path, page_title,
					COUNT(*)                   AS hits,
					ROUND(AVG(co2_mg), 2)      AS avg_co2,
					ROUND(MIN(co2_mg), 2)      AS min_co2,
					ROUND(MAX(co2_mg), 2)      AS max_co2,
					ROUND(AVG(exec_ms), 1)     AS avg_ms,
					ROUND(AVG(response_kb), 1) AS avg_kb,
					MAX(created)               AS last_seen
				FROM `" . self::TABLE . "`
				WHERE is_bot = 0
				  AND created >= NOW() - INTERVAL {$retention} DAY
				GROUP BY page_path, page_title
				ORDER BY avg_co2 DESC
				LIMIT 50
			")->fetchAll(\PDO::FETCH_ASSOC);

			// ── Hourly trend — last 24 h ──────────────────────────────────────
			$trend = $db->query("
				SELECT
					DATE_FORMAT(created, '%H:00')  AS hour,
					ROUND(SUM(co2_mg) / 1000, 4)  AS co2_g,
					COUNT(*)                        AS hits
				FROM `" . self::TABLE . "`
				WHERE created >= NOW() - INTERVAL 24 HOUR
				GROUP BY DATE_FORMAT(created, '%Y-%m-%d %H')
				ORDER BY created ASC
			")->fetchAll(\PDO::FETCH_ASSOC);

			// ── Raw table storage stats ───────────────────────────────────────
			$rawStats = $db->query("
				SELECT
					COUNT(*)   AS row_count,
					MIN(created) AS oldest,
					ROUND(data_length + index_length, 0) AS bytes
				FROM information_schema.TABLES, `" . self::TABLE . "`
				WHERE table_schema = DATABASE()
				  AND table_name   = '" . self::TABLE . "'
				GROUP BY table_schema
				LIMIT 1
			")->fetch(\PDO::FETCH_ASSOC);

		} catch(\Exception $e) {
			return '<div class="uk-alert uk-alert-danger" uk-alert><p>Database error: ' . htmlspecialchars($e->getMessage()) . '</p></div>';
		}

		$noData = !$totalRequests && !(int) ($last24['total_requests'] ?? 0);
		if($noData) {
			return '<div class="uk-alert uk-alert-primary" uk-alert>
				<p>🌿 <strong>No data yet.</strong> Visit a few front-end pages, then refresh — metrics will appear here within an hour (or press Flush buffer).</p>
			</div>';
		}

		// ── Prepare display values ────────────────────────────────────────────
		$sinceFmt   = $since ? date('d M Y, H:i', strtotime($since)) : '—';
		$totalBadge = $this->co2Badge((float) ($last24['avg_co2_mg'] ?? 0));
		$botReq     = (int) ($last24['bot_requests'] ?? 0);
		$humanReq   = (int) ($last24['total_requests'] ?? 0) - $botReq;
		$botSample  = max(1, (int) ($this->get('bot_sample_rate') ?: 10));
		$intensity  = (int) ($this->get('carbon_intensity') ?: self::DEFAULT_CARBON_INTENSITY);
		$moduleUrl  = $this->wire('config')->urls->admin . 'module/edit?name=PageCarbon';
		$csrfInput  = $session->CSRF ? $session->CSRF->renderInput() : '';

		// Buffer status
		$rawBuf      = $this->wire('cache')->get(self::CACHE_BUFFER_KEY);
		$bufCount    = $rawBuf ? count(json_decode($rawBuf, true) ?: []) : 0;
		$lastFlushTs = (int) ($this->wire('cache')->get(self::CACHE_FLUSH_TS) ?: 0);
		$nextFlush   = $lastFlushTs ? date('H:i', $lastFlushTs + self::FLUSH_INTERVAL) : 'soon';

		// Table size
		$rawRowCount = number_format((int) ($rawStats['row_count'] ?? 0));
		$rawTableMB  = isset($rawStats['bytes']) ? round($rawStats['bytes'] / 1048576, 2) : '?';
		$rawOldest   = isset($rawStats['oldest']) ? date('d M Y', strtotime($rawStats['oldest'])) : '—';

		// Last maint
		$lastMaintTs  = (int) ($this->wire('cache')->get(self::CACHE_MAINT_TS) ?: 0);
		$lastMaintFmt = $lastMaintTs ? date('d M Y, H:i', $lastMaintTs) : 'never';

		// Page rows
		$pageRows = '';
		foreach($pages as $p) {
			$badge    = $this->co2Badge((float) $p['avg_co2']);
			$lastSeen = $p['last_seen'] ? date('d M Y, H:i', strtotime($p['last_seen'])) : '—';
			$titleEsc = htmlspecialchars((string) $p['page_title'], ENT_QUOTES, 'UTF-8');
			$pathEsc  = htmlspecialchars((string) $p['page_path'], ENT_QUOTES, 'UTF-8');
			$pageRows .= "
				<tr>
					<td>
						<a href='{$pathEsc}' target='_blank' rel='noopener'><strong>{$titleEsc}</strong></a>
						<br><span class='uk-text-meta uk-text-small'>{$pathEsc}</span>
					</td>
					<td class='uk-text-right'>{$p['avg_co2']}</td>
					<td class='uk-text-right uk-text-meta uk-text-small'>{$p['min_co2']} – {$p['max_co2']}</td>
					<td class='uk-text-right'>{$p['avg_ms']}</td>
					<td class='uk-text-right'>{$p['avg_kb']}</td>
					<td class='uk-text-right'>{$p['hits']}</td>
					<td class='uk-text-center'>{$badge}</td>
					<td class='uk-text-meta uk-text-small'>{$lastSeen}</td>
				</tr>";
		}

		// Trend chart — fill all 24 h with zeros for missing hours
		$trendMap = [];
		foreach($trend as $t) $trendMap[$t['hour']] = (float) $t['co2_g'];
		$allHours = []; $allVals = [];
		for($h = 0; $h < 24; $h++) {
			$label      = sprintf('%02d:00', $h);
			$allHours[] = $label;
			$allVals[]  = $trendMap[$label] ?? 0;
		}
		$trendJSON   = json_encode($allVals);
		$trendLabels = json_encode($allHours);
		$hasAnyData  = (bool) array_sum($allVals);

		// ── Real-world analogies for total CO₂ ──────────────────────────────
		$co2g      = $totalCO2kg * 1000;
		$carKm     = round($co2g / 120, 1);      // petrol car avg EU
		$coffees   = (int) round($co2g / 28);    // espresso
		$kettles   = (int) round($co2g / 32);    // 1L boil
		$phones    = (int) round($co2g / 8.2);   // smartphone full charge
		$netflix   = round($co2g / 36, 1);       // Netflix HD 1h
		$emails    = (int) round($co2g / 4);     // plain email
		$trees     = round($co2g / 21000, 2);    // tree absorbs ~21 kg/yr
		$bulbHours = (int) round($co2g / 0.012); // LED 10W, avg grid
		$subway    = (int) round($co2g / 35);    // metro/subway 1 trip ~35g
		$spotify   = (int) round($co2g / 0.028); // Spotify stream 1 song ~0.028g
		$flights   = round($co2g / 255000, 4);   // short-haul economy 255kg
		$searches  = (int) round($co2g / 0.2);   // Google search ~0.2g

		return <<<HTML
<style>
.pcf-card {
	height:80px;
	display:flex;flex-direction:column;align-items:center;justify-content:center;
	padding:0 12px;box-sizing:border-box;overflow:hidden
}
.pcf-stat-val { font-size:1.35rem;font-weight:700;line-height:1.2;margin:0;white-space:nowrap }
.pcf-stat-lbl { font-size:0.72rem;color:var(--pw-muted-color,#999);margin:4px 0 0;line-height:1.4 }
.pcf-chart-wrap { padding:16px 16px 14px;margin-top:12px }
.pcf-chart-inner { height:120px;position:relative }
.pcf-chart-inner canvas { position:absolute;inset:0;width:100%;height:100% }
.pcf-chart-inner #pcf-chart-svg { position:absolute;inset:0 }
</style>

<div>

	<!-- ── Last 24 hours ── -->
	<p class="uk-text-meta uk-text-uppercase uk-margin-small-bottom" style="letter-spacing:.06em">Last 24 hours</p>
	<div class="uk-grid-small uk-child-width-1-5@m uk-child-width-1-3@s uk-child-width-1-2" uk-grid>
		<div><div class="uk-card uk-card-default uk-text-center pcf-card">
			<p class="pcf-stat-val">{$last24['total_requests']}</p>
			<p class="pcf-stat-lbl">Requests<br>{$humanReq} human &middot; {$botReq} bot</p>
		</div></div>
		<div><div class="uk-card uk-card-default uk-text-center pcf-card">
			<p class="pcf-stat-val">{$last24['total_co2_g']} g</p>
			<p class="pcf-stat-lbl">CO₂ total</p>
		</div></div>
		<div><div class="uk-card uk-card-default uk-text-center pcf-card">
			<p class="pcf-stat-val">{$last24['avg_co2_mg']} mg &nbsp;{$totalBadge}</p>
			<p class="pcf-stat-lbl">CO₂ / request avg</p>
		</div></div>
		<div><div class="uk-card uk-card-default uk-text-center pcf-card">
			<p class="pcf-stat-val">{$last24['avg_ms']} ms</p>
			<p class="pcf-stat-lbl">Avg exec time</p>
		</div></div>
		<div><div class="uk-card uk-card-default uk-text-center pcf-card">
			<p class="pcf-stat-val">{$last24['avg_kb']} KB</p>
			<p class="pcf-stat-lbl">Avg response size</p>
		</div></div>
	</div>

	<!-- Hourly chart -->
	<div class="uk-card uk-card-default uk-card-body pcf-chart-wrap uk-margin-small-top">
		<p class="uk-text-meta uk-margin-remove" style="margin-bottom:8px">CO₂ g/hour — last 24 h</p>
		<div class="pcf-chart-inner">
			<canvas id="pcf-chart"></canvas>
			<div id="pcf-chart-svg"></div>
		</div>
	</div>

	<!-- ── All time ── -->
	<p class="uk-text-meta uk-text-uppercase uk-margin-top uk-margin-small-bottom" style="letter-spacing:.06em">All time (raw + aggregated)</p>
	<div class="uk-grid-small uk-child-width-1-4@m uk-child-width-1-2" uk-grid>
		<div><div class="uk-card uk-card-default uk-text-center pcf-card">
			<p class="pcf-stat-val">{$totalRequests}</p>
			<p class="pcf-stat-lbl">Total requests</p>
		</div></div>
		<div><div class="uk-card uk-card-default uk-text-center pcf-card">
			<p class="pcf-stat-val">{$totalCO2kg} kg</p>
			<p class="pcf-stat-lbl">CO₂ total</p>
		</div></div>
		<div><div class="uk-card uk-card-default uk-text-center pcf-card">
			<p class="pcf-stat-val">{$avgCO2mg} mg</p>
			<p class="pcf-stat-lbl">CO₂ / request avg</p>
		</div></div>
		<div><div class="uk-card uk-card-default uk-text-center pcf-card">
			<p class="pcf-stat-val uk-text-small">{$sinceFmt}</p>
			<p class="pcf-stat-lbl">Collecting since</p>
		</div></div>
	</div>

	<!-- ── Real-world analogies ── -->
	<p class="uk-text-meta uk-text-uppercase uk-margin-top uk-margin-small-bottom" style="letter-spacing:.06em">That's equivalent to…</p>
	<div class="uk-grid-small uk-child-width-1-4@m uk-child-width-1-2" uk-grid>

		<div><div class="uk-card uk-card-default uk-card-body" style="padding:12px 14px;display:flex;align-items:center;gap:10px">
			<span style="flex-shrink:0;color:var(--pw-main-color,#eb1d61)"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 0 0-10.026 0 1.106 1.106 0 0 0-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12" /></svg></span>
			<div><p class="pcf-stat-val" style="font-size:1.1rem">{$carKm} km</p><p class="pcf-stat-lbl">driving by car</p></div>
		</div></div>

		<div><div class="uk-card uk-card-default uk-card-body" style="padding:12px 14px;display:flex;align-items:center;gap:10px">
			<span style="flex-shrink:0;color:var(--pw-main-color,#eb1d61)"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.362 5.214A8.252 8.252 0 0 1 12 21 8.25 8.25 0 0 1 6.038 7.047 8.287 8.287 0 0 0 9 9.601a8.983 8.983 0 0 1 3.361-6.867 8.21 8.21 0 0 0 3 2.48Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M12 18a3.75 3.75 0 0 0 .495-7.468 5.99 5.99 0 0 0-1.925 3.547 5.975 5.975 0 0 1-2.133-1.001A3.75 3.75 0 0 0 12 18Z" /></svg></span>
			<div><p class="pcf-stat-val" style="font-size:1.1rem">{$coffees}</p><p class="pcf-stat-lbl">espressos brewed</p></div>
		</div></div>

		<div><div class="uk-card uk-card-default uk-card-body" style="padding:12px 14px;display:flex;align-items:center;gap:10px">
			<span style="flex-shrink:0;color:var(--pw-main-color,#eb1d61)"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" viewBox="0 0 16 16"><path d="M2 2a.5.5 0 0 0-.5.5v3a2.5 2.5 0 0 0 2.5 2.5h.5a3 3 0 0 0 2.5 2.934V12H5.5a.5.5 0 0 0 0 1h5a.5.5 0 0 0 0-1H9v-1.066A3 3 0 0 0 11.5 8H12a2.5 2.5 0 0 0 2.5-2.5v-3a.5.5 0 0 0-.5-.5zm0 1h1v3a1.5 1.5 0 0 1-1-1.415zm12 0v1.585A1.5 1.5 0 0 1 13 5V3zM3.5 3h9a.5.5 0 0 1 .5.5V8a2.5 2.5 0 0 1-5 0 .5.5 0 0 0-1 0 2.5 2.5 0 0 1-5 0V3.5a.5.5 0 0 1 .5-.5"/></svg></span>
			<div><p class="pcf-stat-val" style="font-size:1.1rem">{$kettles}</p><p class="pcf-stat-lbl">kettles boiled</p></div>
		</div></div>

		<div><div class="uk-card uk-card-default uk-card-body" style="padding:12px 14px;display:flex;align-items:center;gap:10px">
			<span style="flex-shrink:0;color:var(--pw-main-color,#eb1d61)"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 1.5H8.25A2.25 2.25 0 0 0 6 3.75v16.5a2.25 2.25 0 0 0 2.25 2.25h7.5A2.25 2.25 0 0 0 18 20.25V3.75a2.25 2.25 0 0 0-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 8.25h3m-3 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 4.5h3m-6 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0" /></svg></span>
			<div><p class="pcf-stat-val" style="font-size:1.1rem">{$phones}</p><p class="pcf-stat-lbl">phone charges</p></div>
		</div></div>

		<div><div class="uk-card uk-card-default uk-card-body" style="padding:12px 14px;display:flex;align-items:center;gap:10px">
			<span style="flex-shrink:0;color:var(--pw-main-color,#eb1d61)"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 20.25h12m-7.5-3v3m3-3v3m-10.125-3h17.25c.621 0 1.125-.504 1.125-1.125V4.875c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125Z" /></svg></span>
			<div><p class="pcf-stat-val" style="font-size:1.1rem">{$netflix} h</p><p class="pcf-stat-lbl">Netflix HD streaming</p></div>
		</div></div>

		<div><div class="uk-card uk-card-default uk-card-body" style="padding:12px 14px;display:flex;align-items:center;gap:10px">
			<span style="flex-shrink:0;color:var(--pw-main-color,#eb1d61)"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" /></svg></span>
			<div><p class="pcf-stat-val" style="font-size:1.1rem">{$emails}</p><p class="pcf-stat-lbl">emails sent</p></div>
		</div></div>

		<div><div class="uk-card uk-card-default uk-card-body" style="padding:12px 14px;display:flex;align-items:center;gap:10px">
			<span style="flex-shrink:0;color:var(--pw-main-color,#eb1d61)"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" viewBox="0 0 16 16"><path d="M8.416.223a.5.5 0 0 0-.832 0l-3 4.5A.5.5 0 0 0 5 5.5h.098L3.076 8.735A.5.5 0 0 0 3.5 9.5h.191l-1.638 3.276a.5.5 0 0 0 .447.724H7V16h2v-2.5h4.5a.5.5 0 0 0 .447-.724L12.31 9.5h.191a.5.5 0 0 0 .424-.765L10.902 5.5H11a.5.5 0 0 0 .416-.777z"/></svg></span>
			<div><p class="pcf-stat-val" style="font-size:1.1rem">{$trees}</p><p class="pcf-stat-lbl">trees needed 1 year</p></div>
		</div></div>

		<div><div class="uk-card uk-card-default uk-card-body" style="padding:12px 14px;display:flex;align-items:center;gap:10px">
			<span style="flex-shrink:0;color:var(--pw-main-color,#eb1d61)"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18v-5.25m0 0a6.01 6.01 0 0 0 1.5-.189m-1.5.189a6.01 6.01 0 0 1-1.5-.189m3.75 7.478a12.06 12.06 0 0 1-4.5 0m3.75 2.383a14.406 14.406 0 0 1-3 0M14.25 18v-.192c0-.983.658-1.823 1.508-2.316a7.5 7.5 0 1 0-7.517 0c.85.493 1.509 1.333 1.509 2.316V18" /></svg></span>
			<div><p class="pcf-stat-val" style="font-size:1.1rem">{$bulbHours} h</p><p class="pcf-stat-lbl">LED bulb on</p></div>
		</div></div>

		<div><div class="uk-card uk-card-default uk-card-body" style="padding:12px 14px;display:flex;align-items:center;gap:10px">
			<span style="flex-shrink:0;color:var(--pw-main-color,#eb1d61)"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" viewBox="0 0 16 16"><path d="M5.5 10.5a.5.5 0 1 1-1 0 .5.5 0 0 1 1 0m5 0a.5.5 0 1 1-1 0 .5.5 0 0 1 1 0"/><path d="M5.066 7.396A2 2 0 0 1 7 6h2a2 2 0 0 1 1.934 1.396l.23.691A1.5 1.5 0 0 1 12.5 9.5h.5a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5H12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2h-.5A.5.5 0 0 1 3 11v-1a.5.5 0 0 1 .5-.5h.5a1.5 1.5 0 0 1 .836-1.341zM7 7a1 1 0 0 0-.966.741L5.5 9.5h5l-.534-1.759A1 1 0 0 0 9 7zm4 5v-1H5v1a1 1 0 0 0 1 1h4a1 1 0 0 0 1-1"/><path d="M6.236 3.446A1.5 1.5 0 0 1 7.5 2.5h1a1.5 1.5 0 0 1 1.264.946l.228.683A2 2 0 0 0 9 4H7a2 2 0 0 0-.992.129zM6 13.5V15h-.25a.75.75 0 0 0 0 1.5h4.5a.75.75 0 0 0 0-1.5H10v-1.5z"/></svg></span>
			<div><p class="pcf-stat-val" style="font-size:1.1rem">{$subway}</p><p class="pcf-stat-lbl">subway trips</p></div>
		</div></div>

		<div><div class="uk-card uk-card-default uk-card-body" style="padding:12px 14px;display:flex;align-items:center;gap:10px">
			<span style="flex-shrink:0;color:var(--pw-main-color,#eb1d61)"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m9 9 10.5-3m0 6.553v3.75a2.25 2.25 0 0 1-1.632 2.163l-1.32.377a1.803 1.803 0 1 1-.99-3.467l2.31-.66a2.25 2.25 0 0 0 1.632-2.163Zm0 0V2.25L9 5.25v10.303m0 0v3.75a2.25 2.25 0 0 1-1.632 2.163l-1.32.377a1.803 1.803 0 0 1-.99-3.467l2.31-.66A2.25 2.25 0 0 0 9 15.553Z" /></svg></span>
			<div><p class="pcf-stat-val" style="font-size:1.1rem">{$spotify}</p><p class="pcf-stat-lbl">songs streamed</p></div>
		</div></div>

		<div><div class="uk-card uk-card-default uk-card-body" style="padding:12px 14px;display:flex;align-items:center;gap:10px">
			<span style="flex-shrink:0;color:var(--pw-main-color,#eb1d61)"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" /></svg></span>
			<div><p class="pcf-stat-val" style="font-size:1.1rem">{$flights}</p><p class="pcf-stat-lbl">short-haul flights</p></div>
		</div></div>

		<div><div class="uk-card uk-card-default uk-card-body" style="padding:12px 14px;display:flex;align-items:center;gap:10px">
			<span style="flex-shrink:0;color:var(--pw-main-color,#eb1d61)"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 15.803 7.5 7.5 0 0 0 15.803 15.803Z" /></svg></span>
			<div><p class="pcf-stat-val" style="font-size:1.1rem">{$searches}</p><p class="pcf-stat-lbl">Google searches</p></div>
		</div></div>

	</div>

	<!-- ── Storage ── -->
	<p class="uk-text-meta uk-text-uppercase uk-margin-top uk-margin-small-bottom" style="letter-spacing:.06em">Storage</p>
	<div class="uk-grid-small uk-child-width-1-3@s uk-child-width-1-2" uk-grid>
		<div><div class="uk-card uk-card-default uk-text-center pcf-card">
			<p class="pcf-stat-val">{$rawRowCount}</p>
			<p class="pcf-stat-lbl">Raw rows in DB<br>oldest: {$rawOldest}</p>
		</div></div>
		<div><div class="uk-card uk-card-default uk-text-center pcf-card">
			<p class="pcf-stat-val">{$rawTableMB} MB</p>
			<p class="pcf-stat-lbl">Raw table size<br>retention: {$retention} days</p>
		</div></div>
		<div><div class="uk-card uk-card-default uk-text-center pcf-card">
			<p class="pcf-stat-val uk-text-small">{$lastMaintFmt}</p>
			<p class="pcf-stat-lbl">Last maintenance<br>aggr. + pruning</p>
		</div></div>
	</div>

	<!-- ── Top pages table ── -->
	<p class="uk-text-meta uk-text-uppercase uk-margin-top uk-margin-small-bottom" style="letter-spacing:.06em">Top 50 pages by CO₂ — human requests, last {$retention} days</p>
	<div class="uk-overflow-auto">
		<table class="uk-table uk-table-small uk-table-divider uk-table-hover uk-table-striped">
			<thead>
				<tr>
					<th>Page</th>
					<th class="uk-text-right">CO₂ avg (mg)</th>
					<th class="uk-text-right">Range (mg)</th>
					<th class="uk-text-right">Time (ms)</th>
					<th class="uk-text-right">Size (KB)</th>
					<th class="uk-text-right">Hits</th>
					<th class="uk-text-center">Rating</th>
					<th>Last seen</th>
				</tr>
			</thead>
			<tbody>{$pageRows}</tbody>
		</table>
	</div>

	<!-- ── Footer ── -->
	<hr class="uk-margin-top">
	<div class="uk-flex uk-flex-between uk-flex-wrap uk-flex-middle" style="gap:10px">
		<p class="uk-text-meta uk-margin-remove" style="line-height:1.9">
			Model: <a href="https://sustainablewebdesign.org/estimating-digital-emissions/" target="_blank" rel="noopener">Sustainable Web Design v4</a>
			&middot; Intensity: {$intensity} gCO₂/kWh
			&middot; A &lt;100 mg &middot; B &lt;300 mg &middot; C &lt;700 mg &middot; D ≥700 mg<br>
			Buffer: <strong>{$bufCount}</strong> pending rows
			&middot; Next auto-flush at <strong>{$nextFlush}</strong> (hourly)
			&middot; Bot sampling: 1/{$botSample}
		</p>
		<div class="uk-flex uk-flex-wrap" style="gap:6px">
			<a href="{$moduleUrl}" class="uk-button uk-button-default uk-button-small"><span uk-icon="icon:settings;ratio:0.8"></span> Settings</a>
			<form method="post" style="margin:0">
				{$csrfInput}
				<input type="hidden" name="pcf_flush" value="1">
				<button type="submit" class="uk-button uk-button-default uk-button-small"><span uk-icon="icon:upload;ratio:0.8"></span> Flush buffer</button>
			</form>
			<form method="post" style="margin:0">
				{$csrfInput}
				<input type="hidden" name="pcf_maint" value="1">
				<button type="submit" class="uk-button uk-button-default uk-button-small"><span uk-icon="icon:cog;ratio:0.8"></span> Run maintenance</button>
			</form>
			<form method="post" style="margin:0">
				{$csrfInput}
				<input type="hidden" name="pcf_export" value="1">
				<button type="submit" class="uk-button uk-button-primary uk-button-small"><span uk-icon="icon:download;ratio:0.8"></span> Export DOCX</button>
			</form>
			<form method="post" style="margin:0" onsubmit="return confirm('Delete ALL data including aggregates? This cannot be undone.')">
				{$csrfInput}
				<input type="hidden" name="pcf_clear" value="1">
				<button type="submit" class="uk-button uk-button-danger uk-button-small"><span uk-icon="icon:trash;ratio:0.8"></span> Clear all data</button>
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

	var mainColor = getComputedStyle(document.documentElement).getPropertyValue('--pw-main-color').trim() || '#eb1d61';
	function hexToRgb(hex) {
		var r = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
		return r ? parseInt(r[1],16)+','+parseInt(r[2],16)+','+parseInt(r[3],16) : '235,29,97';
	}
	var rgb = hexToRgb(mainColor);

	function drawChart() {
		if (typeof Chart === 'undefined') return false;
		canvas.style.display = 'block';
		new Chart(canvas.getContext('2d'), {
			type: 'bar',
			data: {
				labels: labels,
				datasets: [{
					data: vals,
					backgroundColor: 'rgba('+rgb+',0.7)',
					borderWidth: 0
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
				animation: { duration: 500, easing: 'easeOutQuart' },
				responsive: true, maintainAspectRatio: false
			}
		});
		return true;
	}

	function drawSVG() {
		canvas.style.display = 'none';
		var w = 800, h = 120, n = vals.length;
		var max = Math.max.apply(null, vals.concat([0.001]));
		var barW = Math.max(1, Math.floor(w / n) - 2);
		var bars = vals.map(function(v, i) {
			var bh = Math.max(2, Math.round((v / max) * (h - 4)));
			return '<rect x="' + Math.round(i*(w/n)) + '" y="' + (h-bh) + '" width="' + barW + '" height="' + bh + '" fill="rgba('+rgb+',0.7)" rx="1"/>';
		}).join('');
		if (svgWrap) svgWrap.innerHTML = '<svg viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none" style="width:100%;height:100%;display:block">' + bars + '</svg>';
	}

	function initChart() {
		if (drawChart()) return;
		if (!document.getElementById('pcf-chartjs')) {
			var s = document.createElement('script');
			s.id  = 'pcf-chartjs';
			s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js';
			s.onload = function() { if (!drawChart()) drawSVG(); };
			s.onerror = drawSVG;
			document.head.appendChild(s);
		} else {
			setTimeout(function() { if (!drawChart()) drawSVG(); }, 800);
		}
	}
	initChart();
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
		$aggUntilTs = time() - 86400;
		$aggUntil   = date('Y-m-d H:i:s', $aggUntilTs);
		$lastAggTs  = (int) ($cache->get(self::CACHE_AGG_TS) ?: 0);

		if($lastAggTs && $lastAggTs >= $aggUntilTs) {
			$cache->save(self::CACHE_MAINT_TS, (string) time(), WireCache::expireNever);
			return;
		}

		$ok = true;

		try {
			$whereFrom = $lastAggTs ? 'AND created >= :agg_from' : '';
			$sql = "
				INSERT INTO `" . self::TABLE_HOURLY . "`
					(page_path, page_title, hour_start, requests, co2_mg_sum, co2_mg_avg,
					 exec_ms_avg, response_kb_avg, is_bot)
				SELECT
					page_path,
					page_title,
					DATE_FORMAT(created, '%Y-%m-%d %H:00:00') AS hour_start,
					COUNT(*)                                   AS requests,
					ROUND(SUM(co2_mg), 4)                     AS co2_mg_sum,
					ROUND(AVG(co2_mg), 4)                     AS co2_mg_avg,
					ROUND(AVG(exec_ms), 2)                    AS exec_ms_avg,
					ROUND(AVG(response_kb), 3)                AS response_kb_avg,
					is_bot
				FROM `" . self::TABLE . "`
				WHERE created < :agg_until
				  {$whereFrom}
				GROUP BY page_path, page_title, hour_start, is_bot
				ON DUPLICATE KEY UPDATE
					requests        = requests        + VALUES(requests),
					co2_mg_sum      = co2_mg_sum      + VALUES(co2_mg_sum),
					co2_mg_avg      = ROUND((co2_mg_sum + VALUES(co2_mg_sum)) / (requests + VALUES(requests)), 4),
					exec_ms_avg     = ROUND((exec_ms_avg * requests + VALUES(exec_ms_avg) * VALUES(requests)) / (requests + VALUES(requests)), 2),
					response_kb_avg = ROUND((response_kb_avg * requests + VALUES(response_kb_avg) * VALUES(requests)) / (requests + VALUES(requests)), 3)
			";
			$stmt = $db->prepare($sql);
			$params = [':agg_until' => $aggUntil];
			if($lastAggTs) $params[':agg_from'] = date('Y-m-d H:i:s', $lastAggTs);
			$stmt->execute($params);

			// Delete raw rows older than retention window
			$db->exec("
				DELETE FROM `" . self::TABLE . "`
				WHERE created < NOW() - INTERVAL {$retention} DAY
			");

		} catch(\Exception $e) {
			// Maintenance failure is non-critical
			$ok = false;
		}

		$cache->save(self::CACHE_MAINT_TS, (string) time(), WireCache::expireNever);
		if($ok) {
			$cache->save(self::CACHE_AGG_TS, (string) $aggUntilTs, WireCache::expireNever);
		}
	}

	// ── CO₂ estimation ────────────────────────────────────────────────────────

	public function estimateCO2(float $responseKB, float $execMs, float $peakMemMB): float {
		$intensity = max(1, (int) ($this->get('carbon_intensity') ?: self::DEFAULT_CARBON_INTENSITY));

		$transferEnergy = ($responseKB * 1024) * self::ENERGY_PER_BYTE;
		$cpuEnergy      = $execMs * 0.000000003;
		$memEnergy      = $peakMemMB * 0.0000004;

		return round(($transferEnergy + $cpuEnergy + $memEnergy) * $intensity * 1000, 4);
	}

	// ── CO₂ rating badge ──────────────────────────────────────────────────────

	protected function co2Badge(float $mg): string {
		if($mg < 100) return '<span class="uk-badge" style="background:#38a169;padding:3px 8px">A</span>';
		if($mg < 300) return '<span class="uk-badge" style="background:#d69e2e;padding:3px 8px">B</span>';
		if($mg < 700) return '<span class="uk-badge" style="background:#dd6b20;padding:3px 8px">C</span>';
		return '<span class="uk-badge" style="background:#e53e3e;padding:3px 8px">D</span>';
	}

	// ── DOCX Export ───────────────────────────────────────────────────────────

	public function ___executeExport(): string {
		$db        = $this->wire('database');
		$config    = $this->wire('config');
		$retention = max(1, (int) ($this->get('retention_days') ?: 90));
		$intensity = (int) ($this->get('carbon_intensity') ?: self::DEFAULT_CARBON_INTENSITY);

		try {
			$last24 = $db->query("
				SELECT
					COUNT(*) AS total_requests,
					SUM(CASE WHEN is_bot=1 THEN 1 ELSE 0 END) AS bot_requests,
					ROUND(SUM(co2_mg)/1000,4)  AS total_co2_g,
					ROUND(AVG(co2_mg),2)        AS avg_co2_mg,
					ROUND(AVG(exec_ms),1)       AS avg_ms,
					ROUND(AVG(response_kb),1)   AS avg_kb
				FROM `" . self::TABLE . "`
				WHERE created >= NOW() - INTERVAL 24 HOUR
			")->fetch(\PDO::FETCH_ASSOC);

			$hourlySum = $db->query("
				SELECT SUM(requests) AS reqs,
					   ROUND(SUM(co2_mg_sum)/1000000,6) AS co2_kg,
					   ROUND(SUM(co2_mg_sum)/NULLIF(SUM(requests),0),2) AS avg_mg,
					   MIN(hour_start) AS since
				FROM `" . self::TABLE_HOURLY . "`
			")->fetch(\PDO::FETCH_ASSOC);

			$rawSumStmt = $db->prepare("
				SELECT COUNT(*) AS cnt,
					   ROUND(SUM(co2_mg)/1000000,10) AS co2_kg,
					   ROUND(AVG(co2_mg),2) AS avg_mg,
					   MIN(created) AS since
				FROM `" . self::TABLE . "`
				WHERE created >= :agg_from
			");
			$rawSumStmt->execute([':agg_from' => $this->getRawOverlapStart()]);
			$rawSum = $rawSumStmt->fetch(\PDO::FETCH_ASSOC);

			$totalReqs  = (int) $hourlySum['reqs'] + (int) $rawSum['cnt'];
			$totalCO2kg = round((float) $hourlySum['co2_kg'] + (float) $rawSum['co2_kg'], 6);
			$avgCO2mg   = $totalReqs ? round(
				((float) $hourlySum['avg_mg'] * (int) $hourlySum['reqs']
				+ (float) $rawSum['avg_mg']   * (int) $rawSum['cnt']) / $totalReqs, 2
			) : 0;
			$since = array_filter([$hourlySum['since'], $rawSum['since']]);
			$since = $since ? date('d M Y, H:i', strtotime(min($since))) : '—';

			$pages = $db->query("
				SELECT page_path, page_title,
					   COUNT(*) AS hits,
					   ROUND(AVG(co2_mg),2) AS avg_co2,
					   ROUND(MIN(co2_mg),2) AS min_co2,
					   ROUND(MAX(co2_mg),2) AS max_co2,
					   ROUND(AVG(exec_ms),1) AS avg_ms,
					   ROUND(AVG(response_kb),1) AS avg_kb
				FROM `" . self::TABLE . "`
				WHERE is_bot=0 AND created >= NOW() - INTERVAL {$retention} DAY
				GROUP BY page_path, page_title
				ORDER BY avg_co2 DESC LIMIT 50
			")->fetchAll(\PDO::FETCH_ASSOC);

		} catch(\Exception $e) {
			return '<div class="uk-alert uk-alert-danger" uk-alert><p>Export error: ' . htmlspecialchars($e->getMessage()) . '</p></div>';
		}

		require_once __DIR__ . '/PageCarbonDocx.php';

		$docx = new PageCarbonDocx([
			'site_url'        => $config->urls->root,
			'site_name'       => $config->httpHost,
			'generated_at'    => date('c'),
			'intensity'       => $intensity,
			'retention_days'  => $retention,
			'summary_24h'     => $last24,
			'summary_alltime' => [
				'total_requests' => $totalReqs,
				'total_co2_kg'   => $totalCO2kg,
				'avg_co2_mg'     => $avgCO2mg,
				'since'          => $since,
			],
			'top_pages' => $pages,
		]);

		if(ob_get_level()) ob_end_clean();
		$docx->download('pagecarbon-report-' . date('Y-m-d') . '.docx');
		return ''; // never reached
	}

	protected function getRawOverlapStart(): string {
		$aggTs = (int) ($this->wire('cache')->get(self::CACHE_AGG_TS) ?: 0);
		if($aggTs < 1) $aggTs = time() - 86400;
		return date('Y-m-d H:i:s', $aggTs);
	}

	protected function hasValidCsrfToken(): bool {
		$csrf = $this->wire('session')->CSRF;
		if(!$csrf) return true;

		try {
			if(method_exists($csrf, 'hasValidToken') && $csrf->hasValidToken()) return true;
		} catch(\Throwable $e) {
			// Fall through to validate()
		}

		try {
			if(method_exists($csrf, 'validate')) {
				$csrf->validate();
				return true;
			}
		} catch(\Throwable $e) {
			return false;
		}

		return false;
	}

	// ── Frontend API ──────────────────────────────────────────────────────────

	/**
	 * Get CO₂ stats for a given page from the raw table.
	 * Returns null if no data exists for that page.
	 *
	 * Keys: avg_co2_mg, min_co2_mg, max_co2_mg, avg_ms, avg_kb,
	 *       hits, last_seen, rating (A/B/C/D), rating_color (#hex)
	 *
	 * Usage:
	 *   $stats = $modules->get('PageCarbon')->getStats($page);
	 *   if($stats) echo $stats['avg_co2_mg'] . ' mg CO₂ · Rating ' . $stats['rating'];
	 */
	public function getStats(\ProcessWire\Page $page): ?array {
		$db = $this->wire('database');
		try {
			$stmt = $db->prepare("
				SELECT
					ROUND(AVG(co2_mg), 2)      AS avg_co2_mg,
					ROUND(MIN(co2_mg), 2)      AS min_co2_mg,
					ROUND(MAX(co2_mg), 2)      AS max_co2_mg,
					ROUND(AVG(exec_ms), 1)     AS avg_ms,
					ROUND(AVG(response_kb), 1) AS avg_kb,
					COUNT(*)                   AS hits,
					MAX(created)               AS last_seen
				FROM `" . self::TABLE . "`
				WHERE page_path = :path AND is_bot = 0
			");
			$stmt->execute([':path' => $page->path]);
			$row = $stmt->fetch(\PDO::FETCH_ASSOC);
		} catch(\Exception $e) {
			return null;
		}

		if(!$row || !(int) $row['hits']) return null;

		$mg = (float) $row['avg_co2_mg'];
		$row['rating'] = match(true) {
			$mg < 100 => 'A',
			$mg < 300 => 'B',
			$mg < 700 => 'C',
			default   => 'D',
		};
		$row['rating_color'] = match($row['rating']) {
			'A' => '#38a169',
			'B' => '#d69e2e',
			'C' => '#dd6b20',
			default => '#e53e3e',
		};

		return $row;
	}

	/**
	 * Render a ready-made HTML badge for a page.
	 *
	 * @param \ProcessWire\Page $page
	 * @param string            $style  'full' | 'compact' | 'minimal'
	 *
	 * Usage:
	 *   echo $modules->get('PageCarbon')->renderBadge($page);
	 *   echo $modules->get('PageCarbon')->renderBadge($page, 'compact');
	 */
	public function renderBadge(\ProcessWire\Page $page, string $style = 'full'): string {
		$stats = $this->getStats($page);
		if(!$stats) return '';

		$mg    = $stats['avg_co2_mg'];
		$r     = $stats['rating'];
		$color = $stats['rating_color'];

		return match($style) {
			'minimal' =>
				"<span style='display:inline-flex;align-items:center;gap:5px;font-size:12px;color:#666'>"
				. "🌿 {$mg} mg CO₂"
				. "<span style='background:{$color};color:#fff;border-radius:3px;padding:1px 6px;font-size:11px;font-weight:700'>{$r}</span>"
				. "</span>",

			'compact' =>
				"<span style='display:inline-flex;align-items:center;gap:6px;font-size:13px;"
				. "background:#f7f9f7;border:1px solid #ddd;border-radius:4px;padding:4px 10px;color:#444'>"
				. "🌿 <strong>{$mg} mg</strong> CO₂"
				. "<span style='background:{$color};color:#fff;border-radius:3px;padding:2px 7px;font-size:11px;font-weight:700'>{$r}</span>"
				. "</span>",

			default =>
				"<div style='display:inline-flex;align-items:center;gap:10px;"
				. "background:#f7faf7;border:1px solid #d4e8d4;border-radius:6px;"
				. "padding:8px 14px;font-size:13px;color:#444;line-height:1.4'>"
				. "<span style='font-size:20px'>🌿</span>"
				. "<span><strong style='font-size:15px;color:#222'>{$mg} mg</strong> CO₂ per visit"
				. "<br><span style='font-size:11px;color:#888'>{$stats['hits']} samples · {$stats['avg_ms']} ms · {$stats['avg_kb']} KB</span></span>"
				. "<span style='background:{$color};color:#fff;border-radius:4px;"
				. "padding:4px 10px;font-weight:700;font-size:14px'>{$r}</span>"
				. "</div>",
		};
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
		$cache->delete(self::CACHE_AGG_TS);
	}
}
