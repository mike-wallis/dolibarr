<?php
/**
 * Invoice Line Columns — hook handler.
 *
 * The brightcs/southside invoice PDF templates (custom/core/modules/facture/doc/)
 * want their line-item columns in the order:
 *   Item / Description | Price (ex GST) | Qty | GST | Amount (ex GST)
 * with the GST column showing the calculated dollar amount, not the tax rate.
 *
 * Dolibarr's shared PDF engine (pdf_crabe::write_file(), which brightcs/southside
 * extend) draws those columns in a fixed order — GST%, Price, Qty — using three
 * small helper functions in core/lib/pdf.lib.php, each of which calls an
 * 'addreplace' hook before falling back to its own default text. Rather than
 * duplicating that ~780-line core method to reorder the columns, this class
 * hooks each of those three functions and prints the OTHER column's value in
 * its slot — a 3-way swap that lands each value in the position the template
 * wants without moving anything on the page or touching core files:
 *
 *   slot originally for ...   ends up printing ...
 *   pdf_getlinevatrate()      Price (ex GST)   [was: GST %]
 *   pdf_getlineupexcltax()    Qty               [was: Price]
 *   pdf_getlineqty()          GST ($ amount)    [was: Qty]
 *
 * The matching header labels/positions live in pdf_brightcs.modules.php's
 * _tableau() override — the two files must be read together.
 *
 * Only applies when $object->model_pdf is 'brightcs' or 'southside', so other
 * PDF models (default Dolibarr templates, other doctypes) are unaffected.
 */
class ActionsInvoicelines
{
    public $db;
    public $error    = '';
    public $errors   = [];
    public $resprints = '';

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Hook: pdf_getlinevatrate — repurposed to print Price (ex GST).
     */
    public function pdf_getlinevatrate($parameters, &$object, &$action, $hookmanager)
    {
        if (!$this->appliesTo($object)) {
            return 0;
        }
        if (!empty($parameters['hidedetails']) && $parameters['hidedetails'] > 1) {
            return 0;
        }

        $line = $object->lines[$parameters['i']];
        if (!empty($line->special_code) && $line->special_code == 3) {
            $this->resprints = '';
            return 1;
        }

        $sign     = $this->creditNoteSign($object);
        $subprice = (isModEnabled('multicurrency') && $object->multicurrency_tx != 1)
            ? $line->multicurrency_subprice
            : $line->subprice;

        $this->resprints = price($sign * $subprice, 0, $parameters['outputlangs']);
        return 1;
    }

    /**
     * Hook: pdf_getlineupexcltax — repurposed to print Qty.
     */
    public function pdf_getlineupexcltax($parameters, &$object, &$action, $hookmanager)
    {
        if (!$this->appliesTo($object)) {
            return 0;
        }
        if (!empty($parameters['hidedetails']) && $parameters['hidedetails'] > 1) {
            return 0;
        }

        $line = $object->lines[$parameters['i']];
        if (!empty($line->special_code) && $line->special_code == 3) {
            $this->resprints = '';
            return 1;
        }

        $this->resprints = (string) $line->qty;
        return 1;
    }

    /**
     * Hook: pdf_getlineqty — repurposed to print the GST amount for the line.
     */
    public function pdf_getlineqty($parameters, &$object, &$action, $hookmanager)
    {
        if (!$this->appliesTo($object)) {
            return 0;
        }
        if (!empty($parameters['hidedetails']) && $parameters['hidedetails'] > 1) {
            return 0;
        }

        $line = $object->lines[$parameters['i']];
        if (!empty($line->special_code) && $line->special_code == 3) {
            $this->resprints = '';
            return 1;
        }

        $sign      = $this->creditNoteSign($object);
        $total_tva = (isModEnabled('multicurrency') && $object->multicurrency_tx != 1)
            ? $line->multicurrency_total_tva
            : $line->total_tva;

        $this->resprints = price($sign * $total_tva, 0, $parameters['outputlangs']);
        return 1;
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function appliesTo($object): bool
    {
        if (!is_object($object) || empty($object->model_pdf)) {
            return false;
        }
        $model = explode(':', $object->model_pdf, 2)[0];
        return in_array($model, ['brightcs', 'southside'], true);
    }

    private function creditNoteSign($object): int
    {
        return (isset($object->type) && $object->type == 2 && getDolGlobalString('INVOICE_POSITIVE_CREDIT_NOTE')) ? -1 : 1;
    }
}
