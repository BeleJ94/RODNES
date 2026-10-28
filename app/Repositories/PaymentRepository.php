<?php

declare(strict_types=1);

namespace App\Repositories;

final class PaymentRepository extends Repository
{
    private const SELECT = '
        SELECT p.*, c.name AS client_name, c.code AS client_code, u.name AS created_by_name,
               COALESCE((SELECT SUM(a.amount_minor) FROM client_payment_allocations a WHERE a.payment_id = p.id), 0) AS allocated_minor,
               (SELECT GROUP_CONCAT(i.reference ORDER BY i.reference SEPARATOR \', \') FROM client_payment_allocations a
                  JOIN client_invoices i ON i.id = a.invoice_id WHERE a.payment_id = p.id) AS invoice_references
          FROM client_payments p
          JOIN clients c ON c.id = p.client_id
          LEFT JOIN users u ON u.id = p.created_by';

    /**
     * @param array{unallocated?: bool} $filters
     * @return list<array<string, mixed>>
     */
    public function list(array $filters = []): array
    {
        $rows = $this->db->fetchAll(self::SELECT . ' ORDER BY p.received_on DESC, p.id DESC');
        if (!empty($filters['unallocated'])) {
            $rows = array_values(array_filter($rows, static fn (array $p): bool => (int) $p['amount_minor'] > (int) $p['allocated_minor']));
        }
        return $rows;
    }

    /** @return array<string, mixed> */
    public function get(int $id): array
    {
        return self::orFail($this->db->fetchOne(self::SELECT . ' WHERE p.id = :id', ['id' => $id]));
    }

    /** @return list<array<string, mixed>> */
    public function allocations(int $paymentId): array
    {
        return $this->db->fetchAll(
            'SELECT a.*, i.reference, i.issue_date, i.due_date, i.total_minor, i.status FROM client_payment_allocations a
               JOIN client_invoices i ON i.id = a.invoice_id WHERE a.payment_id = :id ORDER BY i.due_date, i.id',
            ['id' => $paymentId],
        );
    }

    /** @return list<array<string, mixed>> encaissements affectés à une facture */
    public function forInvoice(int $invoiceId): array
    {
        return $this->db->fetchAll(
            'SELECT a.amount_minor AS allocated, p.id, p.reference, p.received_on, p.method, p.bank_reference
               FROM client_payment_allocations a JOIN client_payments p ON p.id = a.payment_id
              WHERE a.invoice_id = :id ORDER BY p.received_on, p.id',
            ['id' => $invoiceId],
        );
    }

    /** @return list<array<string, mixed>> encaissements d'un client avec un reste non affecté (avances) */
    public function unallocatedForClient(int $clientId): array
    {
        return array_values(array_filter(
            $this->db->fetchAll(self::SELECT . ' WHERE p.client_id = :c ORDER BY p.received_on', ['c' => $clientId]),
            static fn (array $p): bool => (int) $p['amount_minor'] > (int) $p['allocated_minor'],
        ));
    }
}
