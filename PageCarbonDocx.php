<?php

/**
 * PageCarbonDocx
 *
 * Zero-dependency .docx report generator for the PageCarbon module.
 * Uses only PHP's built-in ZipArchive extension — no Composer, no Node.js.
 *
 * Usage:
 *   require_once __DIR__ . '/PageCarbonDocx.php';
 *   $docx = new PageCarbonDocx($data);
 *   $docx->download('pagecarbon-report-2026-03-12.docx');
 *
 * @author  Maxim Semenov <maxim@smnv.org> (smnv.org)
 * @link    https://github.com/mxmsmnv/PageCarbon
 * @version 1.6.2
 */
class PageCarbonDocx {

	// ── Palette ───────────────────────────────────────────────────────────────

	const COLOR_HEADER  = '064E3B';
	const COLOR_ACCENT  = '065F46';
	const COLOR_GREEN   = '276749';
	const COLOR_WHITE   = 'FFFFFF';
	const COLOR_DARK    = '111827';
	const COLOR_GRAY    = '6B7280';
	const COLOR_GRAY_BG = 'F9FAFB';
	const COLOR_BORDER  = 'E5E7EB';

	const RATING_COLORS = [
		'A' => '38A169',
		'B' => 'D69E2E',
		'C' => 'DD6B20',
		'D' => 'E53E3E',
	];

	// ── Constructor ───────────────────────────────────────────────────────────

	protected array $data;

	public function __construct(array $data) {
		$this->data = $data;
	}

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Stream the .docx file to the browser as a download and exit.
	 */
	public function download(string $filename = 'pagecarbon-report.docx'): void {
		$tmp = $this->buildFile();
		header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
		header('Content-Disposition: attachment; filename="' . $filename . '"');
		header('Content-Length: ' . filesize($tmp));
		header('Cache-Control: no-cache, no-store, must-revalidate');
		if(ob_get_level()) ob_end_clean();
		readfile($tmp);
		unlink($tmp);
		exit;
	}

	/**
	 * Build the .docx, write to a temp file, and return its path.
	 * Caller is responsible for deleting the file after use.
	 */
	public function buildFile(): string {
		$tmp = tempnam(sys_get_temp_dir(), 'pagecarbon_') . '.docx';
		$zip = new ZipArchive();
		if($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
			throw new \RuntimeException('PageCarbonDocx: cannot create ZIP at ' . $tmp);
		}
		$zip->addFromString('[Content_Types].xml',          $this->contentTypes());
		$zip->addFromString('_rels/.rels',                  $this->rootRels());
		$zip->addFromString('word/_rels/document.xml.rels', $this->documentRels());
		$zip->addFromString('word/document.xml',            $this->documentXml());
		$zip->addFromString('word/header1.xml',             $this->headerXml());
		$zip->addFromString('word/footer1.xml',             $this->footerXml());
		$zip->addFromString('word/styles.xml',              $this->stylesXml());
		$zip->addFromString('word/settings.xml',            $this->settingsXml());
		$zip->addFromString('word/theme/theme1.xml',        $this->themeXml());
		$zip->close();
		return $tmp;
	}

	// ── Utility ───────────────────────────────────────────────────────────────

	protected function x(string $s): string {
		return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
	}

	protected static function formatCO2kg(float $kg): string {
		$mg = $kg * 1e6;
		if($mg < 1)    return round($kg * 1e9, 2) . ' µg';
		if($mg < 1000) return round($mg, 2) . ' mg';
		$g = $kg * 1000;
		if($g < 1000)  return round($g, 4) . ' g';
		return round($kg, 4) . ' kg';
	}

	protected function rating(float $mg): array {
		if($mg < 100) return ['A', self::RATING_COLORS['A']];
		if($mg < 300) return ['B', self::RATING_COLORS['B']];
		if($mg < 700) return ['C', self::RATING_COLORS['C']];
		return ['D', self::RATING_COLORS['D']];
	}

	// ── Run / paragraph builders ──────────────────────────────────────────────

	/**
	 * Build a <w:rPr> block.
	 */
	protected function rpr(array $o = []): string {
		$font   = '<w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/>';
		$bold   = !empty($o['bold'])   ? '<w:b/>'   : '';
		$italic = !empty($o['italic']) ? '<w:i/>'   : '';
		$sz     = isset($o['size'])
			? '<w:sz w:val="' . ($o['size'] * 2) . '"/><w:szCs w:val="' . ($o['size'] * 2) . '"/>'
			: '';
		$color  = isset($o['color'])
			? '<w:color w:val="' . $o['color'] . '"/>'
			: '';
		return '<w:rPr>' . $font . $bold . $italic . $sz . $color . '</w:rPr>';
	}

	/**
	 * Build a <w:r> run.
	 */
	protected function run(string $text, array $o = []): string {
		return '<w:r>' . $this->rpr($o) . '<w:t xml:space="preserve">' . $this->x($text) . '</w:t></w:r>';
	}

	/**
	 * Build a <w:p> paragraph.
	 *
	 * Validated pPr child order (matches OOXML reference):
	 *   pBdr → shd → spacing → jc
	 */
	protected function para(string $runs, array $o = []): string {
		$border  = isset($o['border_bottom'])
			? '<w:pBdr><w:bottom w:val="single" w:sz="' . ($o['border_sz'] ?? 4) . '" w:space="4" w:color="' . $o['border_bottom'] . '"/></w:pBdr>'
			: '';
		$shade   = isset($o['shade'])
			? '<w:shd w:val="clear" w:color="auto" w:fill="' . $o['shade'] . '"/>'
			: '';
		$spacing = (isset($o['before']) || isset($o['after']))
			? '<w:spacing w:before="' . ($o['before'] ?? 0) . '" w:after="' . ($o['after'] ?? 0) . '"/>'
			: '';
		$jc      = isset($o['align'])
			? '<w:jc w:val="' . $o['align'] . '"/>'
			: '';
		return '<w:p><w:pPr>' . $border . $shade . $spacing . $jc . '</w:pPr>' . $runs . '</w:p>';
	}

	protected function spacer(int $before = 120): string {
		return $this->para('', ['before' => $before, 'after' => 0]);
	}

	protected function pageBreak(): string {
		return '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
	}

	// ── Composite blocks ──────────────────────────────────────────────────────

	protected function titleLine(string $text, int $size, string $color, string $bg, bool $bold = false): string {
		return $this->para(
			$this->run('  ' . $text, ['bold' => $bold, 'size' => $size, 'color' => $color]),
			['shade' => $bg, 'before' => 0, 'after' => 0]
		);
	}

	protected function heading(string $text): string {
		return $this->para(
			$this->run($text, ['bold' => true, 'size' => 13, 'color' => self::COLOR_ACCENT]),
			['border_bottom' => self::COLOR_GREEN, 'border_sz' => 4, 'before' => 280, 'after' => 80]
		);
	}

	// ── Table cell helpers ────────────────────────────────────────────────────

	/**
	 * Build <w:tcPr>.
	 *
	 * Validated child order: tcW → tcBorders → shd → tcMar → vAlign
	 * Validated tcMar order: top → left → bottom → right
	 * Validated tcMar attr order: w:type before w:w
	 */
	protected function tcpr(int $w, ?string $bg = null): string {
		$bdr = '<w:tcBorders>'
		     . '<w:top w:val="single" w:sz="2" w:space="0" w:color="' . self::COLOR_BORDER . '"/>'
		     . '<w:left w:val="single" w:sz="2" w:space="0" w:color="' . self::COLOR_BORDER . '"/>'
		     . '<w:bottom w:val="single" w:sz="2" w:space="0" w:color="' . self::COLOR_BORDER . '"/>'
		     . '<w:right w:val="single" w:sz="2" w:space="0" w:color="' . self::COLOR_BORDER . '"/>'
		     . '</w:tcBorders>';
		$shd = $bg
			? '<w:shd w:val="clear" w:color="auto" w:fill="' . $bg . '"/>'
			: '';
		$mar = '<w:tcMar>'
		     . '<w:top w:type="dxa" w:w="80"/>'
		     . '<w:left w:type="dxa" w:w="140"/>'
		     . '<w:bottom w:type="dxa" w:w="80"/>'
		     . '<w:right w:type="dxa" w:w="140"/>'
		     . '</w:tcMar>';
		return '<w:tcPr>'
		     . '<w:tcW w:w="' . $w . '" w:type="dxa"/>'
		     . $bdr . $shd . $mar
		     . '<w:vAlign w:val="center"/>'
		     . '</w:tcPr>';
	}

	/**
	 * Single-paragraph table cell.
	 */
	protected function tc(string $text, int $w, array $o = []): string {
		$bg   = $o['bg']   ?? null;
		$para = $this->para(
			$this->run($text, [
				'bold'   => $o['bold']   ?? false,
				'italic' => $o['italic'] ?? false,
				'size'   => $o['size']   ?? 9,
				'color'  => $o['color']  ?? self::COLOR_DARK,
			]),
			['align' => $o['align'] ?? 'left', 'before' => 0, 'after' => 0]
		);
		return '<w:tc>' . $this->tcpr($w, $bg) . $para . '</w:tc>';
	}

	/**
	 * Table cell with two paragraphs: main title + smaller path/subtext.
	 */
	protected function tcDouble(string $main, string $sub, int $w, ?string $bg = null): string {
		$p1 = $this->para(
			$this->run($main, ['bold' => true, 'size' => 9, 'color' => self::COLOR_DARK]),
			['before' => 0, 'after' => 0]
		);
		$p2 = $this->para(
			$this->run($sub, ['size' => 7, 'color' => self::COLOR_GRAY]),
			['before' => 20, 'after' => 0]
		);
		return '<w:tc>' . $this->tcpr($w, $bg) . $p1 . $p2 . '</w:tc>';
	}

	protected function th(string $text, int $w): string {
		return $this->tc($text, $w, [
			'bg'    => self::COLOR_HEADER,
			'color' => self::COLOR_WHITE,
			'bold'  => true,
			'size'  => 8,
			'align' => 'center',
		]);
	}

	protected function tr(string $cells, bool $isHeader = false): string {
		$hdr = $isHeader ? '<w:tblHeader/>' : '';
		return '<w:tr><w:trPr>' . $hdr . '</w:trPr>' . $cells . '</w:tr>';
	}

	protected function tblOpen(array $colWidths): string {
		$total = array_sum($colWidths);
		$grid  = implode('', array_map(fn($w) => '<w:gridCol w:w="' . $w . '"/>', $colWidths));
		return '<w:tbl>'
		     . '<w:tblPr>'
		     . '<w:tblW w:w="' . $total . '" w:type="dxa"/>'
		     . '<w:tblLayout w:type="fixed"/>'
		     . '</w:tblPr>'
		     . '<w:tblGrid>' . $grid . '</w:tblGrid>';
	}

	// ── Content tables ────────────────────────────────────────────────────────

	protected function summaryTable(array $rows): string {
		$out = $this->tblOpen([4500, 4500]);
		foreach($rows as [$label, $value]) {
			$out .= $this->tr(
				$this->tc((string) $label, 4500, ['bg' => self::COLOR_GRAY_BG, 'color' => self::COLOR_GRAY, 'size' => 9]) .
				$this->tc((string) $value, 4500, ['bold' => true, 'size' => 9, 'align' => 'right'])
			);
		}
		return $out . '</w:tbl>';
	}

	protected function topPagesTable(array $pages): string {
		$cw  = [3300, 1350, 1350, 1200, 1000, 800];
		$out = $this->tblOpen($cw);
		$out .= $this->tr(
			$this->th('Page',         $cw[0]) .
			$this->th('CO2 avg (mg)', $cw[1]) .
			$this->th('Range (mg)',   $cw[2]) .
			$this->th('Time (ms)',    $cw[3]) .
			$this->th('Size (KB)',    $cw[4]) .
			$this->th('Rating',       $cw[5]),
			true
		);
		foreach($pages as $i => $p) {
			[$rating, $rc] = $this->rating((float)($p['avg_co2'] ?? 0));
			$bg = $i % 2 === 0 ? self::COLOR_WHITE : self::COLOR_GRAY_BG;
			$out .= $this->tr(
				$this->tcDouble(
					(string)($p['page_title'] ?: $p['page_path']),
					(string) $p['page_path'],
					$cw[0], $bg
				) .
				$this->tc((string)($p['avg_co2'] ?? ''), $cw[1], ['bg' => $bg, 'align' => 'right']) .
				$this->tc(($p['min_co2'] ?? '') . ' - ' . ($p['max_co2'] ?? ''), $cw[2], ['bg' => $bg, 'align' => 'right', 'size' => 8, 'color' => self::COLOR_GRAY]) .
				$this->tc((string)($p['avg_ms'] ?? ''), $cw[3], ['bg' => $bg, 'align' => 'right']) .
				$this->tc((string)($p['avg_kb'] ?? ''), $cw[4], ['bg' => $bg, 'align' => 'right']) .
				$this->tc($rating, $cw[5], ['bg' => $rc, 'color' => self::COLOR_WHITE, 'bold' => true, 'align' => 'center'])
			);
		}
		return $out . '</w:tbl>';
	}

	protected function ratingTable(): string {
		$rows = [
			['A', 'A - Excellent', '< 100 mg CO2 per request'],
			['B', 'B - Good',      '100-300 mg CO2 per request'],
			['C', 'C - Improve',   '300-700 mg CO2 per request'],
			['D', 'D - Heavy',     '>= 700 mg CO2 per request'],
		];
		$out = $this->tblOpen([900, 2200, 5900]);
		foreach($rows as [$letter, $label, $desc]) {
			$rc = self::RATING_COLORS[$letter];
			$out .= $this->tr(
				$this->tc($letter, 900,  ['bg' => $rc, 'color' => self::COLOR_WHITE, 'bold' => true, 'align' => 'center']) .
				$this->tc($label,  2200, ['bold' => true]) .
				$this->tc($desc,   5900, ['color' => self::COLOR_GRAY])
			);
		}
		return $out . '</w:tbl>';
	}

	// ── Document body ─────────────────────────────────────────────────────────

	protected function documentXml(): string {
		$d         = $this->data;
		$s24       = $d['summary_24h']     ?? [];
		$sAll      = $d['summary_alltime'] ?? [];
		$pages     = $d['top_pages']       ?? [];
		$retention = (int)($d['retention_days'] ?? 90);
		$intensity = (int)($d['intensity']      ?? 436);

		$date    = date('d F Y', strtotime($d['generated_at'] ?? 'now'));
		$time    = date('H:i',   strtotime($d['generated_at'] ?? 'now'));
		$site    = $d['site_name'] ?? 'Website';
		$url     = $d['site_url']  ?? '';
		$human24 = max(0, (int)($s24['total_requests'] ?? 0) - (int)($s24['bot_requests'] ?? 0));

		[$r24]  = $this->rating((float)($s24['avg_co2_mg']  ?? 0));
		[$rAll] = $this->rating((float)($sAll['avg_co2_mg'] ?? 0));

		$body =
			// Title block
			$this->titleLine('CO2 Emissions Report', 24, self::COLOR_WHITE, self::COLOR_HEADER, true) .
			$this->titleLine($site . ($url ? '  |  ' . $url : ''), 11, 'A7F3D0', self::COLOR_HEADER) .
			$this->titleLine("Generated: {$date} at {$time}  |  Carbon intensity: {$intensity} gCO2/kWh", 9, '6EE7B7', self::COLOR_HEADER) .
			$this->spacer(200) .

			// Last 24 hours
			$this->heading('Last 24 Hours') .
			$this->spacer(80) .
			$this->summaryTable([
				['Total requests',        $s24['total_requests'] ?? '0'],
				['Human requests',        $human24],
				['Bot requests',          $s24['bot_requests']   ?? '0'],
				['CO2 total',             ($s24['total_co2_g']   ?? '0') . ' g'],
				['CO2 per request (avg)', ($s24['avg_co2_mg']    ?? '0') . ' mg'],
				['Rating',                $r24],
				['Avg exec time',         ($s24['avg_ms']        ?? '0') . ' ms'],
				['Avg response size',     ($s24['avg_kb']        ?? '0') . ' KB'],
			]) .

			// All time
			$this->heading('All Time') .
			$this->spacer(80) .
			$this->summaryTable([
				['Total requests',        $sAll['total_requests'] ?? '0'],
				['CO2 total',             self::formatCO2kg((float)($sAll['total_co2_kg'] ?? 0))],
				['CO2 per request (avg)', ($sAll['avg_co2_mg']    ?? '0') . ' mg'],
				['Rating',                $rAll],
				['Collecting since',      $sAll['since']          ?? '-'],
				['Raw data retention',    $retention . ' days'],
				['Carbon intensity',      $intensity . ' gCO2/kWh'],
			]) .

			// Top pages (new page)
			$this->pageBreak() .
			$this->heading("Top 50 Pages by CO2 - last {$retention} days, human requests") .
			$this->spacer(80) .
			$this->topPagesTable($pages) .

			// Rating scale
			$this->heading('Rating Scale') .
			$this->spacer(80) .
			$this->ratingTable() .
			$this->spacer(120) .
			$this->para(
				$this->run(
					'Sustainable Web Design Model v4 (Wholegrain Digital, 2024). '
					. 'Energy = transfer x 0.06 kWh/GB + CPU + RAM. CO2 = energy x carbon intensity.',
					['italic' => true, 'size' => 8, 'color' => self::COLOR_GRAY]
				),
				['before' => 80, 'after' => 0]
			);

		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"
            xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:body>' . $body . '
    <w:sectPr>
      <w:headerReference w:type="default" r:id="rId1"/>
      <w:footerReference w:type="default" r:id="rId2"/>
      <w:pgSz w:w="11906" w:h="16838"/>
      <w:pgMar w:top="1080" w:right="1080" w:bottom="1080" w:left="1080"
               w:header="709" w:footer="709" w:gutter="0"/>
    </w:sectPr>
  </w:body>
</w:document>';
	}

	// ── Header & footer ───────────────────────────────────────────────────────

	/**
	 * Note: w:hdr pPr does NOT support w:spacing or w:pBdr per strict schema.
	 * Header only uses w:jc for alignment.
	 */
	protected function headerXml(): string {
		$site = $this->x($this->data['site_name'] ?? 'Website');
		$date = $this->x(date('d F Y', strtotime($this->data['generated_at'] ?? 'now')));
		$rpr  = '<w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/>'
		      . '<w:sz w:val="16"/><w:szCs w:val="16"/>'
		      . '<w:color w:val="' . self::COLOR_GRAY . '"/>';

		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:hdr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:p>
    <w:pPr><w:jc w:val="right"/></w:pPr>
    <w:r><w:rPr>' . $rpr . '</w:rPr>
      <w:t xml:space="preserve">PageCarbon  |  ' . $site . '  |  ' . $date . '</w:t>
    </w:r>
  </w:p>
</w:hdr>';
	}

	/**
	 * Note: w:ftr pPr also does NOT support w:spacing or w:pBdr.
	 * Footer only uses w:jc. PAGE/NUMPAGES fields rendered via w:fldChar.
	 */
	protected function footerXml(): string {
		$rpr = '<w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/>'
		     . '<w:sz w:val="16"/><w:szCs w:val="16"/>'
		     . '<w:color w:val="' . self::COLOR_GRAY . '"/>';

		$r = fn(string $t) =>
			'<w:r><w:rPr>' . $rpr . '</w:rPr>'
			. '<w:t xml:space="preserve">' . $this->x($t) . '</w:t></w:r>';

		$fld = fn(string $instr) =>
			'<w:r><w:rPr>' . $rpr . '</w:rPr><w:fldChar w:fldCharType="begin"/></w:r>'
			. '<w:r><w:rPr>' . $rpr . '</w:rPr><w:instrText xml:space="preserve"> ' . $instr . ' </w:instrText></w:r>'
			. '<w:r><w:rPr>' . $rpr . '</w:rPr><w:fldChar w:fldCharType="separate"/></w:r>'
			. '<w:r><w:rPr>' . $rpr . '</w:rPr><w:fldChar w:fldCharType="end"/></w:r>';

		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:ftr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:p>
    <w:pPr><w:jc w:val="center"/></w:pPr>
    ' . $r('Generated by PageCarbon for ProcessWire  |  smnv.org  |  Page ')
      . $fld('PAGE')
      . $r(' of ')
      . $fld('NUMPAGES') . '
  </w:p>
</w:ftr>';
	}

	// ── Static XML parts ──────────────────────────────────────────────────────

	protected function contentTypes(): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml"  ContentType="application/xml"/>
  <Override PartName="/word/document.xml"
    ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
  <Override PartName="/word/styles.xml"
    ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>
  <Override PartName="/word/settings.xml"
    ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>
  <Override PartName="/word/theme/theme1.xml"
    ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>
  <Override PartName="/word/header1.xml"
    ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/>
  <Override PartName="/word/footer1.xml"
    ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>
</Types>';
	}

	protected function rootRels(): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1"
    Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"
    Target="word/document.xml"/>
</Relationships>';
	}

	protected function documentRels(): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1"
    Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header"
    Target="header1.xml"/>
  <Relationship Id="rId2"
    Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer"
    Target="footer1.xml"/>
  <Relationship Id="rId3"
    Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"
    Target="styles.xml"/>
  <Relationship Id="rId4"
    Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings"
    Target="settings.xml"/>
  <Relationship Id="rId5"
    Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme"
    Target="theme/theme1.xml"/>
</Relationships>';
	}

	protected function stylesXml(): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:docDefaults>
    <w:rPrDefault>
      <w:rPr>
        <w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/>
        <w:sz w:val="20"/><w:szCs w:val="20"/>
        <w:color w:val="' . self::COLOR_DARK . '"/>
      </w:rPr>
    </w:rPrDefault>
    <w:pPrDefault>
      <w:pPr><w:spacing w:after="0"/></w:pPr>
    </w:pPrDefault>
  </w:docDefaults>
  <w:style w:type="paragraph" w:styleId="Normal" w:default="1">
    <w:name w:val="Normal"/>
  </w:style>
  <w:style w:type="table" w:styleId="TableNormal" w:default="1">
    <w:name w:val="Normal Table"/>
    <w:tblPr><w:tblInd w:w="0" w:type="dxa"/></w:tblPr>
  </w:style>
</w:styles>';
	}

	protected function settingsXml(): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:defaultTabStop w:val="720"/>
  <w:compat>
    <w:compatSetting w:name="compatibilityMode"
      w:uri="http://schemas.microsoft.com/office/word" w:val="15"/>
  </w:compat>
</w:settings>';
	}

	protected function themeXml(): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="PageCarbon">
  <a:themeElements>
    <a:clrScheme name="PageCarbon">
      <a:dk1><a:srgbClr val="111827"/></a:dk1>
      <a:lt1><a:srgbClr val="FFFFFF"/></a:lt1>
      <a:dk2><a:srgbClr val="374151"/></a:dk2>
      <a:lt2><a:srgbClr val="F9FAFB"/></a:lt2>
      <a:accent1><a:srgbClr val="' . self::COLOR_ACCENT        . '"/></a:accent1>
      <a:accent2><a:srgbClr val="' . self::RATING_COLORS['A'] . '"/></a:accent2>
      <a:accent3><a:srgbClr val="' . self::RATING_COLORS['B'] . '"/></a:accent3>
      <a:accent4><a:srgbClr val="' . self::RATING_COLORS['C'] . '"/></a:accent4>
      <a:accent5><a:srgbClr val="' . self::RATING_COLORS['D'] . '"/></a:accent5>
      <a:accent6><a:srgbClr val="' . self::COLOR_GRAY          . '"/></a:accent6>
      <a:hlink><a:srgbClr val="' . self::COLOR_ACCENT . '"/></a:hlink>
      <a:folHlink><a:srgbClr val="' . self::COLOR_GREEN . '"/></a:folHlink>
    </a:clrScheme>
    <a:fontScheme name="PageCarbon">
      <a:majorFont><a:latin typeface="Arial"/><a:ea typeface=""/><a:cs typeface=""/></a:majorFont>
      <a:minorFont><a:latin typeface="Arial"/><a:ea typeface=""/><a:cs typeface=""/></a:minorFont>
    </a:fontScheme>
    <a:fmtScheme name="PageCarbon">
      <a:fillStyleLst>
        <a:solidFill><a:srgbClr val="' . self::COLOR_HEADER . '"/></a:solidFill>
        <a:solidFill><a:srgbClr val="' . self::COLOR_ACCENT . '"/></a:solidFill>
        <a:solidFill><a:srgbClr val="' . self::COLOR_GREEN  . '"/></a:solidFill>
      </a:fillStyleLst>
      <a:lnStyleLst>
        <a:ln w="6350"><a:solidFill><a:srgbClr val="' . self::COLOR_BORDER . '"/></a:solidFill></a:ln>
        <a:ln w="12700"><a:solidFill><a:srgbClr val="' . self::COLOR_GRAY  . '"/></a:solidFill></a:ln>
        <a:ln w="19050"><a:solidFill><a:srgbClr val="374151"/></a:solidFill></a:ln>
      </a:lnStyleLst>
      <a:effectStyleLst>
        <a:effectStyle><a:effectLst/></a:effectStyle>
        <a:effectStyle><a:effectLst/></a:effectStyle>
        <a:effectStyle><a:effectLst/></a:effectStyle>
      </a:effectStyleLst>
      <a:bgFillStyleLst>
        <a:solidFill><a:srgbClr val="FFFFFF"/></a:solidFill>
        <a:solidFill><a:srgbClr val="F9FAFB"/></a:solidFill>
        <a:solidFill><a:srgbClr val="' . self::COLOR_HEADER . '"/></a:solidFill>
      </a:bgFillStyleLst>
    </a:fmtScheme>
  </a:themeElements>
</a:theme>';
	}
}
