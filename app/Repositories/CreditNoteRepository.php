<?php

declare(strict_types=1);

namespace App\Repositories;

final class CreditNoteRepository extends Repository
{
    private const SELECT = '
        SELECT n.*, c.name AS client_name, c.code AS client_code, i.reference AS invoice_reference, i.client_order_id,
               o.reference AS order_reference, o.client_po_number, u.name AS created_by_name,
               COALESCE((SELECT SUM(a.amount_minor) FROM client_credit_allocations a WHERE a.credit_note_id = n.id), 0) AS allocated_minor
          FROM client_credit_notes n
          JOIN clients c ON c.id = n.client_id
          JOIN client_invoices i ON i.id = n.invoice_id
          JOIN client_orders o ON o.id = i.client_order_id
          LEFT JOIN users u ON u.id = n.created_by';

    /**
     * @param array{unallocated?: bool, client_id?: ?int} $filters
     * @return list<array<string, mixed>>
     */
    public function list(array $filters = []): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (!empty($filters['client_id'])) {
            $where[] = 'n.client_id = :c';
            $params['c'] = (int) $filters['client_id'];
        }
        $rows = array_map(self::hydrate(...), $this->db->fetchAll(self::SELECT . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY n.issue_date DESC, n.id DESC', $params));
        return !empty($filters['unallocated']) ? array_values(array_filter($rows, static fn (array $r): bool => $r['unallocated'] > 0)) : $rows;
    }

    /** @return array<string, mixed> */
    public function get(int $id): array
    {
        return self::hydrate(self::orFail($this->db->fetchOne(self::SELECT . ' WHERE n.id = :id', ['id' => $id])));
    }

    /** @return list<array<string, mixed>> */
    public function forInvoice(int $invoiceId): array
    {
        return array_map(self::hydrate(...), $this->db->fetchAll(self::SELECT . ' WHERE n.invoice_id = :id ORDER BY n.id', ['id' => $invoiceId]));
    }

    /** @return list<array<string, mixed>> */
    public function lines(int $creditNoteId): array
    {
        return $this->db->fetchAll(
            'SELECT l.*, il.line_no AS invoice_line_no, il.quantity_milli AS invoiced_milli, il.unit_price_minor AS invoiced_price_minor
               FROM client_credit_note_lines l JOIN client_invoice_lines il ON il.id = l.invoice_line_id
              WHERE l.credit_note_id = :id ORDER BY l.line_no',
            ['id' => $creditNoteId],
        );
    }

    /** @return list<array<string, mixed>> */
    public function allocations(int $creditNoteId): array
    {
        return $this->db->fetchAll(
            'SELECT a.*, i.reference, i.issue_date, i.total_minor, i.status FROM client_credit_allocations a
               JOIN client_invoices i ON i.id = a.invoice_id WHERE a.credit_note_id = :id ORDER BY a.id',
            ['id' => $creditNoteId],
        );
    }

    /**
     * Lignes d'une facture avec le montant et la quantité déjà crédités (tous avoirs).
     *
     * @return list<array<string, mixed>>
     */
    public function creditableLines(int $invoiceId): array
    {
        return $this->db->fetchAll(
            'SELECT il.*,
                    COALESCE((SELECT SUM(ROUND(cl.quantity_milli * cl.unit_price_minor / 1000)) FROM client_credit_note_lines cl WHERE cl.invoice_line_id = il.id), 0) AS credited_amount,
                    COALESCE((SELECT SUM(cl.quantity_milli) FROM client_credit_note_lines cl WHERE cl.invoice_line_id = il.id), 0) AS credited_milli
               FROM client_invoice_lines il
              WHERE il.invoice_id = :id ORDER BY il.line_no',
            ['id' => $invoiceId],
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function hydrate(array $row): array
    {
        return $row + ['unallocated' => (int) $row['total_minor'] - (int) $row['allocated_minor']];
    }
}
