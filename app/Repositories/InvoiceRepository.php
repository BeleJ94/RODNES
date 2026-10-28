<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Shared\Money;

final class InvoiceRepository extends Repository
{
    private const SELECT = '
        SELECT i.*, c.name AS client_name, c.code AS client_code, o.reference AS order_reference, o.client_po_number,
               o.payment_terms_days,
               COALESCE((SELECT SUM(ROUND(l.quantity_milli * l.unit_price_minor / 1000)) FROM client_invoice_lines l WHERE l.invoice_id = i.id), 0) AS lines_subtotal,
               COALESCE((SELECT SUM(a.amount_minor) FROM client_payment_allocations a WHERE a.invoice_id = i.id), 0) AS paid_minor,
               COALESCE((SELECT SUM(ca.amount_minor) FROM client_credit_allocations ca WHERE ca.invoice_id = i.id), 0) AS credited_minor
          FROM client_invoices i
          JOIN clients c ON c.id = i.client_id
          JOIN client_orders o ON o.id = i.client_order_id';

    /**
     * @param array{status?: string, client_id?: ?int} $filters status : un statut, « open » (à encaisser) ou « overdue »
     * @return list<array<string, mixed>>
     */
    public function list(array $filters = []): array
    {
        $where = ['1 = 1'];
        $params = [];
        $status = $filters['status'] ?? '';
        if ($status === 'open') {
            $where[] = "i.status IN ('issued', 'partially_paid')";
        } elseif ($status === 'overdue') {
            $where[] = "i.status IN ('issued', 'partially_paid') AND i.due_date < CURDATE()";
        } elseif ($status !== '') {
            $where[] = 'i.status = :status';
            $params['status'] = $status;
        }
        if (!empty($filters['client_id'])) {
            $where[] = 'i.client_id = :client';
            $params['client'] = (int) $filters['client_id'];
        }
        $rows = $this->db->fetchAll(self::SELECT . ' WHERE ' . implode(' AND ', $where) . " ORDER BY i.status = 'draft' DESC, i.issue_date DESC, i.id DESC", $params);
        return array_map(self::hydrate(...), $rows);
    }

    /** @return array<string, mixed> */
    public function get(int $id): array
    {
        return self::hydrate(self::orFail($this->db->fetchOne(self::SELECT . ' WHERE i.id = :id', ['id' => $id])));
    }

    /** @return list<array<string, mixed>> */
    public function forClientOrder(int $clientOrderId): array
    {
        return array_map(self::hydrate(...), $this->db->fetchAll(self::SELECT . ' WHERE i.client_order_id = :id ORDER BY i.id', ['id' => $clientOrderId]));
    }

    /** @return list<array<string, mixed>> */
    public function lines(int $invoiceId): array
    {
        return $this->db->fetchAll(
            'SELECT l.*, ol.line_no AS order_line_no FROM client_invoice_lines l
               JOIN client_order_lines ol ON ol.id = l.client_order_line_id
              WHERE l.invoice_id = :id ORDER BY l.line_no',
            ['id' => $invoiceId],
        );
    }

    /** Quantité déjà facturée (factures non annulées, brouillons compris) pour une ligne de PO client. */
    public function invoicedFor(int $clientOrderLineId, ?int $excludeInvoiceId = null): int
    {
        return (int) $this->db->fetchValue(
            "SELECT COALESCE(SUM(l.quantity_milli), 0) FROM client_invoice_lines l JOIN client_invoices i ON i.id = l.invoice_id
              WHERE l.client_order_line_id = :line AND i.status <> 'cancelled' AND i.id <> :exclude",
            ['line' => $clientOrderLineId, 'exclude' => $excludeInvoiceId ?? 0],
        );
    }

    /**
     * Factures émises non soldées d'un client, avec reste dû (encaissements, relevé, balance âgée).
     *
     * @return list<array<string, mixed>>
     */
    public function openForClient(int $clientId): array
    {
        return array_values(array_filter(
            $this->list(['status' => 'open', 'client_id' => $clientId]),
            static fn (array $i): bool => $i['balance'] > 0,
        ));
    }

    /** Condition SQL : la ligne de PO client « ol » a du livré non encore facturé. */
    public const LINE_TO_INVOICE = "(SELECT COALESCE(SUM(shl.received_milli), 0) FROM shipment_lines shl
            JOIN supplier_order_lines sl ON sl.id = shl.supplier_order_line_id JOIN shipments sh ON sh.id = shl.shipment_id
           WHERE sl.client_order_line_id = ol.id AND sh.status = 'delivered')
        > (SELECT COALESCE(SUM(il.quantity_milli), 0) FROM client_invoice_lines il JOIN client_invoices ci ON ci.id = il.invoice_id
           WHERE il.client_order_line_id = ol.id AND ci.status <> 'cancelled')";

    /** PO clients ayant des quantités livrées pas encore facturées. */
    public function ordersToInvoiceCount(): int
    {
        return (int) $this->db->fetchValue(
            "SELECT COUNT(DISTINCT ol.client_order_id) FROM client_order_lines ol JOIN client_orders o ON o.id = ol.client_order_id
              WHERE o.status <> 'cancelled' AND " . self::LINE_TO_INVOICE
        );
    }

    /** @return array{outstanding: int, overdue: int, overdue_count: int, drafts: int, open_count: int} */
    public function kpis(): array
    {
        $out = ['outstanding' => 0, 'overdue' => 0, 'overdue_count' => 0, 'drafts' => 0, 'open_count' => 0];
        $today = date('Y-m-d');
        foreach ($this->list() as $invoice) {
            if ($invoice['status'] === InvoiceStatus::Draft->value) {
                $out['drafts']++;
            }
            if (InvoiceStatus::from($invoice['status'])->isOpen()) {
                $out['outstanding'] += $invoice['balance'];
                $out['open_count']++;
                if ($invoice['due_date'] < $today) {
                    $out['overdue'] += $invoice['balance'];
                    $out['overdue_count']++;
                }
            }
        }
        return $out;
    }

    /**
     * Mouvements d'un client pour le relevé : factures émises (débit), annulations, avoirs et encaissements (crédit).
     *
     * @return list<array{date: string, type: string, reference: string, id: int, debit: int, credit: int, note: ?string}>
     */
    public function statementEntries(int $clientId): array
    {
        $entries = [];
        foreach ($this->db->fetchAll(
            "SELECT id, reference, issue_date, cancelled_at, total_minor, status, due_date FROM client_invoices
              WHERE client_id = :c AND status <> 'draft'",
            ['c' => $clientId],
        ) as $i) {
            $entries[] = ['date' => (string) $i['issue_date'], 'type' => 'invoice', 'reference' => (string) $i['reference'], 'id' => (int) $i['id'],
                'debit' => (int) $i['total_minor'], 'credit' => 0, 'note' => (string) $i['due_date']];
            if ($i['status'] === 'cancelled') {
                $entries[] = ['date' => substr((string) $i['cancelled_at'], 0, 10), 'type' => 'cancellation', 'reference' => (string) $i['reference'], 'id' => (int) $i['id'],
                    'debit' => 0, 'credit' => (int) $i['total_minor'], 'note' => null];
            }
        }
        foreach ($this->db->fetchAll(
            'SELECT id, reference, received_on, amount_minor, method, bank_reference FROM client_payments WHERE client_id = :c',
            ['c' => $clientId],
        ) as $p) {
            $entries[] = ['date' => (string) $p['received_on'], 'type' => 'payment', 'reference' => (string) $p['reference'], 'id' => (int) $p['id'],
                'debit' => 0, 'credit' => (int) $p['amount_minor'], 'note' => $p['bank_reference']];
        }
        foreach ($this->db->fetchAll(
            'SELECT n.id, n.reference, n.issue_date, n.total_minor, i.reference AS invoice_reference FROM client_credit_notes n
               JOIN client_invoices i ON i.id = n.invoice_id WHERE n.client_id = :c',
            ['c' => $clientId],
        ) as $n) {
            $entries[] = ['date' => (string) $n['issue_date'], 'type' => 'credit_note', 'reference' => (string) $n['reference'], 'id' => (int) $n['id'],
                'debit' => 0, 'credit' => (int) $n['total_minor'], 'note' => (string) $n['invoice_reference']];
        }
        usort($entries, static fn (array $a, array $b): int => [$a['date'], $a['type'] === 'invoice' ? 0 : 1, $a['id']] <=> [$b['date'], $b['type'] === 'invoice' ? 0 : 1, $b['id']]);
        return $entries;
    }

    /**
     * Solde par client (factures émises − annulations − avoirs − encaissements), pour la liste des relevés.
     *
     * @return list<array<string, mixed>>
     */
    public function clientBalances(): array
    {
        return $this->db->fetchAll(
            "SELECT c.id, c.code, c.name, c.payment_terms_days,
                    COALESCE((SELECT SUM(i.total_minor) FROM client_invoices i WHERE i.client_id = c.id AND i.status NOT IN ('draft', 'cancelled')), 0) AS invoiced,
                    COALESCE((SELECT SUM(p.amount_minor) FROM client_payments p WHERE p.client_id = c.id), 0) AS received,
                    COALESCE((SELECT SUM(n.total_minor) FROM client_credit_notes n WHERE n.client_id = c.id), 0) AS credited,
                    (SELECT MAX(p.received_on) FROM client_payments p WHERE p.client_id = c.id) AS last_payment_on
               FROM clients c
              WHERE EXISTS (SELECT 1 FROM client_invoices i WHERE i.client_id = c.id AND i.status <> 'draft')
                 OR EXISTS (SELECT 1 FROM client_payments p WHERE p.client_id = c.id)
              ORDER BY c.name"
        );
    }

    /**
     * Totaux : figés à l'émission ; calculés pour un brouillon. Reste dû = total − encaissé − avoirs imputés.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function hydrate(array $row): array
    {
        if ($row['total_minor'] !== null) {
            $subtotal = (int) $row['subtotal_minor'];
            $vat = (int) $row['vat_minor'];
            $total = (int) $row['total_minor'];
        } else {
            $subtotal = (int) $row['lines_subtotal'];
            $vat = $row['vat_applicable'] ? Money::percentOf($subtotal, (int) $row['vat_rate_bp']) : 0;
            $total = $subtotal + $vat;
        }
        $paid = (int) $row['paid_minor'];
        $credited = (int) $row['credited_minor'];
        return $row + [
            'subtotal' => $subtotal,
            'vat' => $vat,
            'total' => $total,
            'paid' => $paid,
            'credited' => $credited,
            'balance' => in_array($row['status'], ['draft', 'cancelled'], true) ? 0 : max(0, $total - $paid - $credited),
        ];
    }
}
