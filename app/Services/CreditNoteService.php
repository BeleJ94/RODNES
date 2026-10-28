<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\Invoicing\CreditNoteRules;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Invoicing\Receivables;
use App\Domain\Shared\Money;
use App\Domain\Shared\RuleViolation;
use App\Repositories\CreditNoteRepository;
use App\Repositories\InvoiceRepository;
use App\Security\Auth;

/**
 * Avoirs clients : sur une facture émise (payée ou non), dans la limite de ce qui a été facturé.
 * À l'émission, l'avoir solde le reste dû de sa facture ; le surplus est un crédit client imputable sur d'autres factures.
 */
final class CreditNoteService
{
    public function __construct(
        private Database $db,
        private CreditNoteRepository $notes,
        private InvoiceRepository $invoices,
        private InvoiceService $invoiceService,
        private PaymentService $payments,
        private Sequences $sequences,
        private ActivityLogger $logger,
        private Auth $auth,
    ) {
    }

    /**
     * @param array{issue_date: string, reason: string, is_return: bool, notes: ?string} $header
     * @param array<int, array{quantity: int, price: int}> $lines ligne de facture → quantité et prix unitaire crédités
     */
    public function issue(int $invoiceId, array $header, array $lines): int
    {
        return $this->db->transaction(function () use ($invoiceId, $header, $lines): int {
            $invoice = $this->invoices->get($invoiceId);
            $status = InvoiceStatus::from((string) $invoice['status']);
            if ($status === InvoiceStatus::Draft || $status === InvoiceStatus::Cancelled) {
                throw new RuleViolation('credit_notes.error_invoice_status');
            }
            $available = array_column($this->notes->creditableLines($invoiceId), null, 'id');
            $lines = array_filter($lines, static fn (array $l): bool => $l['quantity'] > 0);
            if ($lines === []) {
                throw new RuleViolation('credit_notes.error_no_lines');
            }
            $subtotal = 0;
            foreach ($lines as $lineId => $l) {
                $il = $available[$lineId] ?? throw new RuleViolation('credit_notes.error_no_lines');
                $error = CreditNoteRules::lineError((int) $il['quantity_milli'], (int) $il['unit_price_minor'], (int) $il['credited_amount'], $l['quantity'], $l['price']);
                if ($error !== null) {
                    throw new RuleViolation($error, ['n' => (int) $il['line_no']]);
                }
                $subtotal += Money::lineAmount($l['quantity'], $l['price']);
            }
            $vat = $invoice['vat_applicable'] ? Money::percentOf($subtotal, (int) $invoice['vat_rate_bp']) : 0;
            $total = $subtotal + $vat;
            $reference = $this->sequences->next('credit_note', 'AV');
            $id = $this->db->insert(
                'INSERT INTO client_credit_notes (reference, client_id, invoice_id, issue_date, reason, is_return, vat_applicable, vat_rate_bp,
                        subtotal_minor, vat_minor, total_minor, notes, created_by)
                 VALUES (:ref, :client, :invoice, :date, :reason, :ret, :vat_app, :vat_bp, :sub, :vat, :total, :notes, :user)',
                [
                    'ref' => $reference, 'client' => (int) $invoice['client_id'], 'invoice' => $invoiceId, 'date' => $header['issue_date'],
                    'reason' => $header['reason'], 'ret' => $header['is_return'], 'vat_app' => (bool) $invoice['vat_applicable'],
                    'vat_bp' => (int) $invoice['vat_rate_bp'], 'sub' => $subtotal, 'vat' => $vat, 'total' => $total,
                    'notes' => $header['notes'], 'user' => $this->auth->user()?->id,
                ],
            );
            $no = 0;
            foreach ($lines as $lineId => $l) {
                $il = $available[$lineId];
                $this->db->run(
                    'INSERT INTO client_credit_note_lines (credit_note_id, line_no, invoice_line_id, client_order_line_id, description, quantity_milli, unit, unit_price_minor)
                     VALUES (:n, :no, :il, :ol, :d, :q, :u, :p)',
                    ['n' => $id, 'no' => ++$no, 'il' => $lineId, 'ol' => (int) $il['client_order_line_id'], 'd' => $il['description'],
                        'q' => $l['quantity'], 'u' => $il['unit'], 'p' => $l['price']],
                );
            }
            $onInvoice = min($total, (int) $invoice['balance']);
            if ($onInvoice > 0) {
                $this->allocate($id, [$invoiceId => $onInvoice]);
            }
            $this->logger->log('credit_note', $id, 'created', [], [
                'reference' => $reference, 'invoice' => $invoice['reference'], 'total_minor' => $total, 'on_invoice' => $onInvoice, 'reason' => $header['reason'],
            ]);
            $this->logger->log('invoice', $invoiceId, 'credit_note', [], ['credit_note' => $reference, 'total_minor' => $total]);
            return $id;
        });
    }

    /**
     * Impute le crédit restant d'un avoir sur d'autres factures ouvertes du client.
     *
     * @param array<int, int> $allocations facture → montant
     */
    public function allocateRemaining(int $creditNoteId, array $allocations): void
    {
        $this->db->transaction(function () use ($creditNoteId, $allocations): void {
            $note = $this->notes->get($creditNoteId);
            $allocations = array_filter($allocations, static fn (int $a): bool => $a > 0);
            if ($allocations === []) {
                throw new RuleViolation('payments.error_nothing_allocated');
            }
            $already = array_map('intval', array_column($this->notes->allocations($creditNoteId), 'invoice_id'));
            if (array_intersect(array_keys($allocations), $already) !== []) {
                throw new RuleViolation('payments.error_invoice');
            }
            $error = Receivables::checkAllocation((int) $note['unallocated'], $allocations, $this->payments->balances((int) $note['client_id']));
            if ($error !== null) {
                throw new RuleViolation($error);
            }
            $this->allocate($creditNoteId, $allocations);
            $this->logger->log('credit_note', $creditNoteId, 'allocated', [], ['allocated' => array_sum($allocations)]);
        });
    }

    /** @param array<int, int> $allocations */
    private function allocate(int $creditNoteId, array $allocations): void
    {
        foreach ($allocations as $invoiceId => $amount) {
            $this->db->run(
                'INSERT INTO client_credit_allocations (credit_note_id, invoice_id, amount_minor) VALUES (:n, :i, :a)',
                ['n' => $creditNoteId, 'i' => $invoiceId, 'a' => $amount],
            );
            $this->invoiceService->refreshStatus($invoiceId);
        }
    }
}
