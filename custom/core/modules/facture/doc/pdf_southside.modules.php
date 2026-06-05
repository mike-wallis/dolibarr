<?php
/**
 * South Side Supplies — custom invoice PDF template.
 * Extends pdf_brightcs (same layout, different brand constants).
 * Brand values are read from llx_const (Home → Setup → Other setup).
 *
 * File: htdocs/core/modules/facture/doc/pdf_southside.modules.php
 * DB:   INSERT INTO llx_document_model (nom, type, entity) VALUES ('southside', 'invoice', 1)
 */

require_once DOL_DOCUMENT_ROOT . '/core/modules/facture/doc/pdf_brightcs.modules.php';

class pdf_southside extends pdf_brightcs
{
	// SSS brand colour — change R,G,B values here to match SSS brand guidelines
	const CLR_GREEN = [41, 128, 185];  // blue (update if SSS has a specific colour)

	protected $brand_prefix = 'SSS';

	public function __construct($db)
	{
		parent::__construct($db);

		$this->name        = 'southside';
		$this->description = 'South Side Supplies invoice';
	}

	protected function brand_defaults(): array
	{
		return [
			'NAME'      => 'South Side Supplies Pty Ltd',
			'ADDR1'     => '70 Brisbane Corso',
			'ADDR2'     => 'Fairfield QLD 4103',
			'PHONE'     => '0431 779 857',
			'EMAIL'     => 'southsidesupplies.yes@gmail.com',
			'BSB'       => '182-512',
			'ACC'       => '000974446429',
			'TAGLINE'   => 'Fast local delivery',
			'OWNERSHIP' => 'Ownership of the goods does not pass until payment is received in full.',
			'LOGO'      => 'southside_logo.png',
		];
	}


	// ── Left column: early payment box below Payment Terms ───────────────────────

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.PublicUnderscore
	protected function _tableau_info(&$pdf, $object, $posy, $outputlangs, $outputlangsbis)
	{
		// phpcs:enable
		$posy = parent::_tableau_info($pdf, $object, $posy, $outputlangs, $outputlangsbis);

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
	protected function _tableau_tot(&$pdf, $object, $deja_regle, $posy, $outputlangs, $outputlangsbis)
	{
		// phpcs:enable
		global $conf, $mysoc;

		// Fall back to standard layout if no discount cache (non-SSS invoice or trigger not yet run)
		if (empty($this->disc_lines_cache)) {
			return parent::_tableau_tot($pdf, $object, $deja_regle, $posy, $outputlangs, $outputlangsbis);
		}

		$sign = 1;
		if ($object->type == 2 && getDolGlobalString('INVOICE_POSITIVE_CREDIT_NOTE')) {
			$sign = -1;
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
		$pdf->MultiCell($lcol2, $hl, $this->bcs_price($subtotal, $sign), 0, 'R', true);
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
			$pdf->SetTextColor($applies || $def['always'] ? 0 : 160, 0, 0);
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
		$pdf->MultiCell($lcol2, $hl, $this->bcs_price($total_ht, $sign), 0, 'R', true);
		$idx++;

		// ── GST ───────────────────────────────────────────────────────────────
		// Use $object->total_tva (DB value) rather than tva_array.
		// tva_array is built without DISC- lines, so it doesn't reflect the GST reduction.
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
			$pdf->MultiCell($lcol2, $hl, $this->bcs_price($total_ttc, $sign), 0, 'R', true);
			$idx++;
		}

		// ── Remainder to pay (after credits/payments) ─────────────────────────
		$pdf->SetTextColor(0, 0, 0);

		$creditnoteamount = $object->getSumCreditNotesUsed((isModEnabled('multicurrency') && $object->multicurrency_tx != 1) ? 1 : 0);
		$depositsamount   = $object->getSumDepositsUsed((isModEnabled('multicurrency') && $object->multicurrency_tx != 1) ? 1 : 0);
		$resteapayer      = price2num($total_ttc - $deja_regle - $creditnoteamount - $depositsamount, 'MT');
		if (!empty($object->paye)) {
			$resteapayer = 0;
		}

		if (($deja_regle > 0 || $creditnoteamount > 0 || $depositsamount > 0) && !getDolGlobalString('INVOICE_NO_PAYMENT_DETAILS')) {
			$pdf->SetFillColor(224, 224, 224);
			$pdf->SetTextColor(0, 0, 60);
			$row = $posy + $hl * $idx;
			$pdf->SetXY($col1x, $row);
			$pdf->MultiCell($col2x - $col1x, $hl, $outputlangs->transnoentities('RemainderToPay'), 0, 'L', true);
			$pdf->SetXY($col2x, $row);
			$pdf->MultiCell($lcol2, $hl, $this->bcs_price($resteapayer), 0, 'R', true);
			$pdf->SetTextColor(0, 0, 0);
			$idx++;
		}

		$pdf->SetTextColor(0, 0, 0);
		$pdf->SetFont('', '', $fs - 1);

		return $posy + ($hl * $idx);
	}


	// ── Page footer — bank details left, ownership + tagline + page number ──────

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.PublicUnderscore
	protected function _pagefoot(&$pdf, $object, $outputlangs, $hidefreetext = 0, $heightforqrinvoice = 0)
	{
		// phpcs:enable
		$fs   = pdf_getPDFFontSize($outputlangs);
		$ml   = $this->marge_gauche;
		$mr   = $this->marge_droite;
		$pw   = $this->page_largeur;
		$ph   = $this->page_hauteur;
		$mb   = $this->marge_basse;
		$usew = $pw - $ml - $mr;

		$footH = 22;
		$footY = $ph - $mb - $footH;

		$pdf->SetDrawColor(...static::CLR_GREEN);
		$pdf->SetLineWidth(0.4);
		$pdf->Line($ml, $footY, $pw - $mr, $footY);
		$pdf->SetLineWidth(0.2);
		$pdf->SetDrawColor(0, 0, 0);

		// Bank details — single line: bold heading then account numbers
		$pdf->SetFont('', 'B', $fs);
		$pdf->SetTextColor(...static::CLR_GREEN);
		$pdf->SetXY($ml, $footY + 2);
		$headW = $pdf->GetStringWidth('BANK ACCOUNT DETAILS') + 2;
		$pdf->Cell($headW, 5, 'BANK ACCOUNT DETAILS', 0, 0, 'L');

		$pdf->SetFont('', '', $fs - 1);
		$pdf->SetTextColor(...static::CLR_DARK);
		$pdf->Cell($usew - $headW, 5, '   BSB: ' . $this->brand('BSB') . '   Acc. No: ' . $this->brand('ACC'), 0, 1, 'L');

		// Ownership + tagline + page number
		$pdf->SetFont('', 'I', $fs - 2);
		$pdf->SetTextColor(80, 80, 80);
		$pdf->SetXY($ml, $footY + 10);
		$pdf->Cell($usew, 4, $this->brand('OWNERSHIP'), 0, 1, 'C');

		$pdf->SetFont('', 'B', $fs);
		$pdf->SetTextColor(...static::CLR_GREEN);
		$pdf->SetXY($ml, $footY + 15);
		$pdf->Cell($usew, 4, $this->brand('TAGLINE'), 0, 1, 'C');

		$pdf->SetFont('', '', $fs - 2);
		$pdf->SetTextColor(120, 120, 120);
		$pdf->SetXY($ml, $footY + 15);
		$pdf->Cell($usew, 4, $pdf->getAliasNumPage() . '/' . $pdf->getAliasNbPages(), 0, 0, 'R');

		$pdf->SetTextColor(0, 0, 0);

		return $mb + $footH;
	}
}
