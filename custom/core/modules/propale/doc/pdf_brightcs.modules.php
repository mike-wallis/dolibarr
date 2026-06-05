<?php
/**
 * Bright Cleaning Solutions — Quote PDF template
 *
 * Extends Azur. Overrides:
 *   _tableau()     — column headers: "VAT" → "GST", "Price (excl. tax)" → "Price (ex GST)"
 *   _tableau_tot() — totals section: GST labels, AUD $ currency format on amounts
 *
 * Why direct tab_translate injection instead of the custom lang file:
 *   translate.class.php breaks out of the dir-search loop after the first file found,
 *   so htdocs/custom/langs/en_GB/main.lang is never loaded. Direct assignment into
 *   $outputlangs->tab_translate is the only reliable per-render override.
 *
 * Deploy: copy to htdocs/core/modules/propale/doc/   (NOT htdocs/custom/core/ — see deployment.md)
 * Activate: Proposals > Setup > PDF model > select "brightcs"
 */

require_once DOL_DOCUMENT_ROOT.'/core/modules/propale/doc/pdf_azur.modules.php';

class pdf_brightcs extends pdf_azur
{
	public function __construct($db)
	{
		parent::__construct($db);

		$this->name        = "brightcs";
		$this->description = "Bright Cleaning Solutions quote template";
	}


	// ── Column header table ───────────────────────────────────────────────────

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.PublicUnderscore
	protected function _tableau(&$pdf, $tab_top, $tab_height, $nexY, $outputlangs, $hidetop = 0, $hidebottom = 0, $currency = '')
	{
		// phpcs:enable
		$outputlangs->tab_translate['VAT']             = 'GST';
		$outputlangs->tab_translate['PriceUHT']        = 'Price (ex GST)';
		$outputlangs->tab_translate['TotalHTShort']    = 'Amount (ex GST)';
		$outputlangs->tab_translate['AmountInCurrency'] = ' '; // suppress "Amount in AU Dollars currency" header
		parent::_tableau($pdf, $tab_top, $tab_height, $nexY, $outputlangs, $hidetop, $hidebottom, $currency);
	}


	// ── Totals block ──────────────────────────────────────────────────────────

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.PublicUnderscore
	protected function _tableau_tot(&$pdf, $object, $deja_regle, $posy, $outputlangs, $outputlangsbis = null)
	{
		// phpcs:enable
		global $mysoc;

		$default_font_size = pdf_getPDFFontSize($outputlangs);

		$tab2_top = $posy;
		$tab2_hl  = 4;
		$pdf->SetFont('', '', $default_font_size - 1);

		$col1x    = 120;
		$col2x    = 170;
		if ($this->page_largeur < 210) {
			$col2x -= 20;
		}
		$largcol2 = ($this->page_largeur - $this->marge_droite - $col2x);

		$useborder = 0;
		$index     = 0;

		$total_ht = (isModEnabled("multicurrency") && $object->multicurrency_tx != 1 ? $object->multicurrency_total_ht : $object->total_ht);

		// Total discount
		$total_discount_on_lines              = 0;
		$multicurrency_total_discount_on_lines = 0;
		foreach ($object->lines as $i => $line) {
			$resdiscount               = pdfGetLineTotalDiscountAmount($object, $i, $outputlangs, 2);
			$multicurrency_resdiscount = pdfGetLineTotalDiscountAmount($object, $i, $outputlangs, 2, 1);

			$total_discount_on_lines               += (is_numeric($resdiscount) ? $resdiscount : 0);
			$multicurrency_total_discount_on_lines += (is_numeric($multicurrency_resdiscount) ? $multicurrency_resdiscount : 0);
			if ($line->total_ht < 0) {
				$total_discount_on_lines               += -$line->total_ht;
				$multicurrency_total_discount_on_lines += -$line->multicurrency_total_ht;
			}
		}

		if ($total_discount_on_lines > 0) {
			$pdf->SetFillColor(255, 255, 255);
			$pdf->SetXY($col1x, $tab2_top);
			$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("TotalHTBeforeDiscount").(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transnoentities("TotalHTBeforeDiscount") : ''), 0, 'L', true);
			$pdf->SetXY($col2x, $tab2_top);
			$total_before_discount_to_show = ((isModEnabled("multicurrency") && $object->multicurrency_tx != 1) ? ($object->multicurrency_total_ht + $multicurrency_total_discount_on_lines) : ($object->total_ht + $total_discount_on_lines));
			$pdf->MultiCell($largcol2, $tab2_hl, '$'.price($total_before_discount_to_show, 0, $outputlangs), 0, 'R', true);
			$index++;

			$pdf->SetFillColor(255, 255, 255);
			$pdf->SetXY($col1x, $tab2_top + $tab2_hl);
			$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("TotalDiscount").(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transnoentities("TotalDiscount") : ''), 0, 'L', true);
			$pdf->SetXY($col2x, $tab2_top + $tab2_hl);
			$total_discount_to_show = ((isModEnabled("multicurrency") && $object->multicurrency_tx != 1) ? $multicurrency_total_discount_on_lines : $total_discount_on_lines);
			$pdf->MultiCell($largcol2, $tab2_hl, '$'.price($total_discount_to_show, 0, $outputlangs), 0, 'R', true);
			$index++;
		}

		// Total excl. GST
		$pdf->SetFillColor(255, 255, 255);
		$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
		$pdf->MultiCell($col2x - $col1x, $tab2_hl, 'Total (excl. GST)', 0, 'L', true);

		$total_ht = ((isModEnabled("multicurrency") && isset($object->multicurrency_tx) && $object->multicurrency_tx != 1) ? $object->multicurrency_total_ht : $object->total_ht);
		$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
		$pdf->MultiCell($largcol2, $tab2_hl, '$'.price($total_ht + (!empty($object->remise) ? $object->remise : 0), 0, $outputlangs), 0, 'R', true);

		// GST rows
		$pdf->SetFillColor(248, 248, 248);

		$total_ttc = (isModEnabled("multicurrency") && $object->multicurrency_tx != 1) ? $object->multicurrency_total_ttc : $object->total_ttc;

		$this->atleastoneratenotnull = 0;
		if (!getDolGlobalString('MAIN_GENERATE_DOCUMENTS_WITHOUT_VAT')) {
			$tvaisnull = (!empty($this->tva) && count($this->tva) == 1 && isset($this->tva['0.000']) && is_float($this->tva['0.000']));
			if (getDolGlobalString('MAIN_GENERATE_DOCUMENTS_WITHOUT_VAT_IFNULL') && $tvaisnull) {
				// nothing
			} else {
				// Local tax 1 before VAT
				foreach ($this->localtax1 as $localtax_type => $localtax_rate) {
					if (in_array((string) $localtax_type, array('1', '3', '5'))) {
						continue;
					}
					foreach ($localtax_rate as $tvakey => $tvaval) {
						if ($tvakey != 0) {
							$index++;
							$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
							$tvacompl = '';
							if (preg_match('/\*/', $tvakey)) {
								$tvakey   = str_replace('*', '', $tvakey);
								$tvacompl = " (".$outputlangs->transnoentities("NonPercuRecuperable").")";
							}
							$totalvat = $outputlangs->transcountrynoentities("TotalLT1", $mysoc->country_code).' ';
							if (getDolGlobalString('PDF_LOCALTAX1_LABEL_IS_CODE_OR_RATE') == 'nocodenorate') {
								$totalvat .= $tvacompl;
							} else {
								$totalvat .= vatrate((string) abs((float) $tvakey), true).$tvacompl;
							}
							$pdf->MultiCell($col2x - $col1x, $tab2_hl, $totalvat, 0, 'L', true);
							$total_localtax = ((isModEnabled("multicurrency") && isset($object->multicurrency_tx) && $object->multicurrency_tx != 1) ? price2num($tvaval * $object->multicurrency_tx, 'MT') : $tvaval);
							$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
							$pdf->MultiCell($largcol2, $tab2_hl, '$'.price($total_localtax, 0, $outputlangs), 0, 'R', true);
						}
					}
				}

				// Local tax 2 before VAT
				foreach ($this->localtax2 as $localtax_type => $localtax_rate) {
					if (in_array((string) $localtax_type, array('1', '3', '5'))) {
						continue;
					}
					foreach ($localtax_rate as $tvakey => $tvaval) {
						if ($tvakey != 0) {
							$index++;
							$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
							$tvacompl = '';
							if (preg_match('/\*/', $tvakey)) {
								$tvakey   = str_replace('*', '', $tvakey);
								$tvacompl = " (".$outputlangs->transnoentities("NonPercuRecuperable").")";
							}
							$totalvat = $outputlangs->transcountrynoentities("TotalLT2", $mysoc->country_code).' ';
							if (getDolGlobalString('PDF_LOCALTAX2_LABEL_IS_CODE_OR_RATE') == 'nocodenorate') {
								$totalvat .= $tvacompl;
							} else {
								$totalvat .= vatrate((string) abs((float) $tvakey), true).$tvacompl;
							}
							$pdf->MultiCell($col2x - $col1x, $tab2_hl, $totalvat, 0, 'L', true);
							$total_localtax = ((isModEnabled("multicurrency") && isset($object->multicurrency_tx) && $object->multicurrency_tx != 1) ? price2num($tvaval * $object->multicurrency_tx, 'MT') : $tvaval);
							$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
							$pdf->MultiCell($largcol2, $tab2_hl, '$'.price($total_localtax, 0, $outputlangs), 0, 'R', true);
						}
					}
				}

				// GST by rate
				foreach ($this->tva_array as $tvakey => $tvaval) {
					if ($tvakey != 0) {
						$this->atleastoneratenotnull++;
						$index++;
						$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
						$tvacompl = '';
						if (preg_match('/\*/', $tvakey)) {
							$tvakey   = str_replace('*', '', $tvakey);
							$tvacompl = " (".$outputlangs->transnoentities("NonPercuRecuperable").")";
						}
						// ── hardcoded "Total GST" — bypasses translate load-order issue ──
						$totalvat = 'Total GST ';
						if (getDolGlobalString('PDF_VAT_LABEL_IS_CODE_OR_RATE') == 'rateonly') {
							$totalvat .= vatrate((string) $tvaval['vatrate'], true).$tvacompl;
						} elseif (getDolGlobalString('PDF_VAT_LABEL_IS_CODE_OR_RATE') == 'codeonly') {
							$totalvat .= $tvaval['vatcode'].$tvacompl;
						} elseif (getDolGlobalString('PDF_VAT_LABEL_IS_CODE_OR_RATE') == 'nocodenorate') {
							$totalvat .= $tvacompl;
						} else {
							$totalvat .= vatrate((string) $tvaval['vatrate'], true).($tvaval['vatcode'] ? ' ('.$tvaval['vatcode'].')' : '').$tvacompl;
						}
						$pdf->MultiCell($col2x - $col1x, $tab2_hl, $totalvat, 0, 'L', true);
						$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
						$pdf->MultiCell($largcol2, $tab2_hl, '$'.price(price2num($tvaval['amount'], 'MT'), 0, $outputlangs), 0, 'R', true);
					}
				}

				// Local tax 1 after VAT
				foreach ($this->localtax1 as $localtax_type => $localtax_rate) {
					if (in_array((string) $localtax_type, array('2', '4', '6'))) {
						continue;
					}
					foreach ($localtax_rate as $tvakey => $tvaval) {
						if ($tvakey != 0) {
							$index++;
							$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
							$tvacompl = '';
							if (preg_match('/\*/', $tvakey)) {
								$tvakey   = str_replace('*', '', $tvakey);
								$tvacompl = " (".$outputlangs->transnoentities("NonPercuRecuperable").")";
							}
							$totalvat = $outputlangs->transcountrynoentities("TotalLT1", $mysoc->country_code).' ';
							if (getDolGlobalString('PDF_LOCALTAX1_LABEL_IS_CODE_OR_RATE') == 'nocodenorate') {
								$totalvat .= $tvacompl;
							} else {
								$totalvat .= vatrate((string) abs((float) $tvakey), true).$tvacompl;
							}
							$pdf->MultiCell($col2x - $col1x, $tab2_hl, $totalvat, 0, 'L', true);
							$total_localtax = ((isModEnabled("multicurrency") && $object->multicurrency_tx != 1) ? price2num($tvaval * $object->multicurrency_tx, 'MT') : $tvaval);
							$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
							$pdf->MultiCell($largcol2, $tab2_hl, '$'.price($total_localtax, 0, $outputlangs), 0, 'R', true);
						}
					}
				}

				// Local tax 2 after VAT
				foreach ($this->localtax2 as $localtax_type => $localtax_rate) {
					if (in_array((string) $localtax_type, array('2', '4', '6'))) {
						continue;
					}
					foreach ($localtax_rate as $tvakey => $tvaval) {
						if ($tvakey != 0) {
							$index++;
							$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
							$tvacompl = '';
							if (preg_match('/\*/', $tvakey)) {
								$tvakey   = str_replace('*', '', $tvakey);
								$tvacompl = " (".$outputlangs->transnoentities("NonPercuRecuperable").")";
							}
							$totalvat = $outputlangs->transcountrynoentities("TotalLT2", $mysoc->country_code).' ';
							if (getDolGlobalString('PDF_LOCALTAX2_LABEL_IS_CODE_OR_RATE') == 'nocodenorate') {
								$totalvat .= $tvacompl;
							} else {
								$totalvat .= vatrate((string) abs((float) $tvakey), true).$tvacompl;
							}
							$pdf->MultiCell($col2x - $col1x, $tab2_hl, $totalvat, 0, 'L', true);
							$total_localtax = ((isModEnabled("multicurrency") && $object->multicurrency_tx != 1) ? price2num($tvaval * $object->multicurrency_tx, 'MT') : $tvaval);
							$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
							$pdf->MultiCell($largcol2, $tab2_hl, '$'.price($total_localtax, 0, $outputlangs), 0, 'R', true);
						}
					}
				}

				// Total incl. GST
				$index++;
				$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
				$pdf->SetTextColor(0, 0, 60);
				$pdf->SetFillColor(224, 224, 224);
				$pdf->MultiCell($col2x - $col1x, $tab2_hl, 'Total (inc. GST)', $useborder, 'L', true);
				$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
				$pdf->MultiCell($largcol2, $tab2_hl, '$'.price($total_ttc, 0, $outputlangs), $useborder, 'R', true);
			}
		}

		$pdf->SetTextColor(0, 0, 0);

		if ($deja_regle > 0) {
			$index++;
			$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
			$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("AlreadyPaid"), 0, 'L', false);
			$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
			$pdf->MultiCell($largcol2, $tab2_hl, '$'.price($deja_regle, 0, $outputlangs), 0, 'R', false);

			$index++;
			$pdf->SetTextColor(0, 0, 60);
			$pdf->SetFillColor(224, 224, 224);
			$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
			$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("RemainderToPay"), $useborder, 'L', true);
			$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
			$pdf->MultiCell($largcol2, $tab2_hl, '$'.price(0, 0, $outputlangs), $useborder, 'R', true);

			$pdf->SetFont('', '', $default_font_size - 1);
			$pdf->SetTextColor(0, 0, 0);
		}

		$index++;
		return ($tab2_top + ($tab2_hl * $index));
	}
}
