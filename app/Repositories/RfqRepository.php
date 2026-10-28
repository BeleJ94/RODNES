<?php

declare(strict_types=1);

namespace App\Repositories;

final class RfqRepository extends Repository
{
    private const SELECT = "
        SELECT r.*, c.name AS client_name, c.code AS client_code, u.name AS owner_name,
               (SELECT COUNT(*) FROM rfq_lines l WHERE l.rfq_id = r.id) AS line_count,
               (SELECT COUNT(*) FROM supplier_quotes q WHERE q.rfq_id = r.id) AS requests,
               (SELECT COUNT(*) FROM supplier_quotes q WHERE q.rfq_id = r.id AND q.status = 'received') AS offers
          FROM rfqs r
          JOIN clients c ON c.id = r.client_id
          LEFT JOIN users u ON u.id = r.owner_id";

    /**
     * @param array{status?: string, client_id?: ?int} $filters status : un statut, « open » (à traiter) ou « late »
     * @return list<array<string, mixed>>
     */
    public function list(array $filters = []): array
    {
        $where = ['1 = 1'];
        $params = [];
        $status = $filters['status'] ?? '';
        if ($status === 'open') {
            $where[] = "r.status IN ('received', 'sourcing')";
        } elseif ($status === 'late') {
            $where[] = "r.status IN ('received', 'sourcing') AND r.due_at < NOW()";
        } elseif ($status !== '') {
            $where[] = 'r.status = :status';
            $params['status'] = $status;
        }
        if (!empty($filters['client_id'])) {
            $where[] = 'r.client_id = :client';
            $params['client'] = (int) $filters['client_id'];
        }
        return $this->db->fetchAll(
            self::SELECT . ' WHERE ' . implode(' AND ', $where) . "
             ORDER BY r.status IN ('received', 'sourcing') DESC, r.due_at IS NULL, r.due_at, r.id DESC",
            $params,
        );
    }

    /** @return array<string, mixed> */
    public function get(int $id): array
    {
        return self::orFail($this->db->fetchOne(self::SELECT . ' WHERE r.id = :id', ['id' => $id]));
    }

    /** @return list<array<string, mixed>> */
    public function lines(int $rfqId): array
    {
        return $this->db->fetchAll(
            'SELECT l.*, i.code AS item_code, i.manufacturer, i.part_number FROM rfq_lines l LEFT JOIN items i ON i.id = l.item_id
              WHERE l.rfq_id = :id ORDER BY l.line_no',
            ['id' => $rfqId],
        );
    }

    /** Les lignes sont-elles déjà reprises par une offre fournisseur ou une quotation ? */
    public function linesAreReferenced(int $rfqId): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT EXISTS(SELECT 1 FROM supplier_quotes WHERE rfq_id = :a) OR EXISTS(SELECT 1 FROM quotations WHERE rfq_id = :b)',
            ['a' => $rfqId, 'b' => $rfqId],
        );
    }

    /** @return array{open: int, late: int, due_soon: int, quoted: int, won_year: int, lost_year: int} */
    public function kpis(): array
    {
        $row = $this->db->fetchOne(
            "SELECT COALESCE(SUM(status IN ('received', 'sourcing')), 0) AS open,
                    COALESCE(SUM(status IN ('received', 'sourcing') AND due_at < NOW()), 0) AS late,
                    COALESCE(SUM(status IN ('received', 'sourcing') AND due_at >= NOW() AND due_at < NOW() + INTERVAL 2 DAY), 0) AS due_soon,
                    COALESCE(SUM(status = 'quoted'), 0) AS quoted,
                    COALESCE(SUM(status = 'won' AND YEAR(received_on) = YEAR(CURDATE())), 0) AS won_year,
                    COALESCE(SUM(status = 'lost' AND YEAR(received_on) = YEAR(CURDATE())), 0) AS lost_year
               FROM rfqs"
        ) ?? [];
        return array_map('intval', $row);
    }

    /** @return list<array<string, mixed>> pièces jointes du client, avec le fichier et l'auteur */
    public function files(int $rfqId): array
    {
        return $this->db->fetchAll(
            'SELECT rf.id, rf.created_at, f.id AS file_id, f.original_name, f.mime, f.size_bytes, u.name AS created_by_name
               FROM rfq_files rf JOIN files f ON f.id = rf.file_id LEFT JOIN users u ON u.id = rf.created_by
              WHERE rf.rfq_id = :id ORDER BY rf.id',
            ['id' => $rfqId],
        );
    }
}
