<?php

/**
 * PageCarbonConfig
 *
 * Module configuration fields.
 *
 * @author Maxim Alex <maxim@smnv.org> (smnv.org)
 * @link   https://github.com/mxmsmnv/PageCarbon
 */
class PageCarbonConfig extends ModuleConfig {

	public function getDefaults(): array {
		return [
			'enabled'          => true,
			'carbon_intensity' => 436,
			'retention_days'   => 90,
			'bot_sample_rate'  => 10,
			'skip_templates'   => 'search, sitemap',
		];
	}

	public function getInputfields(): \ProcessWire\InputfieldWrapper {
		$fields  = parent::getInputfields();
		$modules = $this->wire('modules');

		// ── Enabled ───────────────────────────────────────────────────────────
		/** @var \ProcessWire\InputfieldCheckbox $f */
		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', 'enabled');
		$f->label         = 'Enable data collection';
		$f->description   = 'Uncheck to pause recording without dropping the database tables.';
		$f->checkboxLabel = 'Enabled';
		$fields->add($f);

		// ── Carbon intensity ──────────────────────────────────────────────────
		/** @var \ProcessWire\InputfieldInteger $f */
		$f = $modules->get('InputfieldInteger');
		$f->attr('name', 'carbon_intensity');
		$f->label       = 'Grid carbon intensity (gCO₂eq/kWh)';
		$f->description = 'World average ≈ 436. Europe ≈ 295, USA ≈ 386, Germany ≈ 350, Poland ≈ 680. '
		                . 'Up-to-date values: electricitymaps.com';
		$f->min = 1;
		$f->max = 2000;
		$fields->add($f);

		// ── Retention ─────────────────────────────────────────────────────────
		/** @var \ProcessWire\InputfieldInteger $f */
		$f = $modules->get('InputfieldInteger');
		$f->attr('name', 'retention_days');
		$f->label       = 'Raw data retention (days)';
		$f->description = 'Raw request rows older than this are deleted during daily maintenance. '
		                . 'Historical data is preserved permanently in the hourly aggregate table. '
		                . 'Recommended: 30–180 days.';
		$f->notes       = 'Maintenance runs automatically once per day on the first hourly buffer flush.';
		$f->min = 7;
		$f->max = 3650;
		$fields->add($f);

		// ── Bot sample rate ───────────────────────────────────────────────────
		/** @var \ProcessWire\InputfieldInteger $f */
		$f = $modules->get('InputfieldInteger');
		$f->attr('name', 'bot_sample_rate');
		$f->label       = 'Bot sampling rate (1 of N)';
		$f->description = 'Only 1 out of every N bot requests is recorded. '
		                . 'Set to 1 to record all bots (no sampling). '
		                . 'Set to 10 to record ~10% of bot traffic. '
		                . 'Set to 0 or 1 to disable sampling (record all).';
		$f->notes       = 'Bot detection is based on the User-Agent string. Human requests are always recorded.';
		$f->min = 1;
		$f->max = 1000;
		$fields->add($f);

		// ── Skip templates ────────────────────────────────────────────────────
		/** @var \ProcessWire\InputfieldText $f */
		$f = $modules->get('InputfieldText');
		$f->attr('name', 'skip_templates');
		$f->label       = 'Exclude templates (comma-separated)';
		$f->description = 'Pages using these templates will not be recorded. Example: search, sitemap, ajax-endpoint';
		$fields->add($f);

		// ── Info ──────────────────────────────────────────────────────────────
		/** @var \ProcessWire\InputfieldMarkup $f */
		$f = $modules->get('InputfieldMarkup');
		$f->label = 'Storage strategy';
		$f->value = '
			<div class="uk-alert uk-alert-primary" uk-alert>
				<p style="margin:0;line-height:1.7">
					<strong>Raw table</strong> — stores individual request rows for <em>retention_days</em>, then prunes them.<br>
					<strong>Hourly table</strong> — stores compressed hourly averages per page, kept permanently (~15 MB/year at 10k req/day).<br>
					<strong>All-time totals</strong> on the stats page combine both tables automatically.<br>
					<strong>Bot sampling</strong> — reduces bot row volume by the configured factor while preserving trend accuracy.
				</p>
			</div>
		';
		$fields->add($f);

		return $fields;
	}
}
