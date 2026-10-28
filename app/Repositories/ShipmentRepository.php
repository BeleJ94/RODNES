<?php

declare(strict_types=1);

namespace App\Repositories;

final class ShipmentRepository extends Repository
{
    private const SELECT = "
        SELECT sh.*, c.name AS client_name, p.id AS pod_id, p.received_at AS pod_received_at,
               p.file_id AS pod_file_id, pf.mime AS pod_mime, pf.original_name AS pod_file_name,
               (SELECT COUNT(*) FROM shipment_lines l WHERE l.shipment_id = sh.id) AS line_count,
               (SELECT GROUP_CONCAT(DISTINCT so.reference ORDER BY so.reference SEPARATOR ', ')
                  FROM shipment_lines l JOIN supplier_order_lines sl ON sl.id = l.supplier_order_line_id
                  JOIN supplier_orders so ON so.id = sl.supplier_order_id WHERE l.shipment_id = sh.id) AS po_references,
               (SELECT COUNT(*) FROM shipment_required_documents r WHERE r.shipment_id = sh.id) AS docs_required,
               (SELECT COUNT(*) FROM shipment_required_documents r WHERE r.shipment_id = sh.id
                   AND NOT EXISTS (SELECT 1 FROM shipment_files d WHERE d.shipment_id = sh.id AND d.doc_type_id = r.doc_type_id)) AS docs_missing
          FROM shipments sh
          JOIN clients c ON c.id = sh.client_id
          LEFT JOIN pods p ON p.shipment_id = sh.id
          LEFT JOIN files pf ON pf.id = p.file_id";

    private const ACTIVE = "sh.status NOT IN ('delivered', 'cancelled')";

    /** Expédition non annulée à laquelle il manque au moins un document exigé. */
    private const DOCS_MISSING = "sh.status <> 'cancelled' AND EXISTS (SELECT 1 FROM shipment_required_documents r WHERE r.shipment_id = sh.id
        AND NOT EXISTS (SELECT 1 FROM shipment_files d WHERE d.shipment_id = sh.id AND d.doc_type_id = r.doc_type_id))";

    /**
     * @param array{status?: string} $filters status : un statut, « open » (en cours) ou « late » (ETA dépassée)
     * @return list<array<string, mixed>>
     */
    public function list(array $filters = []): array
    {
        $status = $filters['status'] ?? '';
        [$where, $params] = match (true) {
            $status === 'open' => [self::ACTIVE, []],
            $status === 'late' => [self::ACTIVE . ' AND sh.eta < CURDATE()', []],
            $status === 'docs_missing' => [self::DOCS_MISSING, []],
            $status === 'pod_pending' => ["sh.status = 'delivered' AND p.id IS NULL", []],
            $status !== '' => ['sh.status = :status', ['status' => $status]],
            default => ['1 = 1', []],
        };
        return $this->db->fetchAll(self::SELECT . " WHERE {$where} ORDER BY " . self::ACTIVE . ' DESC, sh.eta IS NULL, sh.eta, sh.id DESC', $params);
    }

    /** @return array<string, mixed> */
    public function get(int $id): array
    {
        return self::orFail($this->db->fetchOne(self::SELECT . ' WHERE sh.id = :id', ['id' => $id]));
    }

    /** @return list<array<string, mixed>> expéditions contenant des lignes d'un PO client */
    public function forClientOrder(int $clientOrderId): array
    {
        return $this->db->fetchAll(
            self::SELECT . ' WHERE sh.id IN (SELECT l.shipment_id FROM shipment_lines l
                JOIN supplier_order_lines sl ON sl.id = l.supplier_order_line_id
                JOIN supplier_orders so ON so.id = sl.supplier_order_id WHERE so.client_order_id = :id)
              ORDER BY sh.id',
            ['id' => $clientOrderId],
        );
    }

    /** @return list<array<string, mixed>> */
    public function lines(int $shipmentId): array
    {
        return $this->db->fetchAll(
            'SELECT l.*, sl.description, sl.unit, sl.quantity_milli AS ordered_milli, so.id AS supplier_order_id, so.reference AS supplier_order_reference,
                    s.name AS supplier_name, co.id AS client_order_id, co.reference AS client_order_reference, co.client_po_number
               FROM shipment_lines l
               JOIN supplier_order_lines sl ON sl.id = l.supplier_order_line_id
               JOIN supplier_orders so ON so.id = sl.supplier_order_id
               JOIN suppliers s ON s.id = so.supplier_id
               JOIN client_orders co ON co.id = so.client_order_id
              WHERE l.shipment_id = :id ORDER BY co.id, so.id, sl.line_no',
            ['id' => $shipmentId],
        );
    }

    /**
     * Lignes de PO fournisseurs envoyés ou confirmés d'un client, restant à expédier.
     *
     * @return list<array<string, mixed>>
     */
    public function shippable(int $clientId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM (
                SELECT sl.id, sl.line_no, sl.description, sl.unit, sl.quantity_milli AS ordered_milli, so.id AS supplier_order_id, so.reference AS supplier_order_reference,
                       so.expected_ready_on, s.name AS supplier_name, co.reference AS client_order_reference, co.client_po_number, co.delivery_site,
                       (SELECT COALESCE(SUM(l.quantity_milli), 0) FROM shipment_lines l JOIN shipments sh ON sh.id = l.shipment_id
                         WHERE l.supplier_order_line_id = sl.id AND sh.status <> 'cancelled') AS shipped_milli
                  FROM supplier_order_lines sl
                  JOIN supplier_orders so ON so.id = sl.supplier_order_id
                  JOIN suppliers s ON s.id = so.supplier_id
                  JOIN client_orders co ON co.id = so.client_order_id
                 WHERE co.client_id = :client AND so.status IN ('sent', 'confirmed') AND co.status NOT IN ('closed', 'cancelled')
             ) t WHERE t.shipped_milli < t.ordered_milli
             ORDER BY t.client_order_reference, t.supplier_order_reference, t.line_no",
            ['client' => $clientId],
        );
    }

    /** @return list<array{id: int, name: string}> clients ayant quelque chose à expédier */
    public function clientsWithShippable(): array
    {
        return $this->db->fetchAll(
            "SELECT DISTINCT c.id, c.name FROM clients c
               JOIN client_orders co ON co.client_id = c.id AND co.status NOT IN ('closed', 'cancelled')
               JOIN supplier_orders so ON so.client_order_id = co.id AND so.status IN ('sent', 'confirmed')
               JOIN supplier_order_lines sl ON sl.supplier_order_id = so.id
              WHERE sl.quantity_milli > (SELECT COALESCE(SUM(l.quantity_milli), 0) FROM shipment_lines l JOIN shipments sh ON sh.id = l.shipment_id
                                          WHERE l.supplier_order_line_id = sl.id AND sh.status <> 'cancelled')
              ORDER BY c.name"
        );
    }

    /** @return list<array<string, mixed>> */
    public function events(int $shipmentId): array
    {
        return $this->db->fetchAll(
            'SELECT e.*, u.name AS user_name FROM shipment_events e LEFT JOIN users u ON u.id = e.user_id
              WHERE e.shipment_id = :id ORDER BY e.id',
            ['id' => $shipmentId],
        );
    }

    /** @return array<string, mixed>|null */
    public function pod(int $shipmentId): ?array
    {
        return $this->db->fetchOne(
            'SELECT p.*, f.original_name, f.mime, f.size_bytes, f.sha256, u.name AS created_by_name
               FROM pods p JOIN files f ON f.id = p.file_id LEFT JOIN users u ON u.id = p.created_by
              WHERE p.shipment_id = :id',
            ['id' => $shipmentId],
        );
    }

    /** PO fournisseurs dont la date de mise à disposition est passée et qui ont encore des quantités non expédiées. */
    public function pickupOverdueCount(): int
    {
        return (int) $this->db->fetchValue(
            "SELECT COUNT(DISTINCT so.id) FROM supplier_orders so
               JOIN supplier_order_lines sl ON sl.supplier_order_id = so.id
              WHERE so.status IN ('sent', 'confirmed') AND so.expected_ready_on < CURDATE()
                AND sl.quantity_milli > (SELECT COALESCE(SUM(l.quantity_milli), 0) FROM shipment_lines l JOIN shipments sh ON sh.id = l.shipment_id
                                          WHERE l.supplier_order_line_id = sl.id AND sh.status <> 'cancelled')"
        );
    }

    /** @return array{open: int, late: int, at_border: int, delivered_month: int} */
    public function kpis(): array
    {
        $row = $this->db->fetchOne(
            "SELECT COALESCE(SUM(status NOT IN ('delivered', 'cancelled')), 0) AS open,
                    COALESCE(SUM(status NOT IN ('delivered', 'cancelled') AND eta < CURDATE()), 0) AS late,
                    COALESCE(SUM(status = 'at_border'), 0) AS at_border,
                    COALESCE(SUM(status = 'delivered' AND delivered_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')), 0) AS delivered_month,
                    COALESCE(SUM(" . self::DOCS_MISSING . "), 0) AS docs_missing,
                    COALESCE(SUM(sh.status = 'delivered' AND NOT EXISTS (SELECT 1 FROM pods p WHERE p.shipment_id = sh.id)), 0) AS pod_pending
               FROM shipments sh"
        ) ?? [];
        return array_map('intval', $row);
    }

    /** @return list<array<string, mixed>> documents de transport, avec le fichier et l'auteur */
    public function files(int $shipmentId): array
    {
        return $this->db->fetchAll(
            'SELECT sf.id, sf.created_at, sf.doc_type_id, sf.reference, sf.document_date, f.id AS file_id, f.original_name, f.mime, f.size_bytes, u.name AS created_by_name
               FROM shipment_files sf LEFT JOIN files f ON f.id = sf.file_id LEFT JOIN users u ON u.id = sf.created_by
              WHERE sf.shipment_id = :id ORDER BY sf.id',
            ['id' => $shipmentId],
        );
    }

    /** @return list<int> types de document exigés pour l'expédition */
    public function requiredTypeIds(int $shipmentId): array
    {
        return array_map('intval', array_column(
            $this->db->fetchAll('SELECT doc_type_id FROM shipment_required_documents WHERE shipment_id = :id', ['id' => $shipmentId]),
            'doc_type_id',
        ));
    }
}
