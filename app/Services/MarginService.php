<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\Finance\LandedCost;
use App\Domain\Finance\RealCost;
use App\Domain\Finance\RealMargin;
use App\Domain\Shared\Fx;
use App\Domain\Shared\Money;
use App\Repositories\SupplierPaymentRepository;

/** Marge réelle par PO client : facturé client (HT) face au coût réel des achats et des frais logistiques (RealMargin). */
final class MarginService
{
    public function __construct(private Database $db, private SupplierPaymentRepository $payments)
    {
    }

    /** @return array{lines: list<array<string, mixed>>, total: array<string, mixed>} */
    public function forClientOrder(int $orderId): array
    {
        return $this->compute([$orderId])[$orderId] ?? ['lines' => [], 'total' => RealMargin::total([])];
    }

    /**
     * Rapport : PO clients ayant au moins une facture émise.
     *
     * @return list<array<string, mixed>>
     */
    public function report(?string $from = null, ?string $to = null): array
    {
        $orders = $this->db->fetchAll(
            "SELECT o.id, o.reference, o.client_po_number, c.name AS client_name, MIN(i.issue_date) AS first_invoice, MAX(i.issue_date) AS last_invoice
               FROM client_orders o JOIN clients c ON c.id = o.client_id
               JOIN client_invoices i ON i.client_order_id = o.id AND i.status NOT IN ('draft', 'cancelled')
              GROUP BY o.id, o.reference, o.client_po_number, c.name
             HAVING (:from IS NULL OR MAX(i.issue_date) >= :from2) AND (:to IS NULL OR MIN(i.issue_date) <= :to2)
              ORDER BY last_invoice DESC, o.id DESC",
            ['from' => $from, 'from2' => $from, 'to' => $to, 'to2' => $to],
        );
        $margins = $this->compute(array_map(static fn (array $o): int => (int) $o['id'], $orders));
        return array_map(static fn (array $o): array => $o + $margins[(int) $o['id']]['total'], $orders);
    }

    /**
     * @param list<int> $orderIds
     * @return array<int, array{lines: list<array<string, mixed>>, total: array<string, mixed>}>
     */
    public function compute(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $orderIds));
        $lines = $this->db->fetchAll(
            "SELECT l.id, l.client_order_id, l.line_no, l.description, l.est_unit_cost_minor + l.est_extra_unit_minor AS est_unit_usd,
                    COALESCE(SUM(il.quantity_milli), 0) AS invoiced_milli, COALESCE(SUM(ROUND(il.quantity_milli * il.unit_price_minor / 1000)), 0)
                    - COALESCE((SELECT SUM(ROUND(cl.quantity_milli * cl.unit_price_minor / 1000)) FROM client_credit_note_lines cl
                         WHERE cl.client_order_line_id = l.id), 0) AS revenue
               FROM client_order_lines l
               LEFT JOIN client_invoice_lines il ON il.client_order_line_id = l.id
                    AND il.invoice_id IN (SELECT id FROM client_invoices WHERE status NOT IN ('draft', 'cancelled'))
              WHERE l.client_order_id IN ({$in})
              GROUP BY l.id, l.client_order_id, l.line_no, l.description, l.est_unit_cost_minor, l.est_extra_unit_minor
              ORDER BY l.client_order_id, l.line_no",
        );
        // Factures fournisseurs (non annulées) rattachées à ces PO clients, ligne par ligne.
        $supplierLines = $this->db->fetchAll(
            "SELECT sol.client_order_line_id, il.quantity_milli, il.unit_price_minor, si.id AS invoice_id, si.subtotal_minor, si.total_minor,
                    si.fx_units_per_usd_micro AS invoice_fx, so.fx_units_per_usd_micro AS po_fx
               FROM supplier_invoice_lines il
               JOIN supplier_invoices si ON si.id = il.invoice_id AND si.status <> 'cancelled'
               JOIN supplier_order_lines sol ON sol.id = il.supplier_order_line_id
               JOIN supplier_orders so ON so.id = sol.supplier_order_id
              WHERE so.client_order_id IN ({$in})",
        );
        // Frais logistiques des expéditions transportant ces PO (toutes les lignes de l'expédition partagent ses frais).
        $shipmentLines = $this->db->fetchAll(
            "SELECT shl.shipment_id, sol.client_order_line_id, COALESCE(shl.received_milli, shl.quantity_milli) AS qty_milli,
                    sol.unit_price_minor, so.fx_units_per_usd_micro
               FROM shipment_lines shl
               JOIN shipments sh ON sh.id = shl.shipment_id AND sh.status <> 'cancelled'
               JOIN supplier_order_lines sol ON sol.id = shl.supplier_order_line_id
               JOIN supplier_orders so ON so.id = sol.supplier_order_id
              WHERE shl.shipment_id IN (SELECT DISTINCT shl2.shipment_id FROM shipment_lines shl2
                      JOIN supplier_order_lines sol2 ON sol2.id = shl2.supplier_order_line_id
                      JOIN supplier_orders so2 ON so2.id = sol2.supplier_order_id WHERE so2.client_order_id IN ({$in}))",
        );
        $costLines = $shipmentLines === [] ? [] : $this->db->fetchAll(
            'SELECT cl.shipment_id, cl.amount_minor, si.id AS invoice_id, si.subtotal_minor, si.total_minor, si.fx_units_per_usd_micro AS invoice_fx
               FROM supplier_invoice_cost_lines cl JOIN supplier_invoices si ON si.id = cl.invoice_id AND si.status <> \'cancelled\'
              WHERE cl.shipment_id IN (' . implode(',', array_unique(array_map('intval', array_column($shipmentLines, 'shipment_id')))) . ')',
        );
        $allocations = $this->payments->allocationsByInvoice(array_values(array_unique(array_merge(
            array_map('intval', array_column($supplierLines, 'invoice_id')),
            array_map('intval', array_column($costLines, 'invoice_id')),
        ))));
        $invoiceUsd = [];
        $supplier = [];
        foreach ($supplierLines as $s) {
            $invoiceId = (int) $s['invoice_id'];
            $invoiceUsd[$invoiceId] ??= RealCost::invoiceUsd((int) $s['subtotal_minor'], (int) $s['total_minor'], (int) $s['invoice_fx'], $allocations[$invoiceId] ?? []);
            $amount = Money::lineAmount((int) $s['quantity_milli'], (int) $s['unit_price_minor']);
            $key = (int) $s['client_order_line_id'];
            $supplier[$key] ??= ['milli' => 0, 'usd' => 0, 'at_po' => 0];
            $supplier[$key]['milli'] += (int) $s['quantity_milli'];
            $supplier[$key]['usd'] += RealCost::shareUsd($amount, (int) $s['subtotal_minor'], $invoiceUsd[$invoiceId]);
            $supplier[$key]['at_po'] += Fx::toUsd($amount, (int) $s['po_fx']);
        }
        $landedByShipment = [];
        foreach ($costLines as $cl) {
            $invoiceId = (int) $cl['invoice_id'];
            $invoiceUsd[$invoiceId] ??= RealCost::invoiceUsd((int) $cl['subtotal_minor'], (int) $cl['total_minor'], (int) $cl['invoice_fx'], $allocations[$invoiceId] ?? []);
            $landedByShipment[(int) $cl['shipment_id']] = ($landedByShipment[(int) $cl['shipment_id']] ?? 0)
                + RealCost::shareUsd((int) $cl['amount_minor'], (int) $cl['subtotal_minor'], $invoiceUsd[$invoiceId]);
        }
        $landed = [];
        foreach ($landedByShipment as $shipmentId => $usd) {
            $weights = [];
            $rows = [];
            foreach ($shipmentLines as $k => $sl) {
                if ((int) $sl['shipment_id'] === $shipmentId) {
                    $weights[$k] = Money::lineAmount((int) $sl['qty_milli'], Fx::toUsd((int) $sl['unit_price_minor'], (int) $sl['fx_units_per_usd_micro']));
                    $rows[$k] = $sl;
                }
            }
            foreach (LandedCost::allocate($usd, $weights) as $k => $part) {
                $key = (int) $rows[$k]['client_order_line_id'];
                $landed[$key] ??= ['usd' => 0, 'milli' => 0];
                $landed[$key]['usd'] += $part;
                $landed[$key]['milli'] += (int) $rows[$k]['qty_milli'];
            }
        }
        // Coût engagé (PO non annulés) : prix unitaire USD moyen au taux du PO.
        $committed = [];
        foreach ($this->db->fetchAll(
            "SELECT sol.client_order_line_id, sol.quantity_milli, sol.unit_price_minor, so.fx_units_per_usd_micro
               FROM supplier_order_lines sol JOIN supplier_orders so ON so.id = sol.supplier_order_id AND so.status <> 'cancelled'
              WHERE so.client_order_id IN ({$in})",
        ) as $c) {
            $key = (int) $c['client_order_line_id'];
            $committed[$key] ??= ['milli' => 0, 'usd' => 0];
            $committed[$key]['milli'] += (int) $c['quantity_milli'];
            $committed[$key]['usd'] += Money::lineAmount((int) $c['quantity_milli'], Fx::toUsd((int) $c['unit_price_minor'], (int) $c['fx_units_per_usd_micro']));
        }

        $out = [];
        foreach ($lines as $l) {
            $id = (int) $l['id'];
            $s = $supplier[$id] ?? ['milli' => 0, 'usd' => 0, 'at_po' => 0];
            $c = $committed[$id] ?? null;
            $calc = RealMargin::line([
                'invoiced_milli' => (int) $l['invoiced_milli'], 'revenue' => (int) $l['revenue'],
                'supplier_milli' => $s['milli'], 'supplier_usd' => $s['usd'], 'supplier_usd_at_po' => $s['at_po'],
                'po_unit_usd' => $c !== null && $c['milli'] > 0 ? Money::divRound($c['usd'] * 1000, $c['milli']) : null,
                'est_unit_usd' => (int) $l['est_unit_usd'],
                'landed_usd' => $landed[$id]['usd'] ?? 0, 'landed_milli' => $landed[$id]['milli'] ?? 0,
            ]);
            $out[(int) $l['client_order_id']]['lines'][] = $l + $calc + ['supplier_invoiced_milli' => $s['milli']];
        }
        foreach ($out as $orderId => $data) {
            $out[$orderId]['total'] = RealMargin::total(array_values(array_filter($data['lines'], static fn (array $l): bool => (int) $l['invoiced_milli'] > 0)));
        }
        return $out;
    }
}
