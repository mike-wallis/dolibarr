<?php
/**
 * South Side Supplies — Quote (Commercial Proposal) PDF template.
 * Extends pdf_brightcs (propale) — same layout, different brand constants,
 * plus the SSS-specific early-payment discount box and tiered discount
 * breakdown, mirroring the SSS invoice template.
 * Brand values are read from llx_const (Home → Setup → Other setup).
 *
 * File: htdocs/core/modules/propale/doc/pdf_southside.modules.php
 * DB:   INSERT INTO llx_document_model (nom, type, entity) VALUES ('southside', 'propal', 1)
 */

require_once DOL_DOCUMENT_ROOT . '/core/modules/propale/doc/pdf_brightcs.modules.php';

class pdf_southside extends pdf_brightcs
{
	// SSS brand colour — change R,G,B values here to match SSS brand guidelines
	const CLR_GREEN = [41, 128, 185];  // blue (update if SSS has a specific colour)

	protected $brand_prefix = 'SSS';

	// Cache for DISC- lines extracted before parent::write_file() so
	// _tableau_tot() can access them without them appearing in the line table.
	protected $disc_lines_cache = [];

	public function __construct($db)
	{
		parent::__construct($db);

		$this->name        = 'southside';
		$this->description = 'South Side Supplies quote template';
	}

	protected function brand_defaults(): array
	{
		return [
			'NAME'      => 'South Side Supplies Pty Ltd',
			'ADDR1'     => '70 Brisbane Corso',
			'ADDR2'     => 'Fairfield QLD 4103',
			'PHONE'     => '0431 779 857',
			'EMAIL'     => 'southsidesupplies.yes@gmail.com',
			'TAGLINE'   => 'Fast local delivery',
			'OWNERSHIP' => 'Ownership of the goods does not pass until payment is received in full.',
			'LOGO'      => 'southside_logo.png',
		];
	}


	// ── Extract DISC- lines before rendering ──────────────────────────────────

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	public function write_file($object, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
	{
		// phpcs:enable

		// Extract DISC- lines from the body table into cache. They are hidden from
		// the line items table and shown in the totals block instead (_tableau_tot()
		// below) — same technique as the SSS invoice template. Must happen before
		// parent::write_file() so pdf_azur doesn't render them as body lines.
		$this->disc_lines_cache = [];
		foreach ($object->lines as $i => $line) {
			if (strpos((string) ($line->label ?? ''), 'DISC-') === 0) {
				$this->disc_lines_cache[$line->label] = $line;
				unset($object->lines[$i]);
			}
		}
		$object->lines = array_values($object->lines);

		$result = parent::write_file($object, $outputlangs, $srctemplatepath, $hidedetails, $hidedesc, $hideref);

		// Restore DISC- lines (so re-running write_file() on the same in-memory
		// object, e.g. a second document format, still sees the full line set)
		foreach ($this->disc_lines_cache as $line) {
			$object->lines[] = $line;
		}

		return $result;
	}


	// ── Left column: early payment box below Payment Terms ───────────────────────

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.PublicUnderscore
	protected function _tableau_info(&$pdf, $object, $posy, $outputlangs)
	{
		// phpcs:enable
		$posy = parent::_tableau_info($pdf, $object, $posy, $outputlangs);

		$fs   = pdf_getPDFFontSize($outputlangs);
		$ml   = $this->marge_gauche;
		$boxW = 88; // fixed width — narrower than the full left column

		$early_pct   = (float) getDolGlobalString('SSS_DISC_EARLY', '2.5') / 100;
		$early_days  = (int)   getDolGlobalString('SSS_EARLY_DAYS', '7');
		$early_save  = round($object->total_ttc * $early_pct, 2);
		$early_total = round($object->total_ttc - $early_save, 2);
		$early_date  = dol_time_plus_duree($object->date, $early_days, 'd');

		$boxH = 22;
		$pdf->SetDrawColor(...static::CLR_GREEN);
		$pdf->SetLineWidth(0.5);
		$pdf->Rect($ml, $posy, $boxW, $boxH);
		$pdf->SetLineWidth(0.2);

		$epct_label = rtrim(rtrim(number_format($early_pct * 100, 2, '.', ''), '0'), '.');
		$pdf->SetFont('', 'B', $fs + 1);
		$pdf->SetTextColor(...static::CLR_GREEN);
		$pdf->SetXY($ml + 2, $posy + 2);
		$pdf->Cell($boxW - 4, 5, $epct_label . '% EARLY PAYMENT DISCOUNT', 0, 1, 'L');

		$pdf->SetFont('', '', $fs + 1);
		$pdf->SetTextColor(...static::CLR_DARK);
		$pdf->SetXY($ml + 2, $posy + 8);
		$pdf->Cell($boxW - 4, 5, 'Pay by ' . dol_print_date($early_date, 'day', false, $outputlangs), 0, 1, 'L');

		$pdf->SetFont('', 'B', $fs + 2);
		$pdf->SetTextColor(...static::CLR_GREEN);
		$pdf->SetXY($ml + 2, $posy + 14);
		$pdf->Cell($boxW - 4, 6, $this->bcs_price($early_total) . '   (save ' . $this->bcs_price($early_save) . ')', 0, 1, 'L');

		$pdf->SetTextColor(0, 0, 0);

		return $posy + $boxH + 2;
	}


	// ── Totals block with SSS discount breakdown ──────────────────────────────

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.PublicUnderscore
	protected function _tableau_tot(&$pdf, $object, $deja_regle, $posy, $outputlangs, $outputlangsbis = null)
	{
		// phpcs:enable
		global $mysoc;

		// Fall back to standard layout if no discount cache (non-SSS quote or no DISC- lines)
		if (empty($this->disc_lines_cache)) {
			return parent::_tableau_tot($pdf, $object, $deja_regle, $posy, $outputlangs, $outputlangsbis);
		}

		$fs    = pdf_getPDFFontSize($outputlangs);
		$hl    = 4; // row height mm
		$col1x = 120;
		$col2x = 170;
		$lcol2 = $this->page_largeur - $this->marge_droite - $col2x;
		$idx   = 0;

		// $object->lines contains only product lines here (DISC- were extracted in write_file)
		$total_ht  = (isModEnabled('multicurrency') && $object->multicurrency_tx != 1) ? $object->multicurrency_total_ht  : $object->total_ht;
		$total_ttc = (isModEnabled('multicurrency') && $object->multicurrency_tx != 1) ? $object->multicurrency_total_ttc : $object->total_ttc;

		$pdf->SetFont('', '', $fs - 1);

		// ── Subtotal (product lines, before discounts) ────────────────────────
		$subtotal = 0.0;
		foreach ($object->lines as $ln) {
			$subtotal += (float) $ln->total_ht;
		}

		$pdf->SetFillColor(255, 255, 255);
		$pdf->SetTextColor(0, 0, 0);
		$row = $posy + $hl * $idx;
		$pdf->SetXY($col1x, $row);
		$pdf->MultiCell($col2x - $col1x, $hl, 'Subtotal', 0, 'L', true);
		$pdf->SetXY($col2x, $row);
		$pdf->MultiCell($lcol2, $hl, $this->bcs_price($subtotal), 0, 'R', true);
		$idx++;

		// ── Discount rows — percentages read from llx_const ─────────────────────
		$cache           = $this->disc_lines_cache;
		$large_order_min = (float) getDolGlobalString('SSS_LARGE_ORDER_MIN', '150');
		$zpct = rtrim(rtrim(number_format((float) getDolGlobalString('SSS_DISC_ZONE',   '2.5'), 2, '.', ''), '0'), '.');
		$lpct = rtrim(rtrim(number_format((float) getDolGlobalString('SSS_DISC_LARGE',  '5'),   2, '.', ''), '0'), '.');
		$mpct = rtrim(rtrim(number_format((float) getDolGlobalString('SSS_DISC_MEMBER', '10'),  2, '.', ''), '0'), '.');

		$disc_defs = [
			'DISC-ZONE'   => ['label' => 'Delivery Zone ' . $zpct . '%',  'always' => true,  'reason' => ''],
			'DISC-LARGE'  => ['label' => 'Large Order ' . $lpct . '%',     'always' => false, 'reason' => 'order < $' . number_format($large_order_min, 0, '.', ',')],
			'DISC-MEMBER' => ['label' => 'Member Discount ' . $mpct . '%', 'always' => false, 'reason' => 'not a member'],
		];

		foreach ($disc_defs as $key => $def) {
			$line    = $cache[$key] ?? null;
			$amt     = $line ? (float) $line->total_ht : 0.0; // negative in DB
			$applies = ($line && abs($amt) > 0.001);

			$row        = $posy + $hl * $idx;
			$full_label = $def['label'] . (!$applies && !$def['always'] ? ' (' . $def['reason'] . ')' : '');

			$pdf->SetFillColor(255, 255, 255);
			if (!$applies && !$def['always']) {
				$pdf->SetTextColor(160, 160, 160);
			} else {
				$pdf->SetTextColor(0, 0, 0);
			}
			$pdf->SetXY($col1x, $row);
			$pdf->Cell($col2x - $col1x, $hl, $full_label, 0, 0, 'L', true);

			// Strikethrough over just the base label text (not the reason in parens)
			if (!$applies && !$def['always']) {
				$sw = min($pdf->GetStringWidth($def['label']), $col2x - $col1x - 2);
				$sy = $row + ($hl * 0.55);
				$pdf->SetDrawColor(160, 160, 160);
				$pdf->SetLineWidth(0.15);
				$pdf->Line($col1x, $sy, $col1x + $sw, $sy);
				$pdf->SetDrawColor(0, 0, 0);
				$pdf->SetLineWidth(0.2);
			}

			$pdf->SetXY($col2x, $row);
			if ($applies || $def['always']) {
				$pdf->Cell($lcol2, $hl, $this->bcs_price($amt), 0, 0, 'R', true);
			} else {
				$pdf->SetTextColor(160, 160, 160);
				$pdf->Cell($lcol2, $hl, '$0.00', 0, 0, 'R', true);
			}
			$pdf->SetTextColor(0, 0, 0);
			$idx++;
		}

		// Thin separator before totals
		$sy = $posy + $hl * $idx - 0.5;
		$pdf->SetDrawColor(180, 180, 180);
		$pdf->SetLineWidth(0.15);
		$pdf->Line($col1x, $sy, $this->page_largeur - $this->marge_droite, $sy);
		$pdf->SetDrawColor(0, 0, 0);
		$pdf->SetLineWidth(0.2);

		// ── Total (excl. GST) ──────────────────────────────────────────────────
		$pdf->SetFillColor(248, 248, 248);
		$pdf->SetTextColor(0, 0, 0);
		$row = $posy + $hl * $idx;
		$pdf->SetXY($col1x, $row);
		$pdf->MultiCell($col2x - $col1x, $hl, $outputlangs->transnoentities('TotalHT'), 0, 'L', true);
		$pdf->SetXY($col2x, $row);
		$pdf->MultiCell($lcol2, $hl, $this->bcs_price($total_ht), 0, 'R', true);
		$idx++;

		// ── GST ───────────────────────────────────────────────────────────────
		// Use $object->total_tva (DB value) rather than tva_array. tva_array is
		// built without DISC- lines, so it doesn't reflect the GST reduction.
		if (!getDolGlobalString('MAIN_GENERATE_DOCUMENTS_WITHOUT_VAT')) {
			$gst_label = $outputlangs->transcountrynoentities('TotalVAT', $mysoc->country_code) . ' 10%';
			$row = $posy + $hl * $idx;
			$pdf->SetXY($col1x, $row);
			$pdf->MultiCell($col2x - $col1x, $hl, $gst_label, 0, 'L', true);
			$pdf->SetXY($col2x, $row);
			$pdf->MultiCell($lcol2, $hl, $this->bcs_price($object->total_tva), 0, 'R', true);
			$idx++;

			// Total incl. GST
			$pdf->SetTextColor(0, 0, 60);
			$pdf->SetFillColor(224, 224, 224);
			$row = $posy + $hl * $idx;
			$pdf->SetXY($col1x, $row);
			$pdf->MultiCell($col2x - $col1x, $hl, $outputlangs->transnoentities('TotalTTC'), 0, 'L', true);
			$pdf->SetXY($col2x, $row);
			$pdf->MultiCell($lcol2, $hl, $this->bcs_price($total_ttc), 0, 'R', true);
			$idx++;
		}

		$pdf->SetTextColor(0, 0, 0);
		$pdf->SetFont('', '', $fs - 1);

		return $posy + ($hl * $idx);
	}
}
