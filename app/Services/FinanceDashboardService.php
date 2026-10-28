<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\Finance\CashForecast;
use App\Domain\Sales\Pricing;
use App\Domain\Shared\Fx;
use App\Domain\Shared\Money;
use App\Repositories\InvoiceRepository;
use App\Repositories\SupplierInvoiceRepository;

/**
 * Tableau de bord finance (USD) : trésorerie prévisionnelle, chiffre d'affaires et marge réelle par mois,
 * meilleurs clients et fournisseurs, délais d'encaissement et de paiement, taux de transformation des devis.
 */
final class FinanceDashboardService
{
    public const WEEKS = 8;
    public const MONTHS = 12;

    public function __construct(
        private Database $db,
        private InvoiceRepository $invoices,
        private SupplierInvoiceRepository $supplierInvoices,
        private MarginService $margins,
    ) {
    }

    /** @return array<string, mixed> */
    public function forecast(int $opening, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $in = [];
        foreach ($this->invoices->list(['status' => 'open']) as $i) {
            $in[] = ['date' => (string) $i['due_date'], 'amount' => (int) $i['balance']];
        }
        $out = [];
        $onHold = 0;
        foreach ($this->supplierInvoices->list(['status' => 'open']) as $s) {
            if ($s['status'] === 'on_hold') {
                $onHold += (int) $s['balance_usd'];
                continue;
            }
            $out[] = ['date' => (string) $s['due_date'], 'amount' => (int) $s['balance_usd']];
        }
        return CashForecast::build($today, self::WEEKS, $opening, $in, $out) + ['on_hold' => $onHold, 'opening' => $opening];
    }

    /**
     * Chiffre d'affaires HT (net des avoirs) et marge réelle par mois d'émission, sur 12 mois glissants,
     * plus le cumul par client sur la même période. Le coût d'une ligne suit sa quantité facturée dans le mois.
     *
     * @return array{months: list<array{month: string, revenue: int, margin: int}>, clients: list<array<string, mixed>>}
     */
    public function monthly(?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $first = date('Y-m-01', strtotime(substr($today, 0, 7) . '-01 -' . (self::MONTHS - 1) . ' months'));
        $months = [];
        for ($m = 0; $m < self::MONTHS; $m++) {
            $key = date('Y-m', strtotime($first . " +{$m} months"));
            $months[$key] = ['month' => $key, 'revenue' => 0, 'margin' => 0];
        }
        $invoiceLines = $this->db->fetchAll(
            "SELECT il.client_order_line_id, il.quantity_milli, il.unit_price_minor, i.issue_date, i.client_id, c.name AS client_name, ol.client_order_id
               FROM client_invoice_lines il
               JOIN client_invoices i ON i.id = il.invoice_id AND i.status NOT IN ('draft', 'cancelled')
               JOIN client_order_lines ol ON ol.id = il.client_order_line_id
               JOIN clients c ON c.id = i.client_id
              WHERE i.issue_date >= :first",
            ['first' => $first],
        );
        $creditLines = $this->db->fetchAll(
            'SELECT cl.quantity_milli, cl.unit_price_minor, n.issue_date, n.client_id, c.name AS client_name
               FROM client_credit_note_lines cl JOIN client_credit_notes n ON n.id = cl.credit_note_id JOIN clients c ON c.id = n.client_id
              WHERE n.issue_date >= :first',
            ['first' => $first],
        );
        // Coût réel unitaire par ligne de PO client (marge réelle : achats, frais logistiques, change).
        $unitCost = [];
        foreach ($this->margins->compute(array_values(array_unique(array_map('intval', array_column($invoiceLines, 'client_order_id'))))) as $order) {
            foreach ($order['lines'] as $l) {
                if ((int) $l['invoiced_milli'] > 0) {
                    $unitCost[(int) $l['id']] = ['cost' => (int) $l['cost'], 'milli' => (int) $l['invoiced_milli']];
                }
            }
        }
        $clients = [];
        $add = static function (array $row, int $revenue, int $margin) use (&$months, &$clients): void {
            $key = substr((string) $row['issue_date'], 0, 7);
            if (isset($months[$key])) {
                $months[$key]['revenue'] += $revenue;
                $months[$key]['margin'] += $margin;
            }
            $id = (int) $row['client_id'];
            $clients[$id] ??= ['id' => $id, 'name' => $row['client_name'], 'revenue' => 0, 'margin' => 0];
            $clients[$id]['revenue'] += $revenue;
            $clients[$id]['margin'] += $margin;
        };
        foreach ($invoiceLines as $l) {
            $revenue = Money::lineAmount((int) $l['quantity_milli'], (int) $l['unit_price_minor']);
            $u = $unitCost[(int) $l['client_order_line_id']] ?? null;
            $cost = $u !== null ? Money::divRound($u['cost'] * (int) $l['quantity_milli'], $u['milli']) : 0;
            $add($l, $revenue, $revenue - $cost);
        }
        foreach ($creditLines as $l) {
            $credit = Money::lineAmount((int) $l['quantity_milli'], (int) $l['unit_price_minor']);
            $add($l, -$credit, -$credit);
        }
        $clients = array_values($clients);
        usort($clients, static fn (array $a, array $b): int => $b['revenue'] <=> $a['revenue']);
        foreach ($clients as $i => $c) {
            $clients[$i]['margin_bp'] = Pricing::marginBp($c['revenue'] - $c['margin'], $c['revenue']);
        }
        return ['months' => array_values($months), 'clients' => array_slice($clients, 0, 5)];
    }

    /**
     * Achats HT en USD par fournisseur sur 12 mois (marchandises et frais logistiques, factures non annulées).
     *
     * @return list<array{id: int, name: string, goods: int, logistics: int, total: int}>
     */
    public function topSuppliers(?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $from = date('Y-m-d', strtotime($today . ' -12 months'));
        $out = [];
        foreach ($this->db->fetchAll(
            "SELECT si.supplier_id, s.name, si.kind, si.subtotal_minor, si.fx_units_per_usd_micro FROM supplier_invoices si
               JOIN suppliers s ON s.id = si.supplier_id WHERE si.status <> 'cancelled' AND si.invoice_date >= :from",
            ['from' => $from],
        ) as $r) {
            $id = (int) $r['supplier_id'];
            $out[$id] ??= ['id' => $id, 'name' => (string) $r['name'], 'goods' => 0, 'logistics' => 0, 'total' => 0];
            $usd = Fx::toUsd((int) $r['subtotal_minor'], (int) $r['fx_units_per_usd_micro']);
            $out[$id][$r['kind'] === 'logistics' ? 'logistics' : 'goods'] += $usd;
            $out[$id]['total'] += $usd;
        }
        $out = array_values($out);
        usort($out, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);
        return array_slice($out, 0, 5);
    }

    /** @return array{dso: ?int, dpo: ?int, receivables: int, payables: int, hit_rate_bp: ?int, quotes_won: int, quotes_decided: int} */
    public function indicators(?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $from90 = date('Y-m-d', strtotime($today . ' -90 days'));
        $receivables = $this->invoices->kpis()['outstanding'];
        $sales90 = (int) $this->db->fetchValue(
            "SELECT COALESCE(SUM(total_minor), 0) FROM client_invoices WHERE status NOT IN ('draft', 'cancelled') AND issue_date >= :d",
            ['d' => $from90],
        );
        $payables = $this->supplierInvoices->kpis()['open_usd'];
        $purchases90 = 0;
        foreach ($this->db->fetchAll(
            "SELECT total_minor, fx_units_per_usd_micro FROM supplier_invoices WHERE status <> 'cancelled' AND invoice_date >= :d",
            ['d' => $from90],
        ) as $r) {
            $purchases90 += Fx::toUsd((int) $r['total_minor'], (int) $r['fx_units_per_usd_micro']);
        }
        // Devis tranchés (acceptés ou refusés) sur 12 mois ; une version remplacée ne compte pas.
        $q = $this->db->fetchOne(
            "SELECT COALESCE(SUM(status = 'accepted'), 0) AS won, COALESCE(SUM(status IN ('accepted', 'rejected')), 0) AS decided
               FROM quotations WHERE sent_at >= :d",
            ['d' => date('Y-m-d', strtotime($today . ' -12 months'))],
        ) ?? ['won' => 0, 'decided' => 0];
        return [
            'dso' => CashForecast::days($receivables, $sales90, 90),
            'dpo' => CashForecast::days($payables, $purchases90, 90),
            'receivables' => $receivables,
            'payables' => $payables,
            'hit_rate_bp' => (int) $q['decided'] > 0 ? intdiv((int) $q['won'] * 10_000, (int) $q['decided']) : null,
            'quotes_won' => (int) $q['won'],
            'quotes_decided' => (int) $q['decided'],
        ];
    }
}
