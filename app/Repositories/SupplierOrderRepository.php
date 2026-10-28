<?php

declare(strict_types=1);

namespace App\Repositories;

final class SupplierOrderRepository extends Repository
{
    private const SELECT = '
        SELECT so.*, s.name AS supplier_name, s.code AS supplier_code, s.contact_name AS supplier_contact,
               s.contact_email AS supplier_email, s.country AS supplier_country,
               co.reference AS client_order_reference, co.client_po_number, co.client_id, c.name AS client_name,
               COALESCE((SELECT SUM(ROUND(l.quantity_milli * l.unit_price_minor / 1000)) FROM supplier_order_lines l WHERE l.supplier_order_id = so.id), 0) AS total
          FROM supplier_orders so
          JOIN suppliers s ON s.id = so.supplier_id
          JOIN client_orders co ON co.id = so.client_order_id
          JOIN clients c ON c.id = co.client_id';

    /**
     * @param array{status?: string} $filters status : un statut ou « open » (brouillon, envoyé, confirmé)
     * @return list<array<string, mixed>>
     */
    public function list(array $filters = []): array
    {
        $status = $filters['status'] ?? '';
        [$where, $params] = match (true) {
            $status === 'open' => ["so.status IN ('draft', 'sent', 'confirmed')", []],
            $status !== '' => ['so.status = :status', ['status' => $status]],
            default => ['1 = 1', []],
        };
        return $this->db->fetchAll(self::SELECT . " WHERE {$where} ORDER BY so.status = 'draft' DESC, so.status = 'sent' DESC, so.id DESC", $params);
    }

    /** @return array<string, mixed> */
    public function get(int $id): array
    {
        return self::orFail($this->db->fetchOne(self::SELECT . ' WHERE so.id = :id', ['id' => $id]));
    }

    /** @return list<array<string, mixed>> */
    public function forClientOrder(int $clientOrderId): array
    {
        return $this->db->fetchAll(self::SELECT . ' WHERE so.client_order_id = :id ORDER BY so.id', ['id' => $clientOrderId]);
    }

    /** @return list<array<string, mixed>> */
    public function lines(int $supplierOrderId): array
    {
        return $this->db->fetchAll(
            'SELECT l.*, cl.line_no AS client_line_no, cl.quantity_milli AS client_qty_milli
               FROM supplier_order_lines l JOIN client_order_lines cl ON cl.id = l.client_order_line_id
              WHERE l.supplier_order_id = :id ORDER BY l.line_no',
            ['id' => $supplierOrderId],
        );
    }

    /**
     * Lignes fournisseurs (PO non annulés) servant un PO client : coût réel d'achat par ligne client.
     *
     * @return list<array<string, mixed>>
     */
    public function allocationsForClientOrder(int $clientOrderId): array
    {
        return $this->db->fetchAll(
            "SELECT l.client_order_line_id, l.quantity_milli, l.unit_price_minor, so.id AS supplier_order_id, so.reference,
                    so.status, so.currency_code, so.fx_units_per_usd_micro, s.name AS supplier_name
               FROM supplier_order_lines l
               JOIN supplier_orders so ON so.id = l.supplier_order_id
               JOIN suppliers s ON s.id = so.supplier_id
              WHERE so.client_order_id = :id AND so.status <> 'cancelled'
              ORDER BY so.id",
            ['id' => $clientOrderId],
        );
    }

    /** Quantité déjà commandée aux fournisseurs pour une ligne client, hors un PO donné (modification). */
    public function allocatedFor(int $clientLineId, ?int $excludeSupplierOrderId = null): int
    {
        return (int) $this->db->fetchValue(
            "SELECT COALESCE(SUM(l.quantity_milli), 0) FROM supplier_order_lines l
               JOIN supplier_orders so ON so.id = l.supplier_order_id
              WHERE l.client_order_line_id = :line AND so.status <> 'cancelled' AND so.id <> :exclude",
            ['line' => $clientLineId, 'exclude' => $excludeSupplierOrderId ?? 0],
        );
    }

    /** @return array{draft: int, sent: int, unconfirmed: int, confirmed: int} */
    public function kpis(int $unconfirmedAfterDays = 3): array
    {
        $row = $this->db->fetchOne(
            "SELECT COALESCE(SUM(status = 'draft'), 0) AS draft,
                    COALESCE(SUM(status = 'sent'), 0) AS sent,
                    COALESCE(SUM(status = 'sent' AND sent_at < NOW() - INTERVAL :d DAY), 0) AS unconfirmed,
                    COALESCE(SUM(status = 'confirmed'), 0) AS confirmed
               FROM supplier_orders",
            ['d' => $unconfirmedAfterDays],
        ) ?? [];
        return array_map('intval', $row);
    }
}
