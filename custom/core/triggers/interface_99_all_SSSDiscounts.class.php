<?php
/**
 * SSS automatic discount trigger.
 * Recalculates delivery zone / large order / member discount lines whenever
 * an invoice or quote (commercial proposal) line is added, modified or
 * deleted for an SSS customer.
 *
 * Customer flags (set via Third Party card → Extra fields):
 *   is_sss_customer  checkbox  — must be checked for discounts to apply
 *   is_member        checkbox  — enables the 10% premium member discount
 *
 * Global constant (Home → Setup → Other setup):
 *   SSS_LARGE_ORDER_MIN  — subtotal threshold for the 5% large order discount
 *
 * Quotes only get their discount lines recalculated while still Draft —
 * Propal::deleteLine()/addline() both require draft status, matching the
 * normal Dolibarr rule that a validated quote's lines are locked.
 *
 * File: htdocs/core/triggers/interface_99_all_SSSDiscounts.class.php
 * Dolibarr auto-loads any interface_*.class.php file under core/triggers/ —
 * no module registration or const needed, unlike hooks. See scripts/deploy.ps1.
 */

require_once DOL_DOCUMENT_ROOT . '/core/triggers/dolibarrtriggers.class.php';

class InterfaceSSSDiscounts extends DolibarrTriggers
{
	public function __construct($db)
	{
		parent::__construct($db);
		$this->name        = preg_replace('/^Interface/i', '', get_class($this));
		$this->family      = 'other';
		$this->description = 'Automatic SSS discount line calculation on invoices and quotes';
		$this->version     = '1.1';
		$this->picto       = 'generic';
	}

	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (in_array($action, ['LINEBILL_INSERT', 'LINEBILL_MODIFY', 'LINEBILL_DELETE'])) {
			return $this->handleLine('facture', (int) ($object->fk_facture ?? 0), $object);
		}
		if (in_array($action, ['LINEPROPAL_INSERT', 'LINEPROPAL_MODIFY', 'LINEPROPAL_DELETE'])) {
			return $this->handleLine('propal', (int) ($object->fk_propal ?? 0), $object);
		}
		return 0;
	}

	private function handleLine(string $doctype, int $parentId, $lineObject)
	{
		// Static guard: prevents re-entry while we're adding/removing discount lines.
		// Dolibarr handles nested transactions via a counter so the DB is safe,
		// but without this guard every DISC- addline/deleteline would re-trigger us.
		static $processing = false;
		if ($processing) {
			return 0;
		}

		// Skip if the line being modified is itself a DISC- line.
		if (strpos((string) ($lineObject->label ?? ''), 'DISC-') === 0) {
			return 0;
		}

		if (!$parentId) {
			return 0;
		}

		require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';

		if ($doctype === 'facture') {
			require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
			$doc = new Facture($this->db);
		} else {
			require_once DOL_DOCUMENT_ROOT . '/comm/propal/class/propal.class.php';
			$doc = new Propal($this->db);
		}
		if ($doc->fetch($parentId) <= 0) {
			return 0;
		}

		// Only process SSS customers
		$thirdparty = new Societe($this->db);
		if ($thirdparty->fetch($doc->socid) <= 0) {
			return 0;
		}
		$thirdparty->fetch_optionals();
		if (empty($thirdparty->array_options['options_is_sss_customer'])) {
			return 0;
		}

		$processing = true;
		if ($doctype === 'facture') {
			$this->applyDiscountsInvoice($doc, $thirdparty);
		} else {
			$this->applyDiscountsPropal($doc, $thirdparty);
		}
		$processing = false;

		return 0;
	}

	private function applyDiscountsInvoice(Facture $invoice, Societe $thirdparty)
	{
		// ── 1. Reload and remove existing DISC- lines ─────────────────────────
		$invoice->fetch($invoice->id);
		foreach ($invoice->lines as $line) {
			if (strpos((string) ($line->label ?? ''), 'DISC-') === 0) {
				$invoice->deleteline($line->id);
			}
		}

		// ── 2. Reload after deletions ─────────────────────────────────────────
		$invoice->fetch($invoice->id);

		// ── 3. Calculate product-only subtotal ────────────────────────────────
		[$subtotal, $defaultTva] = $this->productSubtotal($invoice->lines);
		if ($subtotal <= 0) {
			return;
		}

		// ── 4/5. Add discount lines — type=1 (service), no product ───────────
		foreach ($this->discountDefs($subtotal, $thirdparty) as $d) {
			$invoice->addline(
				$d['desc'],   // desc
				-$d['amount'], // pu_ht (negative = discount)
				1,            // qty
				$defaultTva,  // txtva
				0,            // txlocaltax1
				0,            // txlocaltax2
				0,            // fk_product
				0,            // remise_percent
				'',           // date_start
				'',           // date_end
				0,            // fk_code_ventilation
				0,            // info_bits
				0,            // fk_remise_except
				'HT',         // price_base_type
				0,            // pu_ttc
				1,            // type (1=service)
				-1,           // rang
				0,            // special_code
				'',           // origin
				0,            // origin_id
				0,            // fk_parent_line
				null,         // fk_fournprice
				0,            // pa_ht
				$d['label']   // label — used to identify DISC- lines
			);
		}

		// ── 6. Recalculate invoice totals ─────────────────────────────────────
		$invoice->update_price(1);
	}

	private function applyDiscountsPropal(Propal $propal, Societe $thirdparty)
	{
		// ── 1. Reload and remove existing DISC- lines ─────────────────────────
		// Propal::deleteLine() (capital L, unlike Facture::deleteline()) and
		// ::addline() both internally call update_price(), and both require the
		// quote to still be in Draft status — same rule as editing any other line.
		$propal->fetch($propal->id);
		foreach ($propal->lines as $line) {
			if (strpos((string) ($line->label ?? ''), 'DISC-') === 0) {
				$propal->deleteLine($line->id);
			}
		}

		// ── 2. Reload after deletions ─────────────────────────────────────────
		$propal->fetch($propal->id);

		// ── 3. Calculate product-only subtotal ────────────────────────────────
		[$subtotal, $defaultTva] = $this->productSubtotal($propal->lines);
		if ($subtotal <= 0) {
			return;
		}

		// ── 4/5. Add discount lines — type=1 (service), no product ───────────
		// Propal::addline()'s parameter order differs from Facture::addline().
		foreach ($this->discountDefs($subtotal, $thirdparty) as $d) {
			$propal->addline(
				$d['desc'],    // desc
				-$d['amount'], // pu_ht (negative = discount)
				1,             // qty
				$defaultTva,   // txtva
				0,             // txlocaltax1
				0,             // txlocaltax2
				0,             // fk_product
				0,             // remise_percent
				'HT',          // price_base_type
				0,             // pu_ttc
				0,             // info_bits
				1,             // type (1=service)
				-1,            // rang
				0,             // special_code
				0,             // fk_parent_line
				0,             // fk_fournprice
				0,             // pa_ht
				$d['label']    // label — used to identify DISC- lines
			);
		}
	}

	/**
	 * @param object[] $lines
	 * @return array{0: float, 1: float} [product-only subtotal, VAT rate to use for discount lines]
	 */
	private function productSubtotal(array $lines): array
	{
		$subtotal   = 0.0;
		$defaultTva = 10.0;
		foreach ($lines as $line) {
			$subtotal += (float) $line->total_ht;
			if (!empty($line->tva_tx)) {
				$defaultTva = (float) $line->tva_tx;
			}
		}
		return [$subtotal, $defaultTva];
	}

	/**
	 * @return list<array{desc:string, amount:float, label:string}>
	 */
	private function discountDefs(float $subtotal, Societe $thirdparty): array
	{
		$zone_pct        = (float) getDolGlobalString('SSS_DISC_ZONE',       '2.5') / 100;
		$large_pct       = (float) getDolGlobalString('SSS_DISC_LARGE',      '5')   / 100;
		$member_pct      = (float) getDolGlobalString('SSS_DISC_MEMBER',     '10')  / 100;
		$large_order_min = (float) getDolGlobalString('SSS_LARGE_ORDER_MIN', '150');
		$is_member       = !empty($thirdparty->array_options['options_is_member']);
		$large_applies   = ($subtotal >= $large_order_min);

		$zone_amt   = round($subtotal * $zone_pct,   2);
		$large_amt  = $large_applies ? round($subtotal * $large_pct,  2) : 0.0;
		$member_amt = $is_member     ? round($subtotal * $member_pct, 2) : 0.0;

		$zpct = rtrim(rtrim(number_format($zone_pct   * 100, 2, '.', ''), '0'), '.');
		$lpct = rtrim(rtrim(number_format($large_pct  * 100, 2, '.', ''), '0'), '.');
		$mpct = rtrim(rtrim(number_format($member_pct * 100, 2, '.', ''), '0'), '.');

		return [
			[
				'desc'   => $zpct . '% Discount — delivery zone',
				'amount' => $zone_amt,
				'label'  => 'DISC-ZONE',
			],
			[
				'desc' => $large_applies
					? $lpct . '% Large order discount'
					: $lpct . '% Large order discount — order < $' . number_format($large_order_min, 0, '.', ','),
				'amount' => $large_amt,
				'label'  => 'DISC-LARGE',
			],
			[
				'desc' => $is_member
					? $mpct . '% Premium member discount'
					: $mpct . '% Premium member discount — not a member',
				'amount' => $member_amt,
				'label'  => 'DISC-MEMBER',
			],
		];
	}
}
