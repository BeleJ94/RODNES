<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\Invoicing\Receivables;
use App\Domain\Shared\RuleViolation;
use App\Repositories\ClientRepository;
use App\Repositories\InvoiceRepository;
use App\Repositories\PaymentRepository;
use App\Security\Auth;

/** Encaissements clients : saisie, affectation aux factures ouvertes (jamais au-delà du reste dû), avances. */
final class PaymentService
{
    public const METHODS = ['bank_transfer', 'cheque', 'cash', 'mobile_money', 'other'];

    public function __construct(
        private Database $db,
        private PaymentRepository $payments,
        private InvoiceRepository $invoices,
        private ClientRepository $clients,
        private InvoiceService $invoiceService,
        private Sequences $sequences,
        private ActivityLogger $logger,
        private Auth $auth,
    ) {
    }

    /**
     * @param array{received_on: string, amount_minor: int, method: string, bank_reference: ?string, notes: ?string} $header
     * @param array<int, int> $allocations facture → montant ; le reste éventuel reste en avance
     */
    public function record(int $clientId, array $header, array $allocations): int
    {
        return $this->db->transaction(function () use ($clientId, $header, $allocations): int {
            $this->clients->get($clientId);
            $allocations = array_filter($allocations, static fn (int $a): bool => $a > 0);
            $error = Receivables::checkAllocation($header['amount_minor'], $allocations, $this->balances($clientId));
            if ($error !== null) {
                throw new RuleViolation($error);
            }
            $reference = $this->sequences->next('client_payment', 'ENC');
            $id = $this->db->insert(
                'INSERT INTO client_payments (reference, client_id, received_on, amount_minor, method, bank_reference, notes, created_by)
                 VALUES (:ref, :client, :on, :amount, :method, :bank, :notes, :user)',
                [
                    'ref' => $reference, 'client' => $clientId, 'on' => $header['received_on'], 'amount' => $header['amount_minor'],
                    'method' => $header['method'], 'bank' => $header['bank_reference'], 'notes' => $header['notes'], 'user' => $this->auth->user()?->id,
                ],
            );
            $this->allocate($id, $allocations);
            $this->logger->log('payment', $id, 'created', [], $header + ['reference' => $reference, 'allocated' => array_sum($allocations)]);
            return $id;
        });
    }

    /**
     * Affecte le reste non affecté d'un encaissement (avance) à des factures ouvertes.
     *
     * @param array<int, int> $allocations facture → montant
     */
    public function allocateRemaining(int $paymentId, array $allocations): void
    {
        $this->db->transaction(function () use ($paymentId, $allocations): void {
            $payment = $this->payments->get($paymentId);
            $allocations = array_filter($allocations, static fn (int $a): bool => $a > 0);
            $already = array_column($this->payments->allocations($paymentId), 'invoice_id');
            if (array_intersect(array_keys($allocations), array_map('intval', $already)) !== []) {
                throw new RuleViolation('payments.error_invoice');
            }
            $remaining = (int) $payment['amount_minor'] - (int) $payment['allocated_minor'];
            $error = Receivables::checkAllocation($remaining, $allocations, $this->balances((int) $payment['client_id']));
            if ($error !== null || $allocations === []) {
                throw new RuleViolation($error ?? 'payments.error_nothing_allocated');
            }
            $this->allocate($paymentId, $allocations);
            $this->logger->log('payment', $paymentId, 'allocated', [], ['allocated' => array_sum($allocations)]);
        });
    }

    /** @return array<int, int> facture ouverte → reste dû */
    public function balances(int $clientId): array
    {
        return array_column(array_map(
            static fn (array $i): array => ['id' => (int) $i['id'], 'balance' => (int) $i['balance']],
            $this->invoices->openForClient($clientId),
        ), 'balance', 'id');
    }

    /** @param array<int, int> $allocations */
    private function allocate(int $paymentId, array $allocations): void
    {
        foreach ($allocations as $invoiceId => $amount) {
            $this->db->run(
                'INSERT INTO client_payment_allocations (payment_id, invoice_id, amount_minor) VALUES (:p, :i, :a)',
                ['p' => $paymentId, 'i' => $invoiceId, 'a' => $amount],
            );
            $this->invoiceService->refreshStatus($invoiceId);
        }
    }
}
