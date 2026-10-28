<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Shared\Fx;

final class SupplierPaymentRepository extends Repository
{
    private const SELECT = '
        SELECT p.*, s.name AS supplier_name, s.code AS supplier_code,
               COALESCE((SELECT SUM(a.amount_minor) FROM supplier_payment_allocations a WHERE a.payment_id = p.id), 0) AS allocated_minor,
               (SELECT GROUP_CONCAT(i.reference ORDER BY i.reference SEPARATOR \', \') FROM supplier_payment_allocations a
                  JOIN supplier_invoices i ON i.id = a.invoice_id WHERE a.payment_id = p.id) AS invoice_references
          FROM supplier_payments p
          JOIN suppliers s ON s.id = p.supplier_id';

    /**
     * @param array{supplier_id?: int|null} $filters
     * @return list<array<string, mixed>>
     */
    public function list(array $filters = []): array
    {
        $where = '1 = 1';
        $params = [];
        if (!empty($filters['supplier_id'])) {
            $where = 'p.supplier_id = :s';
            $params['s'] = (int) $filters['supplier_id'];
        }
        return array_map(self::hydrate(...), $this->db->fetchAll(self::SELECT . " WHERE {$where} ORDER BY p.paid_on DESC, p.id DESC", $params));
    }

    /** @return array<string, mixed> */
    public function get(int $id): array
    {
        return self::hydrate(self::orFail($this->db->fetchOne(self::SELECT . ' WHERE p.id = :id', ['id' => $id])));
    }

    /** @return list<array<string, mixed>> */
    public function allocations(int $paymentId): array
    {
        return $this->db->fetchAll(
            'SELECT a.*, i.reference, i.supplier_invoice_number, i.invoice_date, i.total_minor, i.status, i.fx_units_per_usd_micro AS invoice_fx
               FROM supplier_payment_allocations a JOIN supplier_invoices i ON i.id = a.invoice_id
              WHERE a.payment_id = :id ORDER BY i.due_date, i.id',
            ['id' => $paymentId],
        );
    }

    /** @return list<array<string, mixed>> */
    public function forInvoice(int $invoiceId): array
    {
        return $this->db->fetchAll(
            'SELECT a.amount_minor AS allocated, p.id, p.reference, p.paid_on, p.method, p.bank_reference, p.fx_units_per_usd_micro
               FROM supplier_payment_allocations a JOIN supplier_payments p ON p.id = a.payment_id
              WHERE a.invoice_id = :id ORDER BY p.paid_on, p.id',
            ['id' => $invoiceId],
        );
    }

    /**
     * Imputations (montant, taux du paiement) par facture fournisseur, pour le coût réel.
     *
     * @param list<int> $invoiceIds
     * @return array<int, list<array{amount: int, fx: int}>>
     */
    public function allocationsByInvoice(array $invoiceIds): array
    {
        if ($invoiceIds === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $invoiceIds));
        $out = [];
        foreach ($this->db->fetchAll(
            "SELECT a.invoice_id, a.amount_minor, p.fx_units_per_usd_micro FROM supplier_payment_allocations a
               JOIN supplier_payments p ON p.id = a.payment_id WHERE a.invoice_id IN ({$in}) ORDER BY p.paid_on, p.id",
        ) as $row) {
            $out[(int) $row['invoice_id']][] = ['amount' => (int) $row['amount_minor'], 'fx' => (int) $row['fx_units_per_usd_micro']];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function hydrate(array $row): array
    {
        $fx = (int) $row['fx_units_per_usd_micro'];
        $unallocated = (int) $row['amount_minor'] - (int) $row['allocated_minor'];
        return $row + [
            'unallocated' => $unallocated,
            'amount_usd' => Fx::toUsd((int) $row['amount_minor'], $fx),
        ];
    }
}
