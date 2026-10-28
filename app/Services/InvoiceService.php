<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Orders\ClientOrderStatus;
use App\Domain\Shared\Money;
use App\Domain\Shared\RuleViolation;
use App\Repositories\ClientOrderRepository;
use App\Repositories\InvoiceRepository;
use App\Security\Auth;

/**
 * Factures client : on ne facture que le livré (POD), jamais deux fois la même quantité.
 * Brouillon modifiable sans numéro ; à l'émission : numéro FAC-AAAA-NNNN, totaux et échéance figés.
 */
final class InvoiceService
{
    public function __construct(
        private Database $db,
        private InvoiceRepository $invoices,
        private ClientOrderRepository $orders,
        private Sequences $sequences,
        private ActivityLogger $logger,
        private Auth $auth,
    ) {
    }

    /**
     * Lignes du PO client avec quantité livrée, déjà facturée et facturable.
     *
     * @return list<array<string, mixed>>
     */
    public function invoiceableLines(int $clientOrderId, ?int $excludeInvoiceId = null): array
    {
        return array_map(function (array $line) use ($excludeInvoiceId): array {
            $invoiced = $this->invoices->invoicedFor((int) $line['id'], $excludeInvoiceId);
            return $line + ['invoiced_milli' => $invoiced, 'invoiceable_milli' => max(0, (int) $line['delivered_milli'] - $invoiced)];
        }, $this->orders->lines($clientOrderId));
    }

    /** @param array<int, int> $lines ligne de PO client → quantité à facturer (millièmes) */
    public function createDraft(int $clientOrderId, array $lines, ?string $notes): int
    {
        return $this->db->transaction(function () use ($clientOrderId, $lines, $notes): int {
            $order = $this->orders->get($clientOrderId);
            if (ClientOrderStatus::from((string) $order['status']) === ClientOrderStatus::Cancelled) {
                throw new RuleViolation('client_orders.error_final');
            }
            $available = array_column($this->invoiceableLines($clientOrderId), null, 'id');
            $lines = array_filter($lines, static fn (int $q): bool => $q > 0);
            if ($lines === []) {
                throw new RuleViolation('invoices.error_no_lines');
            }
            foreach ($lines as $lineId => $qty) {
                $line = $available[$lineId] ?? throw new RuleViolation('invoices.error_no_lines');
                if ($qty > (int) $line['invoiceable_milli']) {
                    throw new RuleViolation('invoices.error_over_delivered', ['n' => (int) $line['line_no']]);
                }
            }
            $id = $this->db->insert(
                'INSERT INTO client_invoices (client_id, client_order_id, vat_applicable, vat_rate_bp, notes, created_by)
                 VALUES (:client, :order, :vat, :vat_bp, :notes, :user)',
                [
                    'client' => (int) $order['client_id'], 'order' => $clientOrderId, 'vat' => (bool) $order['vat_applicable'],
                    'vat_bp' => (int) $order['vat_rate_bp'], 'notes' => $notes, 'user' => $this->auth->user()?->id,
                ],
            );
            $no = 0;
            foreach ($lines as $lineId => $qty) {
                $line = $available[$lineId];
                $this->db->run(
                    'INSERT INTO client_invoice_lines (invoice_id, line_no, client_order_line_id, description, quantity_milli, unit, unit_price_minor)
                     VALUES (:i, :no, :line, :d, :q, :u, :p)',
                    ['i' => $id, 'no' => ++$no, 'line' => $lineId, 'd' => $line['description'], 'q' => $qty, 'u' => $line['unit'], 'p' => (int) $line['unit_price_minor']],
                );
            }
            $this->logger->log('invoice', $id, 'created', [], ['client_order_id' => $clientOrderId, 'lines' => $no]);
            return $id;
        });
    }

    /** @param array<int, int> $lines ligne de facture → quantité (0 = ligne retirée) */
    public function updateDraft(int $id, array $lines, bool $vatApplicable, ?string $notes): void
    {
        $this->db->transaction(function () use ($id, $lines, $vatApplicable, $notes): void {
            $invoice = $this->invoices->get($id);
            if (!InvoiceStatus::from((string) $invoice['status'])->isEditable()) {
                throw new RuleViolation('invoices.error_not_draft');
            }
            $available = array_column($this->invoiceableLines((int) $invoice['client_order_id'], $id), null, 'id');
            $kept = 0;
            foreach ($this->invoices->lines($id) as $line) {
                $qty = $lines[(int) $line['id']] ?? (int) $line['quantity_milli'];
                if ($qty === 0) {
                    $this->db->run('DELETE FROM client_invoice_lines WHERE id = :id', ['id' => (int) $line['id']]);
                    continue;
                }
                if ($qty > (int) ($available[(int) $line['client_order_line_id']]['invoiceable_milli'] ?? 0)) {
                    throw new RuleViolation('invoices.error_over_delivered', ['n' => (int) $line['order_line_no']]);
                }
                $this->db->run('UPDATE client_invoice_lines SET quantity_milli = :q WHERE id = :id', ['q' => $qty, 'id' => (int) $line['id']]);
                $kept++;
            }
            if ($kept === 0) {
                throw new RuleViolation('invoices.error_no_lines');
            }
            $this->db->run('UPDATE client_invoices SET vat_applicable = :v, notes = :n WHERE id = :id', ['v' => $vatApplicable, 'n' => $notes, 'id' => $id]);
            $this->logger->log('invoice', $id, 'updated', ['vat_applicable' => $invoice['vat_applicable'], 'notes' => $invoice['notes']], ['vat_applicable' => $vatApplicable, 'notes' => $notes]);
        });
    }

    /** Émission : numéro définitif, totaux et échéance figés. */
    public function issue(int $id, string $issueDate): string
    {
        return $this->db->transaction(function () use ($id, $issueDate): string {
            $invoice = $this->invoices->get($id);
            if (!InvoiceStatus::from((string) $invoice['status'])->isEditable()) {
                throw new RuleViolation('invoices.error_not_draft');
            }
            $lines = $this->invoices->lines($id);
            if ($lines === []) {
                throw new RuleViolation('invoices.error_no_lines');
            }
            $subtotal = 0;
            foreach ($lines as $line) {
                $subtotal += Money::lineAmount((int) $line['quantity_milli'], (int) $line['unit_price_minor']);
            }
            $vat = $invoice['vat_applicable'] ? Money::percentOf($subtotal, (int) $invoice['vat_rate_bp']) : 0;
            $reference = $this->sequences->next('invoice', 'FAC');
            $due = date('Y-m-d', strtotime($issueDate . ' +' . (int) $invoice['payment_terms_days'] . ' days'));
            $this->db->run(
                "UPDATE client_invoices SET status = 'issued', reference = :ref, issue_date = :issue, due_date = :due,
                        subtotal_minor = :sub, vat_minor = :vat, total_minor = :total, issued_at = NOW(), issued_by = :user
                  WHERE id = :id",
                ['ref' => $reference, 'issue' => $issueDate, 'due' => $due, 'sub' => $subtotal, 'vat' => $vat, 'total' => $subtotal + $vat,
                    'user' => $this->auth->user()?->id, 'id' => $id],
            );
            $this->logger->log('invoice', $id, 'issue', ['status' => 'draft'], ['status' => 'issued', 'reference' => $reference, 'total_minor' => $subtotal + $vat, 'due_date' => $due]);
            return $reference;
        });
    }

    public function deleteDraft(int $id): int
    {
        return $this->db->transaction(function () use ($id): int {
            $invoice = $this->invoices->get($id);
            if (!InvoiceStatus::from((string) $invoice['status'])->isEditable()) {
                throw new RuleViolation('invoices.error_not_draft');
            }
            $this->db->run('DELETE FROM client_invoices WHERE id = :id', ['id' => $id]);
            $this->logger->log('invoice', $id, 'deleted', ['status' => 'draft'], []);
            return (int) $invoice['client_order_id'];
        });
    }

    /** Annulation d'une facture émise sans encaissement : le numéro reste dans la série, les quantités redeviennent facturables. */
    public function cancel(int $id, string $reason): void
    {
        $this->db->transaction(function () use ($id, $reason): void {
            $invoice = $this->invoices->get($id);
            if ((string) $invoice['status'] !== InvoiceStatus::Issued->value || (int) $invoice['paid'] > 0 || (int) $invoice['credited'] > 0
                || $this->db->fetchValue('SELECT 1 FROM client_credit_notes WHERE invoice_id = :id LIMIT 1', ['id' => $id])) {
                throw new RuleViolation('invoices.error_cancel');
            }
            $this->db->run(
                "UPDATE client_invoices SET status = 'cancelled', cancelled_at = NOW(), cancelled_reason = :r WHERE id = :id",
                ['r' => $reason, 'id' => $id],
            );
            $this->logger->log('invoice', $id, 'cancel', ['status' => 'issued'], ['status' => 'cancelled', 'reason' => $reason]);
        });
    }

    /** Statut d'une facture émise d'après les encaissements et avoirs imputés. */
    public function refreshStatus(int $id): void
    {
        $invoice = $this->invoices->get($id);
        $current = InvoiceStatus::from((string) $invoice['status']);
        if ($current === InvoiceStatus::Draft || $current === InvoiceStatus::Cancelled) {
            return;
        }
        $next = InvoiceStatus::fromPayments((int) $invoice['total'], (int) $invoice['paid'] + (int) $invoice['credited']);
        if ($next !== $current) {
            $this->db->run('UPDATE client_invoices SET status = :s WHERE id = :id', ['s' => $next->value, 'id' => $id]);
            $this->logger->log('invoice', $id, 'status', ['status' => $current->value], ['status' => $next->value]);
        }
    }
}
