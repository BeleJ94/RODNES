<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Shared\Fx;

final class SupplierInvoiceRepository extends Repository
{
    private const SELECT = '
        SELECT si.*, s.name AS supplier_name, s.code AS supplier_code,
               so.reference AS order_reference, so.client_order_id, co.reference AS client_order_reference,
               f.original_name AS file_name, f.mime AS file_mime, ua.name AS approved_by_name,
               COALESCE((SELECT SUM(a.amount_minor) FROM supplier_payment_allocations a WHERE a.invoice_id = si.id), 0) AS paid_minor,
               (SELECT GROUP_CONCAT(DISTINCT sh.reference ORDER BY sh.reference SEPARATOR \', \') FROM supplier_invoice_cost_lines cl
                  JOIN shipments sh ON sh.id = cl.shipment_id WHERE cl.invoice_id = si.id) AS shipment_references
          FROM supplier_invoices si
          JOIN suppliers s ON s.id = si.supplier_id
          LEFT JOIN supplier_orders so ON so.id = si.supplier_order_id
          LEFT JOIN client_orders co ON co.id = so.client_order_id
          LEFT JOIN files f ON f.id = si.file_id
          LEFT JOIN users ua ON ua.id = si.approved_by';

    /** Reçu (POD) par ligne de PO fournisseur : expéditions livrées uniquement. */
    public const RECEIVED = "(SELECT COALESCE(SUM(shl.received_milli), 0) FROM shipment_lines shl
           JOIN shipments sh ON sh.id = shl.shipment_id
          WHERE shl.supplier_order_line_id = l.id AND sh.status = 'delivered')";

    /**
     * @param array{status?: string, supplier_id?: int|null, kind?: string} $filters status : un statut, « open » ou « overdue »
     * @return list<array<string, mixed>>
     */
    public function list(array $filters = []): array
    {
        $where = ['1 = 1'];
        $params = [];
        $status = $filters['status'] ?? '';
        if ($status === 'open' || $status === 'overdue') {
            $where[] = "si.status IN ('on_hold', 'approved', 'partially_paid')" . ($status === 'overdue' ? ' AND si.due_date < CURDATE()' : '');
        } elseif ($status !== '') {
            $where[] = 'si.status = :status';
            $params['status'] = $status;
        }
        if (in_array($filters['kind'] ?? '', ['goods', 'logistics'], true)) {
            $where[] = 'si.kind = :kind';
            $params['kind'] = $filters['kind'];
        }
        if (!empty($filters['supplier_id'])) {
            $where[] = 'si.supplier_id = :supplier';
            $params['supplier'] = (int) $filters['supplier_id'];
        }
        $rows = $this->db->fetchAll(self::SELECT . ' WHERE ' . implode(' AND ', $where) . " ORDER BY si.status = 'on_hold' DESC, si.due_date, si.id DESC", $params);
        return array_map(self::hydrate(...), $rows);
    }

    /** @return array<string, mixed> */
    public function get(int $id): array
    {
        return self::hydrate(self::orFail($this->db->fetchOne(self::SELECT . ' WHERE si.id = :id', ['id' => $id])));
    }

    /** @return list<array<string, mixed>> */
    public function forSupplierOrder(int $supplierOrderId): array
    {
        return array_map(self::hydrate(...), $this->db->fetchAll(self::SELECT . ' WHERE si.supplier_order_id = :id ORDER BY si.id', ['id' => $supplierOrderId]));
    }

    /** @return list<array<string, mixed>> */
    public function lines(int $invoiceId): array
    {
        return $this->db->fetchAll(
            'SELECT il.*, l.line_no AS po_line_no, l.quantity_milli AS ordered_milli
               FROM supplier_invoice_lines il JOIN supplier_order_lines l ON l.id = il.supplier_order_line_id
              WHERE il.invoice_id = :id ORDER BY il.line_no',
            ['id' => $invoiceId],
        );
    }

    /**
     * Lignes d'un PO fournisseur pour le rapprochement : commandé, reçu (POD), déjà facturé.
     * Avec $beforeInvoiceId : seules les factures saisies avant celle-ci comptent comme « déjà facturé » (nouveau rapprochement).
     *
     * @return list<array<string, mixed>>
     */
    public function matchLines(int $supplierOrderId, ?int $beforeInvoiceId = null): array
    {
        return $this->db->fetchAll(
            'SELECT l.id, l.line_no, l.description, l.unit, l.quantity_milli AS ordered_milli, l.unit_price_minor AS po_price_minor,
                    ' . self::RECEIVED . " AS received_milli,
                    (SELECT COALESCE(SUM(il.quantity_milli), 0) FROM supplier_invoice_lines il JOIN supplier_invoices si ON si.id = il.invoice_id
                      WHERE il.supplier_order_line_id = l.id AND si.status <> 'cancelled' AND si.id < :before) AS invoiced_milli
               FROM supplier_order_lines l WHERE l.supplier_order_id = :id ORDER BY l.line_no",
            ['id' => $supplierOrderId, 'before' => $beforeInvoiceId ?? PHP_INT_MAX],
        );
    }

    /**
     * Factures bon à payer d'un fournisseur dans une devise, plus anciennes échéances d'abord.
     *
     * @return list<array<string, mixed>>
     */
    public function payableFor(int $supplierId, string $currency): array
    {
        return array_values(array_filter(
            array_map(self::hydrate(...), $this->db->fetchAll(
                self::SELECT . " WHERE si.supplier_id = :s AND si.currency_code = :c AND si.status IN ('approved', 'partially_paid') ORDER BY si.due_date, si.id",
                ['s' => $supplierId, 'c' => $currency],
            )),
            static fn (array $i): bool => $i['balance'] > 0,
        ));
    }

    /**
     * Devises dans lesquelles un fournisseur a des factures bon à payer.
     *
     * @return list<string>
     */
    public function payableCurrencies(int $supplierId): array
    {
        return array_map('strval', array_column($this->db->fetchAll(
            "SELECT DISTINCT currency_code FROM supplier_invoices WHERE supplier_id = :s AND status IN ('approved', 'partially_paid') ORDER BY currency_code",
            ['s' => $supplierId],
        ), 'currency_code'));
    }

    /** @return list<array<string, mixed>> lignes de frais d'une facture logistique */
    public function costLines(int $invoiceId): array
    {
        return $this->db->fetchAll(
            'SELECT cl.*, sh.reference AS shipment_reference, sh.status AS shipment_status, c.name AS client_name
               FROM supplier_invoice_cost_lines cl JOIN shipments sh ON sh.id = cl.shipment_id JOIN clients c ON c.id = sh.client_id
              WHERE cl.invoice_id = :id ORDER BY cl.line_no',
            ['id' => $invoiceId],
        );
    }

    /** @return list<array<string, mixed>> frais logistiques (factures non annulées) d'une expédition */
    public function costLinesForShipment(int $shipmentId): array
    {
        return $this->db->fetchAll(
            "SELECT cl.*, si.id AS invoice_id, si.reference, si.supplier_invoice_number, si.status, si.currency_code, si.fx_units_per_usd_micro,
                    s.name AS supplier_name
               FROM supplier_invoice_cost_lines cl JOIN supplier_invoices si ON si.id = cl.invoice_id AND si.status <> 'cancelled'
               JOIN suppliers s ON s.id = si.supplier_id
              WHERE cl.shipment_id = :id ORDER BY si.invoice_date, cl.line_no",
            ['id' => $shipmentId],
        );
    }

    public function numberExists(int $supplierId, string $number): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT 1 FROM supplier_invoices WHERE supplier_id = :s AND supplier_invoice_number = :n',
            ['s' => $supplierId, 'n' => $number],
        );
    }

    /** @return array{on_hold: int, open_usd: int, overdue_usd: int, overdue_count: int, due_soon_usd: int} */
    public function kpis(int $soonDays = 7): array
    {
        $out = ['on_hold' => 0, 'open_usd' => 0, 'overdue_usd' => 0, 'overdue_count' => 0, 'due_soon_usd' => 0];
        $today = date('Y-m-d');
        $soon = date('Y-m-d', strtotime("+{$soonDays} days"));
        foreach ($this->list(['status' => 'open']) as $row) {
            $usd = (int) $row['balance_usd'];
            $out['open_usd'] += $usd;
            $out['on_hold'] += $row['status'] === 'on_hold' ? 1 : 0;
            if ($row['due_date'] < $today) {
                $out['overdue_usd'] += $usd;
                $out['overdue_count']++;
            } elseif ($row['due_date'] <= $soon) {
                $out['due_soon_usd'] += $usd;
            }
        }
        return $out;
    }

    /**
     * Montants en devise ; balance = total − payé ; contre-valeurs USD au taux de la facture.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function hydrate(array $row): array
    {
        $paid = (int) ($row['paid_minor'] ?? 0);
        $total = (int) $row['total_minor'];
        $fx = (int) $row['fx_units_per_usd_micro'];
        $balance = $row['status'] === 'cancelled' ? 0 : max(0, $total - $paid);
        return $row + [
            'paid' => $paid,
            'balance' => $balance,
            'total_usd' => Fx::toUsd($total, $fx),
            'balance_usd' => Fx::toUsd($balance, $fx),
            'variance_list' => $row['variances'] ? (json_decode((string) $row['variances'], true) ?: []) : [],
        ];
    }
}
