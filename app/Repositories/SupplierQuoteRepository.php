<?php

declare(strict_types=1);

namespace App\Repositories;

final class SupplierQuoteRepository extends Repository
{
    private const SELECT = '
        SELECT q.*, s.name AS supplier_name, s.code AS supplier_code, s.contact_email AS supplier_email,
               r.reference AS rfq_reference, r.status AS rfq_status
          FROM supplier_quotes q
          JOIN suppliers s ON s.id = q.supplier_id
          JOIN rfqs r ON r.id = q.rfq_id';

    /** @return list<array<string, mixed>> */
    public function forRfq(int $rfqId): array
    {
        return $this->db->fetchAll(self::SELECT . ' WHERE q.rfq_id = :id ORDER BY s.name', ['id' => $rfqId]);
    }

    /** @return array<string, mixed> */
    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne(self::SELECT . ' WHERE q.id = :id', ['id' => $id]);
    }

    public function get(int $id): array
    {
        return self::orFail($this->find($id));
    }

    /** @return array<int, array<string, mixed>> rfq_line_id → ligne d'offre */
    public function lines(int $supplierQuoteId): array
    {
        $rows = $this->db->fetchAll('SELECT * FROM supplier_quote_lines WHERE supplier_quote_id = :id', ['id' => $supplierQuoteId]);
        return array_column($rows, null, 'rfq_line_id');
    }

    /**
     * Toutes les offres reçues pour une RFQ, avec devise et fournisseur (comparaison, choix du coût).
     *
     * @return list<array<string, mixed>>
     */
    public function offersForRfq(int $rfqId): array
    {
        return $this->db->fetchAll(
            "SELECT l.id, l.rfq_line_id, l.unit_price_minor, COALESCE(l.lead_time_days, q.lead_time_days) AS lead_time_days,
                    q.id AS supplier_quote_id, q.currency_code, q.incoterm, s.name AS supplier_name, s.code AS supplier_code
               FROM supplier_quote_lines l
               JOIN supplier_quotes q ON q.id = l.supplier_quote_id
               JOIN suppliers s ON s.id = q.supplier_id
              WHERE q.rfq_id = :id AND q.status = 'received'
              ORDER BY s.name",
            ['id' => $rfqId],
        );
    }

    /** Prix proposé par un fournisseur pour une ligne de RFQ (offre reçue), ou null. */
    public function offerFor(int $supplierId, int $rfqLineId): ?array
    {
        return $this->db->fetchOne(
            "SELECT l.unit_price_minor, q.currency_code FROM supplier_quote_lines l JOIN supplier_quotes q ON q.id = l.supplier_quote_id
              WHERE q.supplier_id = :s AND l.rfq_line_id = :l AND q.status = 'received'",
            ['s' => $supplierId, 'l' => $rfqLineId],
        );
    }

    public function pendingCount(int $olderThanDays): int
    {
        return (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM supplier_quotes q JOIN rfqs r ON r.id = q.rfq_id
              WHERE q.status = 'requested' AND q.requested_on <= CURDATE() - INTERVAL :d DAY AND r.status IN ('received', 'sourcing')",
            ['d' => $olderThanDays],
        );
    }

    /** @return list<array<string, mixed>> lignes demandées au fournisseur, avec la quantité de la demande */
    public function requestedLines(int $supplierQuoteId): array
    {
        return $this->db->fetchAll(
            'SELECT l.*, r.quantity_milli AS requested_milli, i.code AS item_code, i.manufacturer, i.part_number
               FROM supplier_quote_requested_lines r
               JOIN rfq_lines l ON l.id = r.rfq_line_id
               LEFT JOIN items i ON i.id = l.item_id
              WHERE r.supplier_quote_id = :id ORDER BY l.line_no',
            ['id' => $supplierQuoteId],
        );
    }

    /** @return array<int, int> demande → nombre de lignes demandées, pour une RFQ */
    public function requestedCounts(int $rfqId): array
    {
        return array_map('intval', array_column($this->db->fetchAll(
            'SELECT q.id, COUNT(r.rfq_line_id) AS n FROM supplier_quotes q LEFT JOIN supplier_quote_requested_lines r ON r.supplier_quote_id = q.id
              WHERE q.rfq_id = :id GROUP BY q.id',
            ['id' => $rfqId],
        ), 'n', 'id'));
    }

    /** @return list<array<string, mixed>> fichiers de l'offre (scan, PDF, e-mail du fournisseur) */
    public function files(int $supplierQuoteId): array
    {
        return $this->db->fetchAll(
            'SELECT sf.id, sf.created_at, f.id AS file_id, f.original_name, f.mime, f.size_bytes, u.name AS created_by_name
               FROM supplier_quote_files sf JOIN files f ON f.id = sf.file_id LEFT JOIN users u ON u.id = sf.created_by
              WHERE sf.supplier_quote_id = :id ORDER BY sf.id',
            ['id' => $supplierQuoteId],
        );
    }
}
