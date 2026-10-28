<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\Invoicing\Receivables;
use App\Domain\Purchasing\SupplierInvoiceStatus as S;
use App\Domain\Shared\RuleViolation;
use App\Repositories\SupplierInvoiceRepository;
use App\Repositories\SupplierPaymentRepository;
use App\Repositories\SupplierRepository;
use App\Security\Auth;

/**
 * Paiements fournisseurs : un paiement dans une devise solde des factures « bon à payer » de cette devise.
 * Le taux du jour du paiement fixe le coût réel en USD (écart de change avec le PO et la facture).
 * Un acompte (payé avant facture) reste en avance fournisseur, imputable ensuite sur les factures de sa devise.
 */
final class SupplierPaymentService
{
    public function __construct(
        private Database $db,
        private SupplierPaymentRepository $payments,
        private SupplierInvoiceRepository $invoices,
        private SupplierRepository $suppliers,
        private Sequences $sequences,
        private ActivityLogger $logger,
        private Auth $auth,
    ) {
    }

    /**
     * @param array{paid_on: string, currency_code: string, amount_minor: int, fx_units_per_usd_micro: int, method: string, bank_reference: ?string, notes: ?string} $header
     * @param array<int, int> $allocations facture → montant (devise du paiement)
     */
    public function record(int $supplierId, array $header, array $allocations): int
    {
        return $this->db->transaction(function () use ($supplierId, $header, $allocations): int {
            $this->suppliers->get($supplierId);
            $allocations = array_filter($allocations, static fn (int $a): bool => $a > 0);
            $this->check($supplierId, $header['currency_code'], $header['amount_minor'], $allocations);
            $reference = $this->sequences->next('supplier_payment', 'PF');
            $id = $this->db->insert(
                'INSERT INTO supplier_payments (reference, supplier_id, paid_on, currency_code, amount_minor, fx_units_per_usd_micro, method, bank_reference, notes, created_by)
                 VALUES (:ref, :supplier, :on, :cur, :amount, :fx, :method, :bank, :notes, :user)',
                [
                    'ref' => $reference, 'supplier' => $supplierId, 'on' => $header['paid_on'], 'cur' => $header['currency_code'],
                    'amount' => $header['amount_minor'], 'fx' => $header['fx_units_per_usd_micro'], 'method' => $header['method'],
                    'bank' => $header['bank_reference'], 'notes' => $header['notes'], 'user' => $this->auth->user()?->id,
                ],
            );
            $this->allocate($id, $allocations);
            $this->logger->log('supplier_payment', $id, 'created', [], $header + ['reference' => $reference, 'allocated' => array_sum($allocations)]);
            return $id;
        });
    }

    /**
     * Impute le reste d'un paiement (acompte) sur des factures devenues bon à payer.
     *
     * @param array<int, int> $allocations facture → montant
     */
    public function allocateRemaining(int $paymentId, array $allocations): void
    {
        $this->db->transaction(function () use ($paymentId, $allocations): void {
            $payment = $this->payments->get($paymentId);
            $allocations = array_filter($allocations, static fn (int $a): bool => $a > 0);
            if ($allocations === []) {
                throw new RuleViolation('supplier_payments.error_nothing_allocated');
            }
            $already = array_map('intval', array_column($this->payments->allocations($paymentId), 'invoice_id'));
            if (array_intersect(array_keys($allocations), $already) !== []) {
                throw new RuleViolation('supplier_payments.error_invoice');
            }
            $this->check((int) $payment['supplier_id'], (string) $payment['currency_code'], (int) $payment['unallocated'], $allocations);
            $this->allocate($paymentId, $allocations);
            $this->logger->log('supplier_payment', $paymentId, 'allocated', [], ['allocated' => array_sum($allocations)]);
        });
    }

    /** @param array<int, int> $allocations */
    private function check(int $supplierId, string $currency, int $available, array $allocations): void
    {
        $balances = array_column(array_map(
            static fn (array $i): array => ['id' => (int) $i['id'], 'balance' => (int) $i['balance']],
            $this->invoices->payableFor($supplierId, $currency),
        ), 'balance', 'id');
        $error = Receivables::checkAllocation($available, $allocations, $balances);
        if ($error !== null) {
            throw new RuleViolation(str_replace('payments.', 'supplier_payments.', $error));
        }
    }

    /** @param array<int, int> $allocations */
    private function allocate(int $paymentId, array $allocations): void
    {
        foreach ($allocations as $invoiceId => $amount) {
            $this->db->run(
                'INSERT INTO supplier_payment_allocations (payment_id, invoice_id, amount_minor) VALUES (:p, :i, :a)',
                ['p' => $paymentId, 'i' => $invoiceId, 'a' => $amount],
            );
            $this->refreshStatus($invoiceId);
        }
    }

    private function refreshStatus(int $invoiceId): void
    {
        $invoice = $this->invoices->get($invoiceId);
        $current = S::from((string) $invoice['status']);
        $next = S::fromPayments((int) $invoice['total_minor'], (int) $invoice['paid']);
        if ($next !== $current) {
            $this->db->run('UPDATE supplier_invoices SET status = :s WHERE id = :id', ['s' => $next->value, 'id' => $invoiceId]);
            $this->logger->log('supplier_invoice', $invoiceId, 'status', ['status' => $current->value], ['status' => $next->value]);
        }
    }
}
