<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ClientOrderRepository;
use App\Repositories\CurrencyRepository;
use App\Repositories\InvoiceRepository;
use App\Repositories\ShipmentRepository;
use App\Repositories\SupplierInvoiceRepository;
use App\Repositories\SupplierOrderRepository;
use App\Repositories\QuotationRepository;
use App\Repositories\RfqRepository;
use App\Repositories\SupplierQuoteRepository;
use App\Security\Auth;

/**
 * Source unique des alertes : cloche, pastilles du menu, tableau de bord (et plus tard l'e-mail récapitulatif).
 * Chaque alerte est actionnable : elle mène à la liste filtrée correspondante.
 */
final class AlertService
{
    /** @var list<array{key: string, level: string, icon: string, count: int, label: string, url: string, nav: string}>|null */
    private ?array $cache = null;

    public function __construct(
        private Auth $auth,
        private RfqRepository $rfqs,
        private SupplierQuoteRepository $offers,
        private QuotationRepository $quotations,
        private CurrencyRepository $currencies,
        private Settings $settings,
        private ClientOrderRepository $clientOrders,
        private SupplierOrderRepository $supplierOrders,
        private ShipmentRepository $shipments,
        private InvoiceRepository $invoices,
        private SupplierInvoiceRepository $supplierInvoices,
        private FollowupService $followups,
    ) {
    }

    /** @return list<array{key: string, level: string, icon: string, count: int, label: string, url: string, nav: string}> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $user = $this->auth->user();
        if ($user === null) {
            return $this->cache = [];
        }
        $alerts = [];
        if ($user->can('rfqs.view')) {
            $rfq = $this->rfqs->kpis();
            $alerts[] = $this->make('rfqs.late', 'bad', 'clock-alert', $rfq['late'], '/rfqs', ['status' => 'late']);
            $alerts[] = $this->make('rfqs.due_soon', 'warn', 'hourglass', $rfq['due_soon'], '/rfqs', ['status' => 'open']);
        }
        if ($user->can('sourcing.manage')) {
            $days = $this->settings->int('sourcing_reminder_days');
            $alerts[] = $this->make('sourcing.pending', 'warn', 'mail-question', $this->offers->pendingCount($days), '/rfqs', ['status' => 'sourcing'], ['d' => $days]);
        }
        if ($user->can('quotations.view')) {
            $q = $this->quotations->kpis();
            if ($user->can('quotations.approve')) {
                $alerts[] = $this->make('quotations.pending_approval', 'bad', 'shield-alert', $q['pending_approval'], '/quotations', ['status' => 'pending_approval']);
            }
            $alerts[] = $this->make('quotations.expiring', 'warn', 'calendar-clock', $q['expiring'], '/quotations', ['status' => 'expiring']);
            $alerts[] = $this->make('quotations.to_close', 'warn', 'calendar-x', $q['to_close'], '/quotations', ['status' => 'to_close']);
        }
        if ($user->can('supplier_orders.view')) {
            $co = $this->clientOrders->kpis();
            $alerts[] = $this->make('client_orders.to_order', 'bad', 'shopping-cart', $co['late_to_order'], '/client-orders', ['status' => 'to_order']);
            $so = $this->supplierOrders->kpis();
            $alerts[] = $this->make('supplier_orders.drafts', 'warn', 'file-pen', $so['draft'], '/supplier-orders', ['status' => 'draft']);
        }
        if ($user->can('shipments.view')) {
            $sh = $this->shipments->kpis();
            $alerts[] = $this->make('shipments.late', 'bad', 'truck', $sh['late'], '/shipments', ['status' => 'late']);
            $alerts[] = $this->make('shipments.pod_pending', 'warn', 'file-signature', $sh['pod_pending'], '/shipments', ['status' => 'pod_pending']);
            $alerts[] = $this->make('shipments.docs_missing', 'warn', 'folder-x', $sh['docs_missing'], '/shipments', ['status' => 'docs_missing']);
            $alerts[] = $this->make('shipments.pickup_overdue', 'warn', 'package-open', $this->shipments->pickupOverdueCount(), '/supplier-orders', ['status' => 'confirmed']);
        }
        if ($user->can('invoices.view')) {
            $inv = $this->invoices->kpis();
            $alerts[] = $this->make('invoices.overdue', 'bad', 'receipt', $inv['overdue_count'], '/invoices', ['status' => 'overdue']);
            $alerts[] = $this->make('invoices.drafts', 'warn', 'file-pen', $inv['drafts'], '/invoices', ['status' => 'draft']);
            $alerts[] = $this->make('invoices.to_invoice', 'warn', 'file-plus', $this->invoices->ordersToInvoiceCount(), '/client-orders', ['status' => 'to_invoice']);
        }
        if ($user->can('supplier_invoices.view')) {
            $si = $this->supplierInvoices->kpis();
            $alerts[] = $this->make('supplier_invoices.on_hold', 'warn', 'file-warning', $si['on_hold'], '/supplier-invoices', ['status' => 'on_hold']);
            $alerts[] = $this->make('supplier_invoices.overdue', 'bad', 'file-input', $si['overdue_count'], '/supplier-invoices', ['status' => 'overdue']);
        }
        $kinds = array_values(array_filter(FollowupService::KINDS, static fn (string $k): bool => $user->can(FollowupService::PERMS[$k][0])));
        if ($kinds !== []) {
            $alerts[] = $this->make('followups.due', 'warn', 'phone-call', $this->followups->dueCount($kinds), '/followups', []);
        }
        if ($user->can('settings.currencies')) {
            $stale = 0;
            foreach ($this->currencies->latestRates() as $code => $rate) {
                if ($code !== 'USD' && ($rate['date'] === null || $rate['date'] < date('Y-m-d', strtotime('-7 days')))) {
                    $stale++;
                }
            }
            $alerts[] = $this->make('currencies.stale', 'warn', 'coins', $stale, '/settings/currencies', []);
        }

        $alerts = array_values(array_filter($alerts, static fn (array $a): bool => $a['count'] > 0));
        usort($alerts, static fn (array $a, array $b): int => [$a['level'] !== 'bad', -$a['count']] <=> [$b['level'] !== 'bad', -$b['count']]);
        return $this->cache = $alerts;
    }

    public function total(): int
    {
        return array_sum(array_column($this->all(), 'count'));
    }

    /** @return array<string, int> chemin de menu → total des alertes */
    public function countsByNav(): array
    {
        $counts = [];
        foreach ($this->all() as $alert) {
            $counts[$alert['nav']] = ($counts[$alert['nav']] ?? 0) + $alert['count'];
        }
        return $counts;
    }

    /**
     * @param array<string, string> $filters
     * @param array<string, scalar> $params
     * @return array{key: string, level: string, icon: string, count: int, label: string, url: string, nav: string}
     */
    private function make(string $key, string $level, string $icon, int $count, string $path, array $filters, array $params = []): array
    {
        return [
            'key' => $key,
            'level' => $level,
            'icon' => $icon,
            'count' => $count,
            'label' => t('alerts.' . $key, ['n' => $count] + $params),
            'url' => url($path, $filters),
            'nav' => $path,
        ];
    }
}
