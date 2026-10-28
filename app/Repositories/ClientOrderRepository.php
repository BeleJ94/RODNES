<?php

declare(strict_types=1);

namespace App\Repositories;

final class ClientOrderRepository extends Repository
{
    private const SELECT = '
        SELECT o.*, c.name AS client_name, c.code AS client_code, r.reference AS rfq_reference,
               q.reference AS quotation_reference, q.version AS quotation_version,
               COALESCE((SELECT SUM(ROUND(l.quantity_milli * l.unit_price_minor / 1000)) FROM client_order_lines l WHERE l.client_order_id = o.id), 0) AS subtotal,
               COALESCE((SELECT SUM(l.quantity_milli) FROM client_order_lines l WHERE l.client_order_id = o.id), 0) AS ordered_milli,
               COALESCE((SELECT SUM(LEAST(l.quantity_milli, (
                    SELECT COALESCE(SUM(sl.quantity_milli), 0) FROM supplier_order_lines sl
                      JOIN supplier_orders so ON so.id = sl.supplier_order_id
                     WHERE sl.client_order_line_id = l.id AND so.status <> \'cancelled\')))
                  FROM client_order_lines l WHERE l.client_order_id = o.id), 0) AS covered_milli
          FROM client_orders o
          JOIN clients c ON c.id = o.client_id
          LEFT JOIN rfqs r ON r.id = o.rfq_id
          LEFT JOIN quotations q ON q.id = o.quotation_id';

    /**
     * @param array{status?: string} $filters status : un statut ou « open » (non clos ni annulé)
     * @return list<array<string, mixed>>
     */
    public function list(array $filters = []): array
    {
        $status = $filters['status'] ?? '';
        [$where, $params] = match (true) {
            $status === 'open' => ["o.status IN ('received', 'ordering', 'ordered', 'partially_delivered', 'delivered')", []],
            $status === 'to_order' => ["o.status IN ('received', 'ordering')", []],
            $status === 'to_invoice' => ["o.status <> 'cancelled' AND EXISTS (SELECT 1 FROM client_order_lines ol WHERE ol.client_order_id = o.id AND " . InvoiceRepository::LINE_TO_INVOICE . ')', []],
            $status !== '' => ['o.status = :status', ['status' => $status]],
            default => ['1 = 1', []],
        };
        return $this->db->fetchAll(self::SELECT . " WHERE {$where} ORDER BY o.status IN ('received', 'ordering') DESC, o.received_on DESC, o.id DESC", $params);
    }

    /** @return array<string, mixed> */
    public function get(int $id): array
    {
        return self::orFail($this->db->fetchOne(self::SELECT . ' WHERE o.id = :id', ['id' => $id]));
    }

    /** @return list<array<string, mixed>> */
    public function forQuotation(int $quotationId): array
    {
        return $this->db->fetchAll(self::SELECT . ' WHERE o.quotation_id = :id ORDER BY o.id', ['id' => $quotationId]);
    }

    /** @return list<array<string, mixed>> */
    public function forRfq(int $rfqId): array
    {
        return $this->db->fetchAll(self::SELECT . ' WHERE o.rfq_id = :id ORDER BY o.id', ['id' => $rfqId]);
    }

    /**
     * Lignes avec quantité déjà commandée aux fournisseurs (PO non annulés) et rappel de la quotation (écarts).
     *
     * @return list<array<string, mixed>>
     */
    public function lines(int $orderId): array
    {
        return $this->db->fetchAll(
            "SELECT l.*, ql.quantity_milli AS quoted_qty_milli, ql.unit_price_minor AS quoted_price_minor,
                    (SELECT COALESCE(SUM(sl.quantity_milli), 0) FROM supplier_order_lines sl
                       JOIN supplier_orders so ON so.id = sl.supplier_order_id
                      WHERE sl.client_order_line_id = l.id AND so.status <> 'cancelled') AS allocated_milli,
                    (SELECT COALESCE(SUM(shl.received_milli), 0) FROM shipment_lines shl
                       JOIN supplier_order_lines sl2 ON sl2.id = shl.supplier_order_line_id
                       JOIN shipments sh ON sh.id = shl.shipment_id
                      WHERE sl2.client_order_line_id = l.id AND sh.status = 'delivered') AS delivered_milli,
                    sq.supplier_id AS default_supplier_id, s.name AS default_supplier_name
               FROM client_order_lines l
               LEFT JOIN quotation_lines ql ON ql.id = l.quotation_line_id
               LEFT JOIN supplier_quote_lines sql_ ON sql_.id = l.supplier_quote_line_id
               LEFT JOIN supplier_quotes sq ON sq.id = sql_.supplier_quote_id
               LEFT JOIN suppliers s ON s.id = sq.supplier_id
              WHERE l.client_order_id = :id ORDER BY l.line_no",
            ['id' => $orderId],
        );
    }

    public function hasSupplierOrders(int $orderId): bool
    {
        return (bool) $this->db->fetchValue("SELECT EXISTS(SELECT 1 FROM supplier_orders WHERE client_order_id = :id AND status <> 'cancelled')", ['id' => $orderId]);
    }

    /** @return array{open: int, open_amount: int, to_order: int, late_to_order: int} */
    public function kpis(int $lateAfterDays = 2): array
    {
        $row = $this->db->fetchOne(
            "SELECT COALESCE(SUM(t.status IN ('received', 'ordering', 'ordered', 'partially_delivered', 'delivered')), 0) AS open,
                    COALESCE(SUM(CASE WHEN t.status IN ('received', 'ordering', 'ordered', 'partially_delivered', 'delivered') THEN t.subtotal END), 0) AS open_amount,
                    COALESCE(SUM(t.status IN ('received', 'ordering')), 0) AS to_order,
                    COALESCE(SUM(t.status IN ('received', 'ordering') AND t.received_on <= CURDATE() - INTERVAL :d DAY), 0) AS late_to_order
               FROM (SELECT o.status, o.received_on,
                            COALESCE((SELECT SUM(ROUND(l.quantity_milli * l.unit_price_minor / 1000)) FROM client_order_lines l WHERE l.client_order_id = o.id), 0) AS subtotal
                       FROM client_orders o) t",
            ['d' => $lateAfterDays],
        ) ?? [];
        return array_map('intval', $row);
    }

    /** @return list<array<string, mixed>> bon(s) de commande du client, avec le fichier et l'auteur */
    public function files(int $orderId): array
    {
        return $this->db->fetchAll(
            'SELECT cf.id, cf.created_at, f.id AS file_id, f.original_name, f.mime, f.size_bytes, u.name AS created_by_name
               FROM client_order_files cf JOIN files f ON f.id = cf.file_id LEFT JOIN users u ON u.id = cf.created_by
              WHERE cf.client_order_id = :id ORDER BY cf.id',
            ['id' => $orderId],
        );
    }
}
