<?php

declare(strict_types=1);

namespace App\Repositories;

final class QuotationRepository extends Repository
{
    /** Totaux calculés en SQL avec le même arrondi que Money::lineAmount (au centime, moitié loin de zéro). */
    private const SELECT = '
        SELECT q.*, c.name AS client_name, c.code AS client_code, r.reference AS rfq_reference, r.client_reference,
               u.name AS approved_by_name,
               COALESCE((SELECT SUM(ROUND(l.quantity_milli * l.unit_price_minor / 1000)) FROM quotation_lines l WHERE l.quotation_id = q.id), 0) AS subtotal,
               COALESCE((SELECT SUM(ROUND(l.quantity_milli * (l.cost_unit_usd_minor + l.extra_unit_usd_minor) / 1000)) FROM quotation_lines l WHERE l.quotation_id = q.id), 0) AS cost
          FROM quotations q
          JOIN clients c ON c.id = q.client_id
          JOIN rfqs r ON r.id = q.rfq_id
          LEFT JOIN users u ON u.id = q.approved_by';

    /**
     * @param array{status?: string} $filters status : un statut ou « expiring » (envoyées, validité ≤ 5 jours)
     * @return list<array<string, mixed>>
     */
    public function list(array $filters = []): array
    {
        $where = ["q.status <> 'superseded'"];
        $params = [];
        $status = $filters['status'] ?? '';
        if ($status === 'expiring') {
            $where[] = "q.status = 'sent' AND q.valid_until >= CURDATE() AND q.valid_until < CURDATE() + INTERVAL 5 DAY";
        } elseif ($status === 'to_close') {
            $where[] = "q.status = 'sent' AND q.valid_until < CURDATE()";
        } elseif ($status !== '') {
            $where = ['q.status = :status'];
            $params['status'] = $status;
        }
        return $this->db->fetchAll(self::SELECT . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY q.id DESC', $params);
    }

    /** @return list<array<string, mixed>> toutes versions comprises */
    public function forRfq(int $rfqId): array
    {
        return $this->db->fetchAll(self::SELECT . ' WHERE q.rfq_id = :id ORDER BY q.reference, q.version DESC', ['id' => $rfqId]);
    }

    /** @return array<string, mixed> */
    public function get(int $id): array
    {
        return self::orFail($this->db->fetchOne(self::SELECT . ' WHERE q.id = :id', ['id' => $id]));
    }

    /** @return list<array<string, mixed>> */
    public function lines(int $quotationId): array
    {
        return $this->db->fetchAll(
            'SELECT l.*, s.name AS supplier_name, i.code AS item_code, i.manufacturer, i.part_number
               FROM quotation_lines l
               LEFT JOIN rfq_lines rl ON rl.id = l.rfq_line_id
               LEFT JOIN items i ON i.id = rl.item_id
               LEFT JOIN supplier_quote_lines sl ON sl.id = l.supplier_quote_line_id
               LEFT JOIN supplier_quotes sq ON sq.id = sl.supplier_quote_id
               LEFT JOIN suppliers s ON s.id = sq.supplier_id
              WHERE l.quotation_id = :id ORDER BY l.line_no',
            ['id' => $quotationId],
        );
    }

    /** @return list<array<string, mixed>> versions de la même référence */
    public function versions(string $reference): array
    {
        return $this->db->fetchAll(
            'SELECT id, version, status, created_at FROM quotations WHERE reference = :ref ORDER BY version DESC',
            ['ref' => $reference],
        );
    }

    /** @return array{draft: int, pending_approval: int, sent: int, sent_amount: int, expiring: int, accepted_year: int, accepted_amount_year: int} */
    public function kpis(): array
    {
        $row = $this->db->fetchOne(
            "SELECT COALESCE(SUM(t.status = 'draft'), 0) AS draft,
                    COALESCE(SUM(t.status = 'pending_approval'), 0) AS pending_approval,
                    COALESCE(SUM(t.status = 'sent'), 0) AS sent,
                    COALESCE(SUM(CASE WHEN t.status = 'sent' THEN t.subtotal END), 0) AS sent_amount,
                    COALESCE(SUM(t.status = 'sent' AND t.valid_until >= CURDATE() AND t.valid_until < CURDATE() + INTERVAL 5 DAY), 0) AS expiring,
                    COALESCE(SUM(t.status = 'sent' AND t.valid_until < CURDATE()), 0) AS to_close,
                    COALESCE(SUM(t.status = 'accepted' AND YEAR(t.decided_at) = YEAR(CURDATE())), 0) AS accepted_year,
                    COALESCE(SUM(CASE WHEN t.status = 'accepted' AND YEAR(t.decided_at) = YEAR(CURDATE()) THEN t.subtotal END), 0) AS accepted_amount_year
               FROM (SELECT q.status, q.valid_until, q.decided_at,
                            COALESCE((SELECT SUM(ROUND(l.quantity_milli * l.unit_price_minor / 1000)) FROM quotation_lines l WHERE l.quotation_id = q.id), 0) AS subtotal
                       FROM quotations q) t"
        ) ?? [];
        return array_map('intval', $row);
    }
}
