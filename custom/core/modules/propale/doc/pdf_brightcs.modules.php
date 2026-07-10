<?php
/**
 * Bright Cleaning Solutions — Quote (Commercial Proposal) PDF template.
 * Same visual design as the BCS invoice/PO templates: logo top-left, brand
 * heading top-right, ABN/DATE/QUOTE# table, TO + QUOTE DETAILS boxes, and
 * reordered GST/Price/Qty columns (via the invoicelines hook module).
 * Extends pdf_azur for the underlying line-drawing/pagination engine.
 * Brand values are read from BCS_* constants in llx_const
 * (Home → Setup → Other setup) — no code changes needed to update them.
 *
 * File: htdocs/core/modules/propale/doc/pdf_brightcs.modules.php
 * DB:   INSERT INTO llx_document_model (nom, type, entity) VALUES ('brightcs', 'propal', 1)
 * Activate: Proposals > Setup > PDF model > select "brightcs"
 */

require_once DOL_DOCUMENT_ROOT . '/core/modules/propale/doc/pdf_azur.modules.php';

class pdf_brightcs extends pdf_azur
{
	const CLR_GREEN   = [44, 138, 62];
	const CLR_DARK    = [30, 30, 30];
	const CLR_HDRFILL = [230, 230, 230];

	// ── Brand helpers (mirrors pdf_brightcs for facture/supplier_order) ────────

	protected $brand_prefix = 'BCS';

	protected function brand_defaults(): array
	{
		return [
			'NAME'      => 'Bright Cleaning Solutions Pty Ltd',
			'ADDR1'     => '70 Brisbane Corso',
			'ADDR2'     => 'Fairfield QLD 4103',
			'PHONE'     => '0401 130 096',
			'EMAIL'     => 'accounts@brightcs.com.au',
			'TAGLINE'   => 'Great Products, Great Service and Really Looking after our Customers.',
			'OWNERSHIP' => 'Ownership of the goods does not pass until payment is received in full.',
			'LOGO'      => '',
		];
	}

	protected function brand(string $key): string
	{
		$val = getDolGlobalString($this->brand_prefix . '_' . $key);
		if ($val !== '') {
			return $val;
		}
		$d = $this->brand_defaults();
		return $d[$key] ?? '';
	}

	protected function bcs_price(float $amount): string
	{
		return ($amount < 0 ? '-$' : '$') . number_format(abs($amount), 2, '.', ',');
	}


	// ── Constructor ──────────────────────────────────────────────────────────────

	public function __construct($db)
	{
		parent::__construct($db);

		$this->name        = 'brightcs';
		$this->description = 'Bright Cleaning Solutions quote template';

		// Column positions (mm from left edge of page) — same scheme as the BCS/SSS
		// invoice and Purchase Order templates. ActionsInvoicelines (custom/modules/
		// invoicelines/) hooks pdf_getlinevatrate/pdf_getlineupexcltax/pdf_getlineqty
		// so the values PRINTED at these slots read Price | Qty | GST, not the
		// GST%/Price/Qty order core's write_file() normally draws — see that class's
		// docblock. The widths below are sized for that printed content.
		$this->posxdesc     = $this->marge_gauche + 1;
		$this->posxpicture  = 116;
		$this->posxtva      = 116; // Price column starts here (see note above)
		$this->posxup       = 139; // Qty column starts here
		$this->posxqty      = 152; // GST column starts here
		$this->posxunit     = 163;
		$this->posxdiscount = 163;
		$this->postotalht   = 174; // Amount column starts here
	}


	// ── Page head ────────────────────────────────────────────────────────────────

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.PublicUnderscore
	protected function _pagehead(&$pdf, $object, $showaddress, $outputlangs, $outputlangsbis = null)
	{
		// phpcs:enable
		global $conf;

		$fs   = pdf_getPDFFontSize($outputlangs);
		$pw   = $this->page_largeur;
		$ml   = $this->marge_gauche;
		$mr   = $this->marge_droite;
		$mt   = $this->marge_haute;
		$usew = $pw - $ml - $mr;

		pdf_pagehead($pdf, $outputlangs, $this->page_hauteur);
		$pdf->SetTextColor(...static::CLR_DARK);


		// ── 1. Logo (top-left) ────────────────────────────────────────────────────
		$logoH    = 0;
		$logofile = $this->brand('LOGO');
		if (empty($logofile) && !empty($this->emetteur->logo)) {
			$logofile = $this->emetteur->logo;
		}
		if ($logofile) {
			$logodir = $conf->mycompany->dir_output;
			if (!empty($conf->mycompany->multidir_output[$object->entity ?? $conf->entity])) {
				$logodir = $conf->mycompany->multidir_output[$object->entity ?? $conf->entity];
			}
			$logopath = $logodir . '/logos/' . $logofile;
			if (is_readable($logopath)) {
				$maxH  = 20;
				$size  = getimagesize($logopath);
				$ratio = ($size && $size[1] > 0) ? $size[0] / $size[1] : 1;
				$drawW = round($maxH * $ratio, 1);
				$pdf->Image($logopath, $ml, $mt, $drawW, $maxH);
				$logoH = $maxH;
			}
		}

		// ── 2. "QUOTATION" heading (top-right) ────────────────────────────────────
		$rw = 95;
		$rx = $pw - $mr - $rw;

		$pdf->SetFont('', 'B', $fs + 8);
		$pdf->SetTextColor(...static::CLR_GREEN);
		$pdf->SetXY($rx, $mt);
		$pdf->Cell($rw, 10, 'QUOTATION', 0, 1, 'C');

		$pdf->SetFont('', 'B', $fs + 1);
		$pdf->SetTextColor(...static::CLR_DARK);
		$pdf->SetXY($rx, $mt + 11);
		$pdf->Cell($rw, 5, $this->brand('NAME'), 0, 1, 'C');

		$pdf->SetFont('', '', $fs - 1);
		$pdf->SetXY($rx, $mt + 17);
		$pdf->Cell($rw, 4, 'P:  ' . $this->brand('PHONE'), 0, 1, 'C');
		$pdf->SetXY($rx, $mt + 21);
		$pdf->Cell($rw, 4, 'E:  ' . $this->brand('EMAIL'), 0, 1, 'C');

		if ($object->statut == $object::STATUS_DRAFT) {
			$pdf->SetFont('', 'B', $fs - 1);
			$pdf->SetTextColor(180, 0, 0);
			$pdf->SetXY($rx, $mt + 25);
			$pdf->Cell($rw, 4, strtoupper($outputlangs->transnoentities('NotValidated')), 0, 1, 'C');
			$pdf->SetTextColor(...static::CLR_DARK);
		}

		// Sender address (below logo, top-left)
		$pdf->SetFont('', '', $fs - 1);
		$pdf->SetTextColor(...static::CLR_DARK);
		$pdf->SetXY($ml, $mt + max($logoH + 2, 15));
		$pdf->MultiCell(70, 4, $this->brand('ADDR1') . "\n" . $this->brand('ADDR2'), 0, 'L');

		// ── 3. ABN | DATE | QUOTE # table ─────────────────────────────────────────
		$tblY  = $mt + 26;
		$col1  = 30;
		$col2  = 30;
		$col3  = $rw - $col1 - $col2;
		$cellH = 6;

		$pdf->SetFont('', 'B', $fs - 1);
		$pdf->SetFillColor(...static::CLR_HDRFILL);
		$pdf->SetXY($rx, $tblY);
		$pdf->Cell($col1, $cellH, 'ABN',      1, 0, 'C', true);
		$pdf->Cell($col2, $cellH, 'DATE',     1, 0, 'C', true);
		$pdf->Cell($col3, $cellH, 'QUOTE #',  1, 1, 'C', true);

		$abn = !empty($this->emetteur->idprof1) ? $this->emetteur->idprof1 : getDolGlobalString('MAIN_INFO_SIREN');
		$pdf->SetFont('', '', $fs - 1);
		$pdf->SetXY($rx, $tblY + $cellH);
		$pdf->Cell($col1, $cellH, $abn,                                                                1, 0, 'C');
		$pdf->Cell($col2, $cellH, dol_print_date($object->date, 'day', false, $outputlangs),           1, 0, 'C');
		$pdf->Cell($col3, $cellH, $object->ref,                                                        1, 1, 'C');

		$afterHeaderY = $tblY + $cellH * 2 + 4;

		if (!$showaddress) {
			$pdf->SetTextColor(0, 0, 0);
			return 0;
		}

		// ── 4. TO / QUOTE DETAILS boxes ───────────────────────────────────────────
		$boxY  = $afterHeaderY;
		$boxH  = 28;
		$halfW = ($usew - 2) / 2; // 2mm gap between the two boxes

		$customer_name = pdfBuildThirdpartyName($object->thirdparty, $outputlangs);
		$customer_addr = pdf_build_address($outputlangs, $this->emetteur, $object->thirdparty, '', 0, 'target', $object);

		$pdf->SetFont('', 'B', $fs - 1);
		$pdf->SetFillColor(...static::CLR_HDRFILL);
		$pdf->SetXY($ml, $boxY);
		$pdf->Cell($halfW, 5, 'TO', 1, 1, 'L', true);

		$pdf->SetFont('', '', $fs - 1);
		$pdf->SetTextColor(...static::CLR_DARK);
		$pdf->SetXY($ml + 2, $boxY + 6);
		$pdf->MultiCell($halfW - 4, 4, $customer_name . "\n" . $customer_addr, 0, 'L');

		$pdf->RoundedRect($ml, $boxY, $halfW, $boxH, $this->corner_radius, '1234', 'D');

		// QUOTE DETAILS (right) — validity date + customer codes, previously shown
		// as small right-aligned text lines by stock Azur; boxed here to match the
		// TO box and keep them from crowding the ABN/DATE/QUOTE# table above.
		$detailX = $ml + $halfW + 2;

		$pdf->SetFont('', 'B', $fs - 1);
		$pdf->SetFillColor(...static::CLR_HDRFILL);
		$pdf->SetXY($detailX, $boxY);
		$pdf->Cell($halfW, 5, 'QUOTE DETAILS', 1, 1, 'L', true);

		$pdf->SetFont('', '', $fs - 1);
		$pdf->SetTextColor(...static::CLR_DARK);
		$pdf->SetXY($detailX + 2, $boxY + 6);

		$details = 'Valid until: ' . dol_print_date($object->fin_validite, 'day', false, $outputlangs);
		if (!getDolGlobalString('MAIN_PDF_HIDE_CUSTOMER_CODE') && !empty($object->thirdparty->code_client)) {
			$details .= "\nCustomer code: " . $object->thirdparty->code_client;
		}
		if (!getDolGlobalString('MAIN_PDF_HIDE_CUSTOMER_ACCOUNTING_CODE') && !empty($object->thirdparty->code_compta_client)) {
			$details .= "\nAccounting code: " . $object->thirdparty->code_compta_client;
		}
		$pdf->MultiCell($halfW - 4, 4, $details, 0, 'L');

		$pdf->RoundedRect($detailX, $boxY, $halfW, $boxH, $this->corner_radius, '1234', 'D');

		$endY      = $boxY + $boxH + 2;
		$top_shift = max(0, $endY - 78);

		$pdf->SetTextColor(0, 0, 0);
		return $top_shift;
	}


	// ── Column header table ───────────────────────────────────────────────────────

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.PublicUnderscore
	protected function _tableau(&$pdf, $tab_top, $tab_height, $nexY, $outputlangs, $hidetop = 0, $hidebottom = 0, $currency = '')
	{
		// phpcs:enable
		global $conf;

		// Translation overrides — same trick used across the BCS/SSS templates.
		$outputlangs->tab_translate['VAT']              = 'GST';
		$outputlangs->tab_translate['PriceUHT']         = 'Price (ex GST)';
		$outputlangs->tab_translate['TotalHTShort']     = 'Amount (ex GST)';
		$outputlangs->tab_translate['AmountInCurrency'] = ' '; // suppress "Amount in AU Dollars currency" header

		$hidebottom = 0;
		if ($hidetop) {
			$hidetop = -1;
		}

		$fs = pdf_getPDFFontSize($outputlangs);
		$ml = $this->marge_gauche;
		$mr = $this->marge_droite;
		$pw = $this->page_largeur;

		$pdf->SetDrawColor(128, 128, 128);
		$this->printRoundedRect($pdf, $ml, $tab_top, $pw - $ml - $mr, $tab_height, $this->corner_radius, $hidetop, $hidebottom, 'D');

		if (!empty($hidetop)) {
			return;
		}

		$hh = 6;
		$pdf->SetFillColor(...static::CLR_GREEN);
		$pdf->SetTextColor(255, 255, 255);
		$pdf->SetFont('', 'B', $fs - 2);

		// Column order here is Item | Price | Qty | GST | Amount — see the constructor
		// note and ActionsInvoicelines (custom/modules/invoicelines/) docblock.
		$cols = [
			['ITEM CODE / DESCRIPTION', $ml,               $this->posxtva    - $ml,               'L'],
			['PRICE (ex GST)',          $this->posxtva,    $this->posxup     - $this->posxtva,    'C'],
			['QTY',                     $this->posxup,     $this->posxqty    - $this->posxup,     'C'],
			['GST',                     $this->posxqty,    $this->postotalht - $this->posxqty,    'C'],
			['AMOUNT (ex GST)',         $this->postotalht, $pw - $mr         - $this->postotalht, 'C'],
		];

		foreach ($cols as [$label, $x, $w, $align]) {
			$pdf->SetXY($x, $tab_top);
			$pdf->Cell($w, $hh, $label, 0, 0, $align, true);
		}
		$pdf->Ln();

		$pdf->SetDrawColor(180, 180, 180);
		foreach ($cols as $idx => [$label, $x, $w, $align]) {
			if ($idx === 0) {
				continue;
			}
			$pdf->Line($x, $tab_top, $x, $tab_top + $tab_height);
		}

		$pdf->SetDrawColor(128, 128, 128);
		$pdf->Line($ml, $tab_top + $hh, $pw - $mr, $tab_top + $hh);

		$pdf->SetTextColor(0, 0, 0);
		$pdf->SetFillColor(255, 255, 255);
	}


	// ── Totals table ─────────────────────────────────────────────────────────────

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

		$total_ht = (isModEnabled('multicurrency') && $object->multicurrency_tx != 1 ? $object->multicurrency_total_ht : $object->total_ht);

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
			$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities('TotalHTBeforeDiscount') . (is_object($outputlangsbis) ? ' / ' . $outputlangsbis->transnoentities('TotalHTBeforeDiscount') : ''), 0, 'L', true);
			$pdf->SetXY($col2x, $tab2_top);
			$total_before_discount_to_show = ((isModEnabled('multicurrency') && $object->multicurrency_tx != 1) ? ($object->multicurrency_total_ht + $multicurrency_total_discount_on_lines) : ($object->total_ht + $total_discount_on_lines));
			$pdf->MultiCell($largcol2, $tab2_hl, $this->bcs_price((float) $total_before_discount_to_show), 0, 'R', true);
			$index++;

			$pdf->SetFillColor(255, 255, 255);
			$pdf->SetXY($col1x, $tab2_top + $tab2_hl);
			$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities('TotalDiscount') . (is_object($outputlangsbis) ? ' / ' . $outputlangsbis->transnoentities('TotalDiscount') : ''), 0, 'L', true);
			$pdf->SetXY($col2x, $tab2_top + $tab2_hl);
			$total_discount_to_show = ((isModEnabled('multicurrency') && $object->multicurrency_tx != 1) ? $multicurrency_total_discount_on_lines : $total_discount_on_lines);
			$pdf->MultiCell($largcol2, $tab2_hl, $this->bcs_price((float) $total_discount_to_show), 0, 'R', true);
			$index++;
		}

		// Total excl. GST
		$pdf->SetFillColor(255, 255, 255);
		$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
		$pdf->MultiCell($col2x - $col1x, $tab2_hl, 'Total (excl. GST)', 0, 'L', true);

		$total_ht = ((isModEnabled('multicurrency') && isset($object->multicurrency_tx) && $object->multicurrency_tx != 1) ? $object->multicurrency_total_ht : $object->total_ht);
		$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
		$pdf->MultiCell($largcol2, $tab2_hl, $this->bcs_price((float) ($total_ht + (!empty($object->remise) ? $object->remise : 0))), 0, 'R', true);

		// GST rows
		$pdf->SetFillColor(248, 248, 248);

		$total_ttc = (isModEnabled('multicurrency') && $object->multicurrency_tx != 1) ? $object->multicurrency_total_ttc : $object->total_ttc;

		$this->atleastoneratenotnull = 0;
		if (!getDolGlobalString('MAIN_GENERATE_DOCUMENTS_WITHOUT_VAT')) {
			$tvaisnull = (!empty($this->tva) && count($this->tva) == 1 && isset($this->tva['0.000']) && is_float($this->tva['0.000']));
			if (getDolGlobalString('MAIN_GENERATE_DOCUMENTS_WITHOUT_VAT_IFNULL') && $tvaisnull) {
				// nothing
			} else {
				// GST by rate
				foreach ($this->tva_array as $tvakey => $tvaval) {
					if ($tvakey != 0) {
						$this->atleastoneratenotnull++;
						$index++;
						$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
						$tvacompl = '';
						if (preg_match('/\*/', $tvakey)) {
							$tvakey   = str_replace('*', '', $tvakey);
							$tvacompl = ' (' . $outputlangs->transnoentities('NonPercuRecuperable') . ')';
						}
						$totalvat = 'Total GST ' . vatrate((string) $tvaval['vatrate'], true) . $tvacompl;
						$pdf->MultiCell($col2x - $col1x, $tab2_hl, $totalvat, 0, 'L', true);
						$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
						$pdf->MultiCell($largcol2, $tab2_hl, $this->bcs_price((float) price2num($tvaval['amount'], 'MT')), 0, 'R', true);
					}
				}

				// Total incl. GST
				$index++;
				$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
				$pdf->SetTextColor(0, 0, 60);
				$pdf->SetFillColor(224, 224, 224);
				$pdf->MultiCell($col2x - $col1x, $tab2_hl, 'Total (inc. GST)', $useborder, 'L', true);
				$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
				$pdf->MultiCell($largcol2, $tab2_hl, $this->bcs_price((float) $total_ttc), $useborder, 'R', true);
			}
		}

		$pdf->SetTextColor(0, 0, 0);
		$pdf->SetFont('', '', $default_font_size - 1);

		$index++;
		return ($tab2_top + ($tab2_hl * $index));
	}


	// ── Page footer ──────────────────────────────────────────────────────────────

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.PublicUnderscore
	protected function _pagefoot(&$pdf, $object, $outputlangs, $hidefreetext = 0)
	{
		// phpcs:enable
		$fs   = pdf_getPDFFontSize($outputlangs);
		$ml   = $this->marge_gauche;
		$mr   = $this->marge_droite;
		$pw   = $this->page_largeur;
		$ph   = $this->page_hauteur;
		$mb   = $this->marge_basse;
		$usew = $pw - $ml - $mr;

		$footH = 8;
		$footY = $ph - $mb - $footH;

		$pdf->SetDrawColor(...static::CLR_GREEN);
		$pdf->SetLineWidth(0.4);
		$pdf->Line($ml, $footY, $pw - $mr, $footY);
		$pdf->SetLineWidth(0.2);
		$pdf->SetDrawColor(0, 0, 0);

		$pdf->SetFont('', 'B', $fs);
		$pdf->SetTextColor(...static::CLR_GREEN);
		$pdf->SetXY($ml, $footY + 2);
		$pdf->Cell($usew, 4, $this->brand('TAGLINE'), 0, 1, 'C');

		$pdf->SetFont('', '', $fs - 2);
		$pdf->SetTextColor(120, 120, 120);
		$pdf->SetXY($ml, $footY + 2);
		$pdf->Cell($usew, 4, $pdf->getAliasNumPage() . '/' . $pdf->getAliasNbPages(), 0, 0, 'R');

		$pdf->SetTextColor(0, 0, 0);

		return $mb + $footH;
	}
}
