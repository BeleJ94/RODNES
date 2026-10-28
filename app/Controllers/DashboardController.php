<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\ClientOrderRepository;
use App\Repositories\InvoiceRepository;
use App\Repositories\QuotationRepository;
use App\Repositories\RfqRepository;
use App\Repositories\ShipmentRepository;
use App\Repositories\SupplierInvoiceRepository;
use App\Services\AlertService;
use App\Services\DashboardPanels;
use App\Services\DashboardService;
use App\Services\SalesTrackingService;

/**
 * Tableau de bord : indicateurs (commercial, exécution, finance), graphiques légers, notifications.
 * Chaque indicateur ou notification ouvre son détail dans une fenêtre (panel), sans quitter la page.
 */
final class DashboardController extends Controller
{
    public function __construct(
        private RfqRepository $rfqs,
        private QuotationRepository $quotations,
        private AlertService $alerts,
        private ClientOrderRepository $clientOrders,
        private ShipmentRepository $shipments,
        private InvoiceRepository $invoices,
        private SupplierInvoiceRepository $supplierInvoices,
        private SalesTrackingService $tracking,
        private DashboardService $dashboard,
        private DashboardPanels $panels,
    ) {
    }

    public function index(Request $request): Response
    {
        $kpis = [];
        $charts = [];
        if (can('rfqs.view')) {
            $rfq = $this->rfqs->kpis();
            $summary = $this->tracking->summary($this->tracking->rows());
            $decided = $rfq['won_year'] + $rfq['lost_year'];
            $kpis['sales'][] = ['label' => t('dashboard.kpi.open_rfqs'), 'value' => (string) $rfq['open'], 'hint' => t('dashboard.kpi.open_rfqs_hint2', ['late' => $rfq['late']]),
                'panel' => $rfq['late'] > 0 ? 'rfqs.late' : 'rfqs.open', 'tone' => $rfq['late'] > 0 ? 'alert' : ($rfq['open'] > 0 ? 'warn' : ''), 'icon' => 'inbox'];
            $kpis['sales'][] = ['label' => t('dashboard.kpi.awaiting_po'), 'value' => format_money($summary['stages']['awaiting_po']['amount'], 'USD'), 'hint' => t('dashboard.kpi.awaiting_po_hint', ['n' => $summary['stages']['awaiting_po']['n']]),
                'panel' => 'tracking.awaiting_po', 'tone' => $summary['stages']['awaiting_po']['n'] > 0 ? 'warn' : '', 'icon' => 'file-clock'];
            $kpis['sales'][] = ['label' => t('dashboard.kpi.win_rate'), 'value' => format_percent($decided > 0 ? intdiv($rfq['won_year'] * 10000, $decided) : null), 'hint' => t('dashboard.kpi.win_rate_hint', ['won' => $rfq['won_year'], 'lost' => $rfq['lost_year']]),
                'panel' => 'quotations.won', 'tone' => '', 'icon' => 'trophy'];
            $charts['pipeline'] = $summary['stages'];
            $charts['activity'] = $this->dashboard->activity();
        }
        if (can('quotations.view')) {
            $q = $this->quotations->kpis();
            array_splice($kpis['sales'], 1, 0, [['label' => t('dashboard.kpi.sent'), 'value' => format_money($q['sent_amount'], 'USD'), 'hint' => t('dashboard.kpi.sent_hint2', ['n' => $q['sent'], 'expiring' => $q['expiring'] + $q['to_close']]),
                'panel' => 'quotations.sent', 'tone' => $q['to_close'] > 0 ? 'warn' : '', 'icon' => 'send']]);
        }
        if (can('client_orders.view')) {
            $o = $this->clientOrders->kpis();
            $kpis['ops'][] = ['label' => t('dashboard.kpi.orders'), 'value' => format_money($o['open_amount'], 'USD'), 'hint' => t('dashboard.kpi.to_order_hint2', ['n' => $o['open'], 'to_order' => $o['to_order']]),
                'panel' => $o['to_order'] > 0 ? 'client_orders.to_order' : 'client_orders.open', 'tone' => $o['late_to_order'] > 0 ? 'alert' : ($o['to_order'] > 0 ? 'warn' : ''), 'icon' => 'file-check'];
        }
        if (can('shipments.view')) {
            $s = $this->shipments->kpis();
            $kpis['ops'][] = ['label' => t('dashboard.kpi.shipments'), 'value' => (string) $s['open'], 'hint' => t('dashboard.kpi.shipments_hint', ['late' => $s['late'], 'border' => $s['at_border']]),
                'panel' => $s['late'] > 0 ? 'shipments.late' : 'shipments.open', 'tone' => $s['late'] > 0 ? 'alert' : '', 'icon' => 'truck'];
            $charts['shipments'] = $this->dashboard->shipmentStages();
        }
        if (can('invoices.view')) {
            $i = $this->invoices->kpis();
            $kpis['finance'][] = ['label' => t('dashboard.kpi.receivables'), 'value' => format_money($i['outstanding'], 'USD'), 'hint' => t('dashboard.kpi.receivables_hint', ['n' => $i['open_count'], 'overdue' => format_money($i['overdue'], 'USD')]),
                'panel' => $i['overdue'] > 0 ? 'invoices.overdue' : 'invoices.open', 'tone' => $i['overdue'] > 0 ? 'alert' : '', 'icon' => 'receipt'];
            $charts['aging'] = $this->dashboard->aging($this->invoices->list(['status' => 'open']));
        }
        if (can('supplier_invoices.view')) {
            $si = $this->supplierInvoices->kpis();
            $kpis['finance'][] = ['label' => t('dashboard.kpi.payables'), 'value' => format_money($si['open_usd'], 'USD'), 'hint' => t('dashboard.kpi.payables_hint', ['overdue' => format_money($si['overdue_usd'], 'USD'), 'soon' => format_money($si['due_soon_usd'], 'USD')]),
                'panel' => $si['overdue_usd'] > 0 ? 'supplier_invoices.overdue' : 'supplier_invoices.open', 'tone' => $si['overdue_usd'] > 0 ? 'alert' : '', 'icon' => 'file-input'];
        }
        // Notifications : le détail s'ouvre dans la fenêtre quand une liste existe, sinon lien vers la page.
        $alerts = array_map(fn (array $a): array => $a + ['panel' => isset(DashboardPanels::PERMS[$a['key']]) && can(DashboardPanels::PERMS[$a['key']]) ? $a['key'] : null], $this->alerts->all());

        return $this->view('dashboard/index', [
            'title' => t('dashboard.title'),
            'kpis' => $kpis,
            'charts' => $charts,
            'alerts' => $alerts,
        ]);
    }

    /** Détail d'un indicateur ou d'une notification : fragment HTML chargé dans la fenêtre. */
    public function panel(Request $request, string $key): Response
    {
        if (!isset(DashboardPanels::PERMS[$key])) {
            throw new HttpException(404);
        }
        if (!can(DashboardPanels::PERMS[$key])) {
            throw new HttpException(403);
        }
        $panel = $this->panels->get($key, mb_substr($request->str('p'), 0, 40)) ?? throw new HttpException(404);
        return $this->view('dashboard/_panel', ['panel' => $panel], null)->withHeader('Cache-Control', 'no-store');
    }
}
