<?php
/**
 * Bright Cleaning Solutions — Purchase Order PDF template.
 * Extends pdf_muscadet for the line-item rendering engine.
 * Brand values are read from BCS_* constants in llx_const
 * (Home → Setup → Other setup) — no code changes needed to update them.
 *
 * File: htdocs/core/modules/supplier_order/doc/pdf_brightcs_po.modules.php
 *
 * One-time DB registration (run once in phpMyAdmin or via a migration script):
 *   INSERT INTO llx_document_model (nom, type, entity)
 *   VALUES ('brightcs_po', 'order_supplier', 1)
 *   ON DUPLICATE KEY UPDATE nom = nom;
 */

require_once DOL_DOCUMENT_ROOT . '/core/modules/supplier_order/doc/pdf_muscadet.modules.php';

class pdf_brightcs_po extends pdf_muscadet
{
	const CLR_GREEN   = [44, 138, 62];
	const CLR_DARK    = [30, 30, 30];
	const CLR_HDRFILL = [230, 230, 230];

	// ── Brand helpers (mirrors pdf_brightcs — can't share via inheritance) ──────

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

		$this->name        = 'brightcs_po';
		$this->description = 'Bright Cleaning Solutions purchase order';
	}


	// ── Translation injection ────────────────────────────────────────────────────
	// Translate::load() keeps the FIRST value set for each key, so injecting
	// before parent::write_file() means our values win over the en_EN strings.

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

		return parent::write_file($object, $outputlangs, $srctemplatepath, $hidedetails, $hidedesc, $hideref);
	}


	// ── Page head ────────────────────────────────────────────────────────────────

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.PublicUnderscore
	protected function _pagehead(&$pdf, $object, $showaddress, $outputlangs)
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

		// ── 2. "PURCHASE ORDER" heading (top-right) ───────────────────────────────
		$rw = 95;
		$rx = $pw - $mr - $rw;

		$pdf->SetFont('', 'B', $fs + 8);
		$pdf->SetTextColor(...static::CLR_GREEN);
		$pdf->SetXY($rx, $mt);
		$pdf->Cell($rw, 10, 'PURCHASE ORDER', 0, 1, 'C');

		$pdf->SetFont('', 'B', $fs + 1);
		$pdf->SetTextColor(...static::CLR_DARK);
		$pdf->SetXY($rx, $mt + 11);
		$pdf->Cell($rw, 5, $this->brand('NAME'), 0, 1, 'C');

		$pdf->SetFont('', '', $fs - 1);
		$pdf->SetXY($rx, $mt + 17);
		$pdf->Cell($rw, 4, 'P:  ' . $this->brand('PHONE'), 0, 1, 'C');
		$pdf->SetXY($rx, $mt + 21);
		$pdf->Cell($rw, 4, 'E:  ' . $this->brand('EMAIL'), 0, 1, 'C');

		// Sender address (below logo, top-left)
		$pdf->SetFont('', '', $fs - 1);
		$pdf->SetTextColor(...static::CLR_DARK);
		$pdf->SetXY($ml, $mt + max($logoH + 2, 15));
		$pdf->MultiCell(70, 4, $this->brand('ADDR1') . "\n" . $this->brand('ADDR2'), 0, 'L');

		// ── 3. ABN | DATE | P.O. # table ─────────────────────────────────────────
		$tblY  = $mt + 26;
		$col1  = 30;
		$col2  = 30;
		$col3  = $rw - $col1 - $col2;
		$cellH = 6;

		$pdf->SetFont('', 'B', $fs - 1);
		$pdf->SetFillColor(...static::CLR_HDRFILL);
		$pdf->SetXY($rx, $tblY);
		$pdf->Cell($col1, $cellH, 'ABN',    1, 0, 'C', true);
		$pdf->Cell($col2, $cellH, 'DATE',   1, 0, 'C', true);
		$pdf->Cell($col3, $cellH, 'P.O. #', 1, 1, 'C', true);

		$abn = !empty($this->emetteur->idprof1) ? $this->emetteur->idprof1 : getDolGlobalString('MAIN_INFO_SIREN');
		$pdf->SetFont('', '', $fs - 1);
		$pdf->SetXY($rx, $tblY + $cellH);
		$pdf->Cell($col1, $cellH, $abn,                                                                1, 0, 'C');
		$pdf->Cell($col2, $cellH, dol_print_date($object->date_commande, 'day', false, $outputlangs), 1, 0, 'C');
		$pdf->Cell($col3, $cellH, $object->ref,                                                        1, 1, 'C');

		$afterHeaderY = $tblY + $cellH * 2 + 4;

		if (!$showaddress) {
			$pdf->SetTextColor(0, 0, 0);
			return 0;
		}

		// ── 4. VENDOR box ─────────────────────────────────────────────────────────
		$boxY  = $afterHeaderY;
		$boxH  = 24;
		$halfW = ($usew - 2) / 2; // 2mm gap between VENDOR and DELIVER TO boxes

		$vendor_name = pdfBuildThirdpartyName($object->thirdparty, $outputlangs);
		$vendor_addr = pdf_build_address($outputlangs, $this->emetteur, $object->thirdparty, '', 0, 'target', $object);

		$pdf->SetFont('', 'B', $fs - 1);
		$pdf->SetFillColor(...static::CLR_HDRFILL);
		$pdf->SetXY($ml, $boxY);
		$pdf->Cell($halfW, 5, 'VENDOR', 1, 1, 'L', true);

		$pdf->SetFont('', '', $fs - 1);
		$pdf->SetTextColor(...static::CLR_DARK);
		$pdf->SetXY($ml + 2, $boxY + 6);
		$pdf->MultiCell($halfW - 4, 4, $vendor_name . "\n" . $vendor_addr, 0, 'L');

		$pdf->RoundedRect($ml, $boxY, $halfW, $boxH, $this->corner_radius, '1234', 'D');

		// DELIVER TO (right) — reads from the 'delivery_address' extra field on the PO.
		// The field stores a short key; we look up the display label from the param options.
		// Falls back to BCS brand address when the field is not set on the order.
		$deliverX = $ml + $halfW + 2;
		$object->fetch_optionals();
		$deliverKey = trim((string) ($object->array_options['options_delivery_address'] ?? ''));
		if ($deliverKey !== '') {
			require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
			$ef = new ExtraFields($this->db);
			$ef->fetch_name_optionals_label('commande_fournisseur');
			$opts      = $ef->attributes['commande_fournisseur']['param']['delivery_address']['options'] ?? [];
			$deliverTo = $opts[$deliverKey] ?? $deliverKey;
		} else {
			$deliverTo = $this->brand('NAME') . "\n" . $this->brand('ADDR1') . "\n" . $this->brand('ADDR2');
		}

		$pdf->SetFont('', 'B', $fs - 1);
		$pdf->SetFillColor(...static::CLR_HDRFILL);
		$pdf->SetXY($deliverX, $boxY);
		$pdf->Cell($halfW, 5, 'DELIVER TO', 1, 1, 'L', true);
		$pdf->SetFont('', '', $fs - 1);
		$pdf->SetTextColor(...static::CLR_DARK);
		$pdf->SetXY($deliverX + 2, $boxY + 6);
		$pdf->MultiCell($halfW - 4, 4, $deliverTo, 0, 'L');
		$pdf->RoundedRect($deliverX, $boxY, $halfW, $boxH, $this->corner_radius, '1234', 'D');

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

		$cols = [
			['ITEM CODE / DESCRIPTION', $ml,               $this->posxtva    - $ml,               'L'],
			['GST %',                   $this->posxtva,    $this->posxup     - $this->posxtva,    'C'],
			['PRICE (ex GST)',          $this->posxup,     $this->posxqty    - $this->posxup,     'C'],
			['QTY',                     $this->posxqty,    $this->posxunit   - $this->posxqty,    'C'],
			['UNIT',                    $this->posxunit,   $this->postotalht - $this->posxunit,   'C'],
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
	protected function _tableau_tot(&$pdf, $object, $deja_regle, $posy, $outputlangs)
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
		$largcol2  = ($this->page_largeur - $this->marge_droite - $col2x);
		$useborder = 0;
		$index     = 0;

		$total_ht  = ((isModEnabled('multicurrency') && $object->multicurrency_tx != 1) ? $object->multicurrency_total_ht  : $object->total_ht);
		$total_ttc = ((isModEnabled('multicurrency') && $object->multicurrency_tx != 1) ? $object->multicurrency_total_ttc : $object->total_ttc);

		// Subtotal (excl. GST)
		$pdf->SetFillColor(255, 255, 255);
		$pdf->SetXY($col1x, $tab2_top);
		$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities('TotalHT'), 0, 'L', true);
		$pdf->SetXY($col2x, $tab2_top);
		$pdf->MultiCell($largcol2, $tab2_hl, $this->bcs_price((float) $total_ht), 0, 'R', true);

		$pdf->SetFillColor(248, 248, 248);

		// GST breakdown
		if (!getDolGlobalString('MAIN_GENERATE_DOCUMENTS_WITHOUT_VAT')) {
			$this->atleastoneratenotnull = 0;
			foreach ($this->tva as $tvakey => $tvaval) {
				if ($tvakey > 0) {
					$this->atleastoneratenotnull++;
					$index++;

					$tvacompl = '';
					if (preg_match('/\*/', (string) $tvakey)) {
						$tvakey   = str_replace('*', '', (string) $tvakey);
						$tvacompl = ' (' . $outputlangs->transnoentities('NonPercuRecuperable') . ')';
					}
					$totalvat = $outputlangs->transcountrynoentities('TotalVAT', $mysoc->country_code) . ' ';
					$totalvat .= vatrate((string) $tvakey, true) . $tvacompl;

					$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
					$pdf->MultiCell($col2x - $col1x, $tab2_hl, $totalvat, 0, 'L', true);
					$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
					$pdf->MultiCell($largcol2, $tab2_hl, $this->bcs_price((float) $tvaval), 0, 'R', true);
				}
			}

			if (!$this->atleastoneratenotnull) {
				$index++;
				$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
				$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transcountrynoentities('TotalVAT', $mysoc->country_code), 0, 'L', true);
				$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
				$pdf->MultiCell($largcol2, $tab2_hl, $this->bcs_price((float) $object->total_tva), 0, 'R', true);
			}
		}

		// Total (inc. GST)
		$index++;
		$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
		$pdf->SetTextColor(0, 0, 60);
		$pdf->SetFillColor(224, 224, 224);
		$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities('TotalTTC'), $useborder, 'L', true);
		$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
		$pdf->MultiCell($largcol2, $tab2_hl, $this->bcs_price((float) $total_ttc), $useborder, 'R', true);

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

		$pdf->SetFont('', '', $fs - 2);
		$pdf->SetTextColor(128, 128, 128);
		$pdf->SetXY($ml, $footY + 2);
		$pdf->Cell($usew, 4, $pdf->getAliasNumPage() . '/' . $pdf->getAliasNbPages(), 0, 1, 'R');

		$pdf->SetTextColor(0, 0, 0);

		return $mb + $footH;
	}
}
