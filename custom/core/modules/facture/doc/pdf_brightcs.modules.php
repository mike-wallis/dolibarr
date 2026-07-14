<?php
/**
 * Bright Cleaning Solutions — custom invoice PDF template.
 * Extends pdf_crabe. Brand values are read from llx_const (Home → Setup → Other setup)
 * so they can be updated through the UI without touching this file.
 *
 * File: htdocs/core/modules/facture/doc/pdf_brightcs.modules.php
 * DB:   INSERT INTO llx_document_model (nom, type, entity) VALUES ('brightcs', 'invoice', 1)
 */

require_once DOL_DOCUMENT_ROOT . '/core/modules/facture/doc/pdf_crabe.modules.php';

class pdf_brightcs extends pdf_crabe
{
	// ── Colours — override const in subclass for a different brand colour ─────
	// Use static:: (not self::) throughout so child-class overrides take effect.

	const CLR_GREEN   = [44, 138, 62];   // BCS brand green
	const CLR_DARK    = [30, 30, 30];
	const CLR_HDRFILL = [230, 230, 230];

	// Cache for DISC- lines extracted before parent::write_file() so _tableau_tot()
	// can access them in subclasses (pdf_southside) without them appearing in the line table.
	protected $disc_lines_cache = [];

	// ── Brand config prefix ───────────────────────────────────────────────────
	// Constants are read from llx_const as <prefix>_NAME, <prefix>_PHONE, etc.
	// Override in a subclass to point at a different set of constants.

	protected $brand_prefix = 'BCS';

	// Fallback values used when the constant is not yet set in the DB.
	protected function brand_defaults(): array
	{
		return [
			'NAME'      => 'Bright Cleaning Solutions Pty Ltd',
			'ADDR1'     => '70 Brisbane Corso',
			'ADDR2'     => 'Fairfield QLD 4103',
			'PHONE'     => '0401 130 096',
			'EMAIL'     => 'accounts@brightcs.com.au',
			'BSB'       => '182-512',
			'ACC'       => '000974446429',
			'TAGLINE'   => 'Great Products, Great Service and Really Looking after our Customers.',
			'OWNERSHIP' => 'Ownership of the goods does not pass until payment is received in full.',
			'LOGO'      => '',  // empty = use company logo from Setup → Company
		];
	}

	// Read a brand value: DB constant wins, then brand_defaults(), then ''.
	protected function brand(string $key): string
	{
		$val = getDolGlobalString($this->brand_prefix . '_' . $key);
		if ($val !== '') {
			return $val;
		}
		$d = $this->brand_defaults();
		return $d[$key] ?? '';
	}


	public function __construct($db)
	{
		parent::__construct($db);

		$this->name        = 'brightcs';
		$this->description = 'Bright Cleaning Solutions invoice';

		// Column positions (mm from left edge of page).
		// write_file draws in this fixed order: desc → tva → up → qty → unit → total.
		// Positions MUST increase left-to-right in that sequence.
		//
		// ActionsInvoicelines (custom/modules/invoicelines/) hooks the content drawn at
		// the tva/up/qty slots so the PRINTED values read Price | Qty | GST — see that
		// class's docblock. The widths below are sized for THAT printed content (e.g.
		// "PRICE (ex GST)" needs the wider slot, not the narrow one GST% used to have),
		// not for what each variable name suggests.
		$this->posxdesc     = $this->marge_gauche + 1;
		$this->posxpicture  = 116;
		$this->posxtva      = 116; // Price column starts here (see note above)
		$this->posxup       = 139; // Qty column starts here
		$this->posxqty      = 152; // GST column starts here
		$this->posxunit     = 163;
		$this->posxdiscount = 163;
		$this->postotalht   = 174; // Amount column starts here
	}


	// ── Translation injection ─────────────────────────────────────────────────
	// translate.class.php keeps the FIRST value set for each key.
	// Injecting before parent::write_file() calls loadLangs() makes our values win.

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	public function write_file($object, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
	{
		// phpcs:enable
		$t = &$outputlangs->tab_translate;

		$t['VAT']                              = 'GST';
		$t['VATShort']                         = 'GST %';
		$t['TotalHT']                          = 'Total (excl. GST)';
		$t['TotalHTShort']                     = 'Total (excl. GST)';
		$t['TotalTTC']                         = 'Total (inc. GST)';
		$t['TotalTTCShort']                    = 'Total (inc. GST)';
		$t['TotalVAT']                         = 'Total GST';
		$t['TotalVATShort']                    = 'Total GST';
		$t['AmountHT']                         = 'Amount (excl. GST)';
		$t['AmountTTC']                        = 'Amount (inc. GST)';
		$t['PriceUHT']                         = 'Price (excl. GST)';
		$t['PaymentCondition30DENDMONTH']      = '30 days EOM';
		$t['PaymentConditionShort30DENDMONTH'] = '30 days EOM';

		// Extract DISC- lines from the body table into cache.
		// They are hidden from the line items table and shown in the totals block instead (pdf_southside).
		// Must happen before parent::write_file() so pdf_crabe doesn't render them as body lines.
		$this->disc_lines_cache = [];
		foreach ($object->lines as $i => $line) {
			if (strpos((string) ($line->label ?? ''), 'DISC-') === 0) {
				$this->disc_lines_cache[$line->label] = $line;
				unset($object->lines[$i]);
			}
		}
		$object->lines = array_values($object->lines);

		// Append discount % to description text when a line has a line-level discount
		$saved_descs = [];
		foreach ($object->lines as $i => $line) {
			if (!empty($line->remise_percent) && (float) $line->remise_percent > 0) {
				$saved_descs[$i]         = $line->desc;
				$pct                     = rtrim(rtrim(number_format((float) $line->remise_percent, 2, '.', ''), '0'), '.');
				$object->lines[$i]->desc = $line->desc . "\nDiscount: " . $pct . '%';
			}
		}

		$result = parent::write_file($object, $outputlangs, $srctemplatepath, $hidedetails, $hidedesc, $hideref);

		// Restore line descriptions and DISC- lines
		foreach ($saved_descs as $i => $desc) {
			$object->lines[$i]->desc = $desc;
		}
		foreach ($this->disc_lines_cache as $line) {
			$object->lines[] = $line;
		}

		return $result;
	}


	// ── Currency helper ───────────────────────────────────────────────────────

	protected function bcs_price($amount, $sign = 1)
	{
		$val = (float) ($sign * $amount);
		return ($val < 0 ? '-$' : '$') . number_format(abs($val), 2, '.', ',');
	}


	// ── Totals table ─────────────────────────────────────────────────────────

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.PublicUnderscore
	protected function _tableau_tot(&$pdf, $object, $deja_regle, $posy, $outputlangs, $outputlangsbis)
	{
		// phpcs:enable
		global $conf, $mysoc;

		$sign = 1;
		if ($object->type == 2 && getDolGlobalString('INVOICE_POSITIVE_CREDIT_NOTE')) {
			$sign = -1;
		}

		$default_font_size = pdf_getPDFFontSize($outputlangs);
		$tab2_top = $posy;
		$tab2_hl  = 4;
		$pdf->SetFont('', '', $default_font_size - 1);

		$col1x    = 120;
		$col2x    = 170;
		$largcol2 = ($this->page_largeur - $this->marge_droite - $col2x);
		$useborder = 0;
		$index     = 0;

		$total_ht  = ((isModEnabled("multicurrency") && $object->multicurrency_tx != 1) ? $object->multicurrency_total_ht  : $object->total_ht);
		$total_ttc = ((isModEnabled("multicurrency") && $object->multicurrency_tx != 1) ? $object->multicurrency_total_ttc : $object->total_ttc);

		$pdf->SetFillColor(255, 255, 255);
		$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
		$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("TotalHT"), 0, 'L', true);
		$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
		$pdf->MultiCell($largcol2, $tab2_hl, $this->bcs_price($total_ht, $sign), 0, 'R', true);

		$pdf->SetFillColor(248, 248, 248);

		if (!getDolGlobalString('MAIN_GENERATE_DOCUMENTS_WITHOUT_VAT')) {
			foreach ($this->tva_array as $tvakey => $tvaval) {
				if ($tvakey != 0 || getDolGlobalString('INVOICE_SHOW_ALSO_VAT_LINE_IF_ZERO')) {
					$index++;
					$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);

					$tvacompl = '';
					if (preg_match('/\*/', $tvakey)) {
						$tvakey   = str_replace('*', '', $tvakey);
						$tvacompl = ' (' . $outputlangs->transnoentities("NonPercuRecuperable") . ')';
					}

					$totalvat = $outputlangs->transcountrynoentities("TotalVAT", $mysoc->country_code) . ' ';
					$totalvat .= vatrate((string) $tvaval['vatrate'], true)
						. ($tvaval['vatcode'] ? ' (' . $tvaval['vatcode'] . ')' : '') . $tvacompl;

					$pdf->MultiCell($col2x - $col1x, $tab2_hl, $totalvat, 0, 'L', true);
					$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
					$pdf->MultiCell($largcol2, $tab2_hl, $this->bcs_price(price2num($tvaval['amount'], 'MT')), 0, 'R', true);
				}
			}

			$index++;
			$pdf->SetTextColor(0, 0, 60);
			$pdf->SetFillColor(224, 224, 224);
			$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
			$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("TotalTTC"), $useborder, 'L', true);
			$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
			$pdf->MultiCell($largcol2, $tab2_hl, $this->bcs_price($total_ttc, $sign), $useborder, 'R', true);
		}

		$pdf->SetTextColor(0, 0, 0);

		$creditnoteamount = $object->getSumCreditNotesUsed((isModEnabled("multicurrency") && $object->multicurrency_tx != 1) ? 1 : 0);
		$depositsamount   = $object->getSumDepositsUsed((isModEnabled("multicurrency") && $object->multicurrency_tx != 1) ? 1 : 0);
		$resteapayer      = price2num($total_ttc - $deja_regle - $creditnoteamount - $depositsamount, 'MT');
		if (!empty($object->paye)) {
			$resteapayer = 0;
		}

		if (($deja_regle > 0 || $creditnoteamount > 0 || $depositsamount > 0) && !getDolGlobalString('INVOICE_NO_PAYMENT_DETAILS')) {
			$index++;
			$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
			$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("Paid"), 0, 'L', false);
			$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
			$pdf->MultiCell($largcol2, $tab2_hl, $this->bcs_price($deja_regle + $depositsamount), 0, 'R', false);

			if ($creditnoteamount) {
				$labeltouse = ($outputlangs->transnoentities("CreditNotesOrExcessReceived") != "CreditNotesOrExcessReceived")
					? $outputlangs->transnoentities("CreditNotesOrExcessReceived")
					: $outputlangs->transnoentities("CreditNotes");
				$index++;
				$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
				$pdf->MultiCell($col2x - $col1x, $tab2_hl, $labeltouse, 0, 'L', false);
				$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
				$pdf->MultiCell($largcol2, $tab2_hl, $this->bcs_price($creditnoteamount), 0, 'R', false);
			}

			$index++;
			$pdf->SetTextColor(0, 0, 60);
			$pdf->SetFillColor(224, 224, 224);
			$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
			$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("RemainderToPay"), $useborder, 'L', true);
			$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
			$pdf->MultiCell($largcol2, $tab2_hl, $this->bcs_price($resteapayer), $useborder, 'R', true);

			$pdf->SetFont('', '', $default_font_size - 1);
			$pdf->SetTextColor(0, 0, 0);
		}

		$index++;

		return ($tab2_top + ($tab2_hl * $index));
	}


	// ── Column header table ───────────────────────────────────────────────────

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.PublicUnderscore
	protected function _tableau(&$pdf, $tab_top, $tab_height, $nexY, $outputlangs, $hidetop = 0, $hidebottom = 0, $currency = '')
	{
		// phpcs:enable
		global $conf;

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

		// Column order here is Item | Price | Qty | GST | Amount. The content drawn in
		// each x-position below is NOT what Dolibarr's core write_file() normally puts
		// there — ActionsInvoicelines (custom/modules/invoicelines/) hooks the three
		// pdf_getline*() calls in core and swaps their printed values around so the
		// physical column order matches this header without touching core files.
		// See that class's docblock for the full explanation. Read the two together.
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


	// ── Page head ─────────────────────────────────────────────────────────────

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


		// ── 1. Logo (top-left) ─────────────────────────────────────────────────
		// Cap logo height at 20mm so square/tall logos don't crowd the header.
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
				$maxH  = 20; // mm — prevents tall/square logos overlapping address
				$size  = getimagesize($logopath);
				$ratio = ($size && $size[1] > 0) ? $size[0] / $size[1] : 1;
				$drawW = round($maxH * $ratio, 1);
				$drawH = $maxH;
				$pdf->Image($logopath, $ml, $mt, $drawW, $drawH);
				$logoH = $drawH;
			}
		}

		// ── 2. "TAX INVOICE" heading (top-right) ──────────────────────────────
		$rw = 95;
		$rx = $pw - $mr - $rw;

		$pdf->SetFont('', 'B', $fs + 8);
		$pdf->SetTextColor(...static::CLR_GREEN);
		$pdf->SetXY($rx, $mt);
		$pdf->Cell($rw, 10, 'TAX INVOICE', 0, 1, 'C');

		$pdf->SetFont('', 'B', $fs + 1);
		$pdf->SetTextColor(...static::CLR_DARK);
		$pdf->SetXY($rx, $mt + 11);
		$pdf->Cell($rw, 5, $this->brand('NAME'), 0, 1, 'C');

		$pdf->SetFont('', '', $fs - 1);
		$pdf->SetXY($rx, $mt + 17);
		$pdf->Cell($rw, 4, 'P:  ' . $this->brand('PHONE'), 0, 1, 'C');
		$pdf->SetXY($rx, $mt + 21);
		$pdf->Cell($rw, 4, 'E:  ' . $this->brand('EMAIL'), 0, 1, 'C');

		// Sender address (bottom-left)
		$pdf->SetFont('', '', $fs - 1);
		$pdf->SetTextColor(...static::CLR_DARK);
		$pdf->SetXY($ml, $mt + max($logoH + 2, 15));
		$pdf->MultiCell(70, 4, $this->brand('ADDR1') . "\n" . $this->brand('ADDR2'), 0, 'L');

		// ── 3. ABN | DATE | INVOICE # table ──────────────────────────────────
		$tblY  = $mt + 26;
		$col1  = 30; $col2 = 30; $col3 = $rw - $col1 - $col2;
		$cellH = 6;

		$pdf->SetFont('', 'B', $fs - 1);
		$pdf->SetFillColor(...static::CLR_HDRFILL);
		$pdf->SetXY($rx, $tblY);
		$pdf->Cell($col1, $cellH, 'ABN',      1, 0, 'C', true);
		$pdf->Cell($col2, $cellH, 'DATE',      1, 0, 'C', true);
		$pdf->Cell($col3, $cellH, 'INVOICE #', 1, 1, 'C', true);

		$abn = !empty($this->emetteur->idprof1) ? $this->emetteur->idprof1 : getDolGlobalString('MAIN_INFO_SIREN');
		$pdf->SetFont('', '', $fs - 1);
		$pdf->SetXY($rx, $tblY + $cellH);
		$pdf->Cell($col1, $cellH, $abn,                                                      1, 0, 'C');
		$pdf->Cell($col2, $cellH, dol_print_date($object->date, 'day', false, $outputlangs), 1, 0, 'C');
		$pdf->Cell($col3, $cellH, $object->ref,                                              1, 1, 'C');

		$afterHeaderY = $tblY + $cellH * 2 + 4;

		if (!$showaddress) {
			$pdf->SetTextColor(0, 0, 0);
			return 0;
		}

		// ── 4. BILL TO / SHIP TO boxes ────────────────────────────────────────
		$boxY = $afterHeaderY;
		$boxW = ($usew / 2) - 2;
		$boxH = 28;

		$pdf->SetFont('', 'B', $fs - 1);
		$pdf->SetFillColor(...static::CLR_HDRFILL);
		$pdf->SetXY($ml, $boxY);
		$pdf->Cell($boxW, 5, 'BILL TO', 1, 1, 'L', true);
		$pdf->SetFont('', '', $fs - 1);
		$pdf->SetXY($ml, $boxY + 5);
		$carac_client  = pdfBuildThirdpartyName($object->thirdparty, $outputlangs) . "\n";
		$carac_client .= pdf_build_address($outputlangs, $this->emetteur, $object->thirdparty, '', 0, 'target', $object);
		$pdf->MultiCell($boxW, 4, $carac_client, 1, 'L');

		// SHIP TO — uses a contact linked to THIS invoice with role "Shipping" (set on
		// the invoice's own Contacts/Addresses tab, drawn from the customer's own
		// Contacts/Addresses list) if one is set, so different invoices for the same
		// customer can ship to different addresses. Falls back to the same address as
		// Bill To otherwise. Mirrors core Dolibarr's own logic (pdf_crabe.modules.php)
		// so this behaves exactly like stock Dolibarr once a shipping contact is linked.
		$idaddressshipping = $object->getIdContact('external', 'SHIPPING');
		if (!empty($idaddressshipping)) {
			$object->fetch_Contact($idaddressshipping[0]);
			$shipto_company = new Societe($this->db);
			$shipto_company->fetch($object->contact->fk_soc);
			$carac_client_shipping  = pdfBuildThirdpartyName($object->contact, $outputlangs) . "\n";
			$carac_client_shipping .= pdf_build_address($outputlangs, $this->emetteur, $shipto_company, $object->contact, 1, 'target', $object);
		} else {
			$carac_client_shipping = $carac_client;
		}

		$pdf->SetFont('', 'B', $fs - 1);
		$pdf->SetXY($ml + $boxW + 4, $boxY);
		$pdf->SetFillColor(...static::CLR_HDRFILL);
		$pdf->Cell($boxW, 5, 'SHIP TO', 1, 1, 'L', true);
		$pdf->SetFont('', '', $fs - 1);
		$pdf->SetXY($ml + $boxW + 4, $boxY + 5);
		$pdf->MultiCell($boxW, 4, $carac_client_shipping, 1, 'L');

		// ── 5. P.O.# | TERMS | DUE DATE | REP | SHIP | VIA ──────────────────
		$metaY = $boxY + $boxH + 2;
		$mcols = ['P.O. #', 'TERMS', 'DUE DATE', 'REP', 'SHIP', 'VIA'];
		$colW  = $usew / count($mcols);

		$pdf->SetFont('', 'B', $fs - 2);
		$pdf->SetFillColor(...static::CLR_HDRFILL);
		$pdf->SetXY($ml, $metaY);
		foreach ($mcols as $c) {
			$pdf->Cell($colW, 5, $c, 1, 0, 'C', true);
		}
		$pdf->Ln();

		$pdf->SetFont('', '', $fs - 2);
		$cond = '';
		if (!empty($object->cond_reglement_doc)) {
			$cond = $object->cond_reglement_doc;
		} elseif (!empty($object->cond_reglement_code)) {
			$cond = $outputlangs->transnoentities('PaymentConditionShort' . $object->cond_reglement_code);
		}
		$due  = dol_print_date($object->date_lim_reglement, 'day', false, $outputlangs);
		$ship = dol_print_date($object->date, 'day', false, $outputlangs);

		$vals = [$object->ref_client, $cond, $due, '', $ship, ''];
		$pdf->SetXY($ml, $metaY + 5);
		foreach ($vals as $v) {
			$pdf->Cell($colW, 5, $v, 1, 0, 'C');
		}
		$pdf->Ln();

		$endY      = $metaY + 10;
		$top_shift = max(0, $endY - 78);

		$pdf->SetTextColor(0, 0, 0);
		return $top_shift;
	}


	// ── Page foot ─────────────────────────────────────────────────────────────

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

		$footY = $ph - $mb - 28;

		$pdf->SetDrawColor(...static::CLR_GREEN);
		$pdf->SetLineWidth(0.4);
		$pdf->Line($ml, $footY, $pw - $mr, $footY);
		$pdf->SetLineWidth(0.2);
		$pdf->SetDrawColor(0, 0, 0);

		$bw = $usew * 0.55;
		$pdf->SetFont('', 'B', $fs);
		$pdf->SetTextColor(...static::CLR_GREEN);
		$pdf->SetXY($ml, $footY + 2);
		$pdf->Cell($bw, 5, 'BANK ACCOUNT DETAILS', 0, 1, 'L');

		$pdf->SetFont('', '', $fs - 1);
		$pdf->SetTextColor(...static::CLR_DARK);
		$pdf->SetXY($ml, $footY + 7);
		$pdf->MultiCell($bw, 4,
			"Please make your payments to:\nBSB: " . $this->brand('BSB') . "   Acc. No: " . $this->brand('ACC'),
			0, 'L'
		);

		$pdf->SetFont('', 'I', $fs - 2);
		$pdf->SetTextColor(80, 80, 80);
		$pdf->SetXY($ml, $footY + 18);
		$pdf->Cell($usew, 4, $this->brand('OWNERSHIP'), 0, 1, 'C');

		$pdf->SetFont('', 'B', $fs);
		$pdf->SetTextColor(...static::CLR_GREEN);
		$pdf->SetXY($ml, $footY + 22);
		$pdf->Cell($usew, 4, $this->brand('TAGLINE'), 0, 1, 'C');

		$pdf->SetFont('', '', $fs - 2);
		$pdf->SetTextColor(120, 120, 120);
		$pdf->SetXY($ml, $footY + 22);
		$pdf->Cell($usew, 4, $pdf->getAliasNumPage() . '/' . $pdf->getAliasNbPages(), 0, 0, 'R');

		$pdf->SetTextColor(0, 0, 0);

		return $mb + 28;
	}
}
