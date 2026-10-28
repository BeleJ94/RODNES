<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Invoicing\Receivables;
use App\Domain\Logistics\ShipmentStatus;
use App\Domain\Orders\ClientOrderStatus;
use App\Domain\Orders\SupplierOrderStatus;
use App\Domain\Purchasing\SupplierInvoiceStatus;
use App\Domain\Sales\Pricing;
use App\Domain\Sales\QuotationStatus;
use App\Domain\Sales\RfqStatus;
use App\Domain\Shared\Fx;
use App\Repositories\ClientOrderRepository;
use App\Repositories\InvoiceRepository;
use App\Repositories\QuotationRepository;
use App\Repositories\RfqRepository;
use App\Repositories\ShipmentRepository;
use App\Repositories\SupplierInvoiceRepository;
use App\Repositories\SupplierOrderRepository;

/**
 * Détails du tableau de bord : chaque indicateur ou notification ouvre une de ces listes dans une fenêtre
 * (tableau compact filtrable, exportable). Une liste = permission, titre, icône, lignes, colonnes typées, lien.
 * Colonne : [libellé, type (text, money, int, percent, date, datetime, badge), fn(row) → valeur brute
 * (badge : [Tone, libellé] ; money : centimes, devise en 3e élément facultatif)].
 */
final class DashboardPanels
{
    public function __construct(
        private RfqRepository $rfqs,
        private QuotationRepository $quotations,
        private ClientOrderRepository $clientOrders,
        private SupplierOrderRepository $supplierOrders,
        private ShipmentRepository $shipments,
        private InvoiceRepository $invoices,
        private SupplierInvoiceRepository $supplierInvoices,
        private FollowupService $followups,
        private SalesTrackingService $tracking,
    ) {
    }

    /** Clés disponibles et permission requise. */
    public const PERMS = [
        'rfqs.open' => 'rfqs.view', 'rfqs.late' => 'rfqs.view', 'rfqs.due_soon' => 'rfqs.view',
        'quotations.sent' => 'quotations.view', 'quotations.pending_approval' => 'quotations.view', 'quotations.expiring' => 'quotations.view',
        'quotations.to_close' => 'quotations.view', 'quotations.won' => 'quotations.view', 'tracking.awaiting_po' => 'rfqs.view',
        'client_orders.open' => 'client_orders.view', 'client_orders.to_order' => 'client_orders.view', 'client_orders.to_invoice' => 'client_orders.view',
        'supplier_orders.drafts' => 'supplier_orders.view', 'supplier_orders.open' => 'supplier_orders.view',
        'shipments.open' => 'shipments.view', 'shipments.late' => 'shipments.view',
        'invoices.open' => 'invoices.view', 'invoices.overdue' => 'invoices.view', 'invoices.drafts' => 'invoices.view', 'invoices.to_invoice' => 'client_orders.view',
        'supplier_invoices.open' => 'supplier_invoices.view', 'supplier_invoices.on_hold' => 'supplier_invoices.view', 'supplier_invoices.overdue' => 'supplier_invoices.view',
        'followups.due' => 'dashboard.view',
        // Clic sur un graphique : le paramètre p précise la barre (étape, statut, tranche, série:mois).
        'chart.activity' => 'rfqs.view', 'chart.pipeline' => 'rfqs.view', 'chart.shipments' => 'shipments.view', 'chart.aging' => 'invoices.view',
    ];

    /**
     * @return array{title: string, icon: string, page: string, columns: list<array{0: string, 1: string, 2: \Closure}>, rows: list<array<string, mixed>>, link: \Closure}|null
     */
    public function get(string $key, string $param = ''): ?array
    {
        $today = date('Y-m-d');
        $now = date('Y-m-d H:i:s');
        $col = static fn (string $label, string $type, \Closure $value): array => [$label, $type, $value];

        $rfqCols = [
            $col(t('rfqs.col.reference'), 'text', static fn (array $r) => $r['reference']),
            $col(t('rfqs.col.client'), 'text', static fn (array $r) => $r['client_name']),
            $col(t('rfqs.col.status'), 'badge', static fn (array $r) => [RfqStatus::from($r['status'])->tone(), t(RfqStatus::from($r['status'])->labelKey())]),
            $col(t('rfqs.col.received'), 'date', static fn (array $r) => $r['received_on']),
            $col(t('rfqs.col.due'), 'datetime', static fn (array $r) => $r['due_at']),
            $col(t('rfqs.col.owner'), 'text', static fn (array $r) => $r['owner_name']),
        ];
        $quoteCols = [
            $col(t('quotations.col.reference'), 'text', static fn (array $r) => $r['reference'] . ' v' . $r['version']),
            $col(t('rfqs.col.client'), 'text', static fn (array $r) => $r['client_name']),
            $col(t('common.status'), 'badge', static fn (array $r) => [QuotationStatus::from($r['status'])->tone(), t(QuotationStatus::from($r['status'])->labelKey())]),
            $col(t('quotations.col.amount'), 'money', static fn (array $r) => (int) $r['subtotal']),
            $col(t('quotations.col.margin'), 'percent', static fn (array $r) => Pricing::marginBp((int) $r['cost'], (int) $r['subtotal'])),
            $col(t('detail.sent_at'), 'date', static fn (array $r) => $r['sent_at']),
            $col(t('quotations.col.valid_until'), 'date', static fn (array $r) => $r['valid_until']),
        ];
        $orderCols = [
            $col(t('client_orders.col.reference'), 'text', static fn (array $r) => $r['reference']),
            $col(t('detail.client_po'), 'text', static fn (array $r) => $r['client_po_number']),
            $col(t('rfqs.col.client'), 'text', static fn (array $r) => $r['client_name']),
            $col(t('common.status'), 'badge', static fn (array $r) => [ClientOrderStatus::from($r['status'])->tone(), t(ClientOrderStatus::from($r['status'])->labelKey())]),
            $col(t('client_orders.col.amount'), 'money', static fn (array $r) => (int) $r['subtotal']),
            $col(t('client_orders.col.coverage'), 'percent', static fn (array $r) => (int) $r['ordered_milli'] > 0 ? intdiv((int) $r['covered_milli'] * 10000, (int) $r['ordered_milli']) : 0),
            $col(t('client_orders.col.received'), 'date', static fn (array $r) => $r['received_on']),
            $col(t('detail.required_by'), 'date', static fn (array $r) => $r['required_by']),
        ];
        $poCols = [
            $col(t('supplier_orders.col.reference'), 'text', static fn (array $r) => $r['reference']),
            $col(t('sourcing.supplier'), 'text', static fn (array $r) => $r['supplier_name']),
            $col(t('rfqs.col.client'), 'text', static fn (array $r) => $r['client_name']),
            $col(t('common.status'), 'badge', static fn (array $r) => [SupplierOrderStatus::from($r['status'])->tone(), t(SupplierOrderStatus::from($r['status'])->labelKey())]),
            $col(t('detail.amount_currency'), 'money', static fn (array $r) => [(int) $r['total'], $r['currency_code']]),
            $col(t('supplier_orders.col.amount_usd'), 'money', static fn (array $r) => Fx::toUsd((int) $r['total'], (int) $r['fx_units_per_usd_micro'])),
            $col(t('supplier_orders.col.ready'), 'date', static fn (array $r) => $r['expected_ready_on']),
        ];
        $shipCols = [
            $col(t('shipments.col.reference'), 'text', static fn (array $r) => $r['reference']),
            $col(t('rfqs.col.client'), 'text', static fn (array $r) => $r['client_name']),
            $col(t('common.status'), 'badge', static fn (array $r) => [ShipmentStatus::from($r['status'])->tone(), t(ShipmentStatus::from($r['status'])->labelKey())]),
            $col(t('shipments.col.carrier'), 'text', static fn (array $r) => $r['carrier_name'] ?: t('shipments.carrier_type.' . $r['carrier_type'])),
            $col(t('detail.route'), 'text', static fn (array $r) => trim(($r['origin'] ?? '') . ' → ' . ($r['destination'] ?? ''), ' →')),
            $col(t('shipments.eta'), 'date', static fn (array $r) => $r['eta']),
            $col(t('detail.supplier_orders'), 'text', static fn (array $r) => $r['po_references']),
        ];
        $invCols = [
            $col(t('invoices.col.reference'), 'text', static fn (array $r) => $r['reference'] ?? t('invoices.draft_title')),
            $col(t('rfqs.col.client'), 'text', static fn (array $r) => $r['client_name']),
            $col(t('common.status'), 'badge', static fn (array $r) => [InvoiceStatus::from($r['status'])->tone(), t(InvoiceStatus::from($r['status'])->labelKey())]),
            $col(t('invoices.col.issue_date'), 'date', static fn (array $r) => $r['issue_date']),
            $col(t('invoices.col.due_date'), 'date', static fn (array $r) => $r['due_date']),
            $col(t('invoices.col.total'), 'money', static fn (array $r) => (int) $r['total']),
            $col(t('invoices.col.balance'), 'money', static fn (array $r) => (int) $r['balance']),
        ];
        $sinvCols = [
            $col(t('supplier_invoices.col.reference'), 'text', static fn (array $r) => $r['reference']),
            $col(t('sourcing.supplier'), 'text', static fn (array $r) => $r['supplier_name']),
            $col(t('detail.their_number'), 'text', static fn (array $r) => $r['supplier_invoice_number']),
            $col(t('common.status'), 'badge', static fn (array $r) => [SupplierInvoiceStatus::from($r['status'])->tone(), t(SupplierInvoiceStatus::from($r['status'])->labelKey())]),
            $col(t('invoices.col.due_date'), 'date', static fn (array $r) => $r['due_date']),
            $col(t('detail.amount_currency'), 'money', static fn (array $r) => [(int) $r['total_minor'], $r['currency_code']]),
            $col(t('supplier_invoices.col.balance_usd'), 'money', static fn (array $r) => (int) $r['balance_usd']),
        ];

        $trackCols = [
            $col(t('tracking.col.rfq'), 'text', static fn (array $r) => $r['reference']),
            $col(t('rfqs.col.client'), 'text', static fn (array $r) => $r['client_name']),
            $col(t('tracking.col.quotation'), 'text', static fn (array $r) => $r['quotation_reference'] !== null ? $r['quotation_reference'] . ' v' . $r['version'] : ''),
            $col(t('tracking.col.amount'), 'money', static fn (array $r) => $r['quotation_id'] !== null ? (int) $r['quotation_amount'] : 0),
            $col(t('tracking.col.due'), 'datetime', static fn (array $r) => $r['due_at']),
            $col(t('tracking.col.answered_on'), 'date', static fn (array $r) => $r['first_sent_at']),
            $col(t('tracking.col.po'), 'text', static fn (array $r) => $r['order_reference']),
            $col(t('detail.owner'), 'text', static fn (array $r) => $r['owner_name']),
        ];
        $trackLink = static fn (array $r): string => url($r['quotation_id'] !== null ? '/quotations/' . $r['quotation_id'] : '/rfqs/' . $r['id']);
        $link = static fn (string $base): \Closure => static fn (array $r): string => url($base . $r['id']);
        $panel = static fn (string $title, string $icon, string $page, array $columns, array $rows, \Closure $link): array => compact('title', 'icon', 'page', 'columns', 'rows', 'link');

        return match ($key) {
            'rfqs.open' => $panel(t('dashboard.panel.rfqs_open'), 'inbox', url('/rfqs', ['status' => 'open']), $rfqCols, $this->rfqs->list(['status' => 'open']), $link('/rfqs/')),
            'rfqs.late' => $panel(t('dashboard.panel.rfqs_late'), 'clock-alert', url('/rfqs', ['status' => 'late']), $rfqCols, $this->rfqs->list(['status' => 'late']), $link('/rfqs/')),
            'rfqs.due_soon' => $panel(t('dashboard.panel.rfqs_due_soon'), 'hourglass', url('/rfqs', ['status' => 'open']), $rfqCols,
                array_values(array_filter($this->rfqs->list(['status' => 'open']), static fn (array $r): bool => $r['due_at'] !== null && $r['due_at'] >= $now && $r['due_at'] < date('Y-m-d H:i:s', strtotime('+2 days')))), $link('/rfqs/')),
            'quotations.sent' => $panel(t('dashboard.panel.quotations_sent'), 'send', url('/quotations', ['status' => 'sent']), $quoteCols, $this->quotations->list(['status' => 'sent']), $link('/quotations/')),
            'quotations.pending_approval' => $panel(t('dashboard.panel.quotations_pending_approval'), 'shield-alert', url('/quotations', ['status' => 'pending_approval']), $quoteCols, $this->quotations->list(['status' => 'pending_approval']), $link('/quotations/')),
            'quotations.expiring' => $panel(t('dashboard.panel.quotations_expiring'), 'calendar-clock', url('/quotations', ['status' => 'expiring']), $quoteCols, $this->quotations->list(['status' => 'expiring']), $link('/quotations/')),
            'quotations.to_close' => $panel(t('dashboard.panel.quotations_to_close'), 'calendar-x', url('/quotations', ['status' => 'to_close']), $quoteCols, $this->quotations->list(['status' => 'to_close']), $link('/quotations/')),
            'quotations.won' => $panel(t('dashboard.panel.quotations_won'), 'trophy', url('/quotations', ['status' => 'accepted']), $quoteCols,
                array_values(array_filter($this->quotations->list(['status' => 'accepted']), static fn (array $r): bool => substr((string) $r['decided_at'], 0, 4) === date('Y'))), $link('/quotations/')),
            'tracking.awaiting_po' => $panel(t('dashboard.panel.awaiting_po'), 'file-clock', url('/tracking', ['stage' => 'awaiting_po']), [
                $col(t('tracking.col.rfq'), 'text', static fn (array $r) => $r['reference']),
                $col(t('rfqs.col.client'), 'text', static fn (array $r) => $r['client_name']),
                $col(t('tracking.col.quotation'), 'text', static fn (array $r) => $r['quotation_reference'] . ' v' . $r['version']),
                $col(t('tracking.col.amount'), 'money', static fn (array $r) => (int) $r['quotation_amount']),
                $col(t('detail.decided_at'), 'date', static fn (array $r) => $r['decided_at']),
                $col(t('detail.followups'), 'int', static fn (array $r) => $r['followups']),
            ], $this->tracking->rows(['stage' => 'awaiting_po']), static fn (array $r): string => url('/quotations/' . $r['quotation_id'])),
            'client_orders.open' => $panel(t('dashboard.panel.client_orders_open'), 'file-check', url('/client-orders', ['status' => 'open']), $orderCols, $this->clientOrders->list(['status' => 'open']), $link('/client-orders/')),
            'client_orders.to_order' => $panel(t('dashboard.panel.client_orders_to_order'), 'shopping-cart', url('/client-orders', ['status' => 'to_order']), $orderCols, $this->clientOrders->list(['status' => 'to_order']), $link('/client-orders/')),
            'client_orders.to_invoice', 'invoices.to_invoice' => $panel(t('dashboard.panel.client_orders_to_invoice'), 'file-plus', url('/client-orders', ['status' => 'to_invoice']), $orderCols, $this->clientOrders->list(['status' => 'to_invoice']), $link('/client-orders/')),
            'supplier_orders.drafts' => $panel(t('dashboard.panel.supplier_orders_drafts'), 'file-pen', url('/supplier-orders', ['status' => 'draft']), $poCols, $this->supplierOrders->list(['status' => 'draft']), $link('/supplier-orders/')),
            'supplier_orders.open' => $panel(t('dashboard.panel.supplier_orders_open'), 'shopping-cart', url('/supplier-orders', ['status' => 'open']), $poCols, $this->supplierOrders->list(['status' => 'open']), $link('/supplier-orders/')),
            'shipments.open' => $panel(t('dashboard.panel.shipments_open'), 'truck', url('/shipments', ['status' => 'open']), $shipCols, $this->shipments->list(['status' => 'open']), $link('/shipments/')),
            'shipments.late' => $panel(t('dashboard.panel.shipments_late'), 'truck', url('/shipments', ['status' => 'late']), $shipCols, $this->shipments->list(['status' => 'late']), $link('/shipments/')),
            'invoices.open' => $panel(t('dashboard.panel.invoices_open'), 'receipt', url('/invoices', ['status' => 'open']), $invCols, $this->invoices->list(['status' => 'open']), $link('/invoices/')),
            'invoices.overdue' => $panel(t('dashboard.panel.invoices_overdue'), 'receipt', url('/invoices', ['status' => 'overdue']), $invCols, $this->invoices->list(['status' => 'overdue']), $link('/invoices/')),
            'invoices.drafts' => $panel(t('dashboard.panel.invoices_drafts'), 'file-pen', url('/invoices', ['status' => 'draft']), $invCols, $this->invoices->list(['status' => 'draft']), $link('/invoices/')),
            'supplier_invoices.open' => $panel(t('dashboard.panel.supplier_invoices_open'), 'file-input', url('/supplier-invoices', ['status' => 'open']), $sinvCols, $this->supplierInvoices->list(['status' => 'open']), $link('/supplier-invoices/')),
            'supplier_invoices.on_hold' => $panel(t('dashboard.panel.supplier_invoices_on_hold'), 'file-warning', url('/supplier-invoices', ['status' => 'on_hold']), $sinvCols, $this->supplierInvoices->list(['status' => 'on_hold']), $link('/supplier-invoices/')),
            'supplier_invoices.overdue' => $panel(t('dashboard.panel.supplier_invoices_overdue'), 'file-input', url('/supplier-invoices', ['status' => 'overdue']), $sinvCols, $this->supplierInvoices->list(['status' => 'overdue']), $link('/supplier-invoices/')),
            'followups.due' => $panel(t('dashboard.panel.followups_due'), 'phone-call', url('/followups'), [
                $col(t('followups.col.due'), 'date', static fn (array $r) => $r['due_on']),
                $col(t('followups.col.kind'), 'text', static fn (array $r) => t('followups.kind.' . $r['kind'])),
                $col(t('followups.col.document'), 'text', static fn (array $r) => $r['reference']),
                $col(t('followups.col.party'), 'text', static fn (array $r) => $r['party']),
                $col(t('followups.col.round'), 'text', static fn (array $r) => ($r['done'] + 1) . ' / ' . $r['planned']),
                $col(t('followups.col.owner'), 'text', static fn (array $r) => $r['owner_name']),
            ], array_values(array_filter($this->followups->open($this->followupKinds()), static fn (array $f): bool => $f['due_on'] <= $today)),
                static fn (array $r): string => $r['url'] . '#followups'),
            'chart.pipeline' => in_array($param, SalesTrackingService::STAGES, true)
                ? $panel(t('dashboard.chart.pipeline') . ' — ' . t('tracking.stage.' . $param), 'route', url('/tracking', ['stage' => $param]), $trackCols, $this->tracking->rows(['stage' => $param]), $trackLink) : null,
            'chart.shipments' => in_array($param, ['planned', 'picked_up', 'in_transit', 'at_border', 'cleared', 'delivered'], true)
                ? $panel(t('dashboard.chart.shipments') . ' — ' . t('shipment_status.' . $param), 'truck', url('/shipments', ['status' => $param]), $shipCols,
                    array_values(array_filter($this->shipments->list(['status' => $param]), static fn (array $r): bool => $param !== 'delivered' || (string) $r['delivered_at'] >= date('Y-m-d H:i:s', strtotime('-30 days')))), $link('/shipments/')) : null,
            'chart.aging' => in_array($param, Receivables::BUCKETS, true)
                ? $panel(t('dashboard.chart.aging') . ' — ' . t('aging.' . $param), 'hourglass', url('/invoices', ['status' => 'open']), $invCols,
                    array_values(array_filter($this->invoices->list(['status' => 'open']), static fn (array $r): bool => Receivables::bucket((string) $r['due_date'], $today) === $param)), $link('/invoices/')) : null,
            'chart.activity' => $this->activityPanel($param, $panel, $rfqCols, $trackCols, $orderCols, $link, $trackLink),
            default => null,
        };
    }

    /** Barre « série:mois » du graphique d'activité : RFQ reçues, devis envoyés (premier envoi) ou PO clients reçus. */
    private function activityPanel(string $param, \Closure $panel, array $rfqCols, array $trackCols, array $orderCols, \Closure $link, \Closure $trackLink): ?array
    {
        if (!preg_match('/^(rfqs|quotes|orders):(\d{4}-\d{2})$/', $param, $m)) {
            return null;
        }
        [, $series, $month] = $m;
        $label = t('dashboard.series.' . $series) . ' — ' . t('finance.month.' . substr($month, 5, 2)) . ' ' . substr($month, 0, 4);
        $inMonth = static fn (?string $date): bool => $date !== null && substr($date, 0, 7) === $month;
        return match ($series) {
            'rfqs' => $panel($label, 'inbox', url('/rfqs'), $rfqCols, array_values(array_filter($this->rfqs->list(), static fn (array $r): bool => $inMonth($r['received_on']))), $link('/rfqs/')),
            'quotes' => $panel($label, 'send', url('/tracking'), $trackCols, array_values(array_filter($this->tracking->rows(), static fn (array $r): bool => $inMonth($r['first_sent_at']))), $trackLink),
            'orders' => can('client_orders.view')
                ? $panel($label, 'file-check', url('/client-orders'), $orderCols, array_values(array_filter($this->clientOrders->list(), static fn (array $r): bool => $r['status'] !== 'cancelled' && $inMonth($r['received_on']))), $link('/client-orders/'))
                : null,
        };
    }

    /** @return list<string> types de relance visibles par l'utilisateur */
    private function followupKinds(): array
    {
        return array_values(array_filter(FollowupService::KINDS, static fn (string $k): bool => can(FollowupService::PERMS[$k][0])));
    }
}
