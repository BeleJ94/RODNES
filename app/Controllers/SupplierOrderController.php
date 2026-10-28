<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Xlsx;
use App\Core\Validator;
use App\Domain\Orders\SupplierOrderStatus;
use App\Domain\Shared\Fx;
use App\Domain\Shared\Money;
use App\Domain\Shared\RuleViolation;
use App\Repositories\ClientOrderRepository;
use App\Repositories\CurrencyRepository;
use App\Repositories\SupplierInvoiceRepository;
use App\Repositories\SupplierOrderRepository;
use App\Repositories\SupplierQuoteRepository;
use App\Repositories\SupplierRepository;
use App\Security\SecurityHeaders;
use App\Services\ActivityLogger;
use App\Services\Settings;
use App\Services\FollowupService;
use App\Services\SupplierOrderService;

final class SupplierOrderController extends Controller
{
    public function __construct(
        private SupplierOrderRepository $orders,
        private SupplierOrderService $service,
        private ClientOrderRepository $clientOrders,
        private SupplierRepository $suppliers,
        private SupplierQuoteRepository $offers,
        private CurrencyRepository $currencies,
        private Settings $settings,
        private ActivityLogger $logger,
        private SupplierInvoiceRepository $supplierInvoices,
        private FollowupService $followups,
    ) {
    }

    public function index(Request $request): Response
    {
        if ($request->str('format') === 'xlsx') {
            return $this->export($this->orders->list(['status' => $request->str('status')]));
        }
        $status = $request->str('status');
        return $this->view('supplier_orders/index', [
            'title' => t('supplier_orders.title'),
            'rows' => $this->orders->list(['status' => $status]),
            'status' => $status,
            'kpis' => $this->orders->kpis(),
        ]);
    }

    /** Brouillons automatiques pour tout le reste à commander (fournisseurs retenus en sourcing). */
    public function prepare(Request $request, string $clientOrderId): Response
    {
        try {
            $result = $this->service->prepareFromClientOrder((int) $clientOrderId);
        } catch (RuleViolation $e) {
            return $this->refused('/client-orders/' . $clientOrderId, $e);
        }
        $key = $result['created'] === [] ? 'supplier_orders.flash_prepared_none' : 'supplier_orders.flash_prepared';
        app(\App\Core\Session::class)->flash($result['created'] === [] ? 'info' : 'success', [
            'key' => $key, 'params' => ['n' => count($result['created']), 'skipped' => $result['skipped']],
        ]);
        return Response::redirect(url('/client-orders/' . $clientOrderId . '#supplier-orders'));
    }

    /** Saisie manuelle : choix du fournisseur (rechargement), puis quantités et prix des lignes restant à commander. */
    public function create(Request $request): Response
    {
        if ($request->int('client_order_id') === null) {
            return $this->redirect('/client-orders', 'supplier_orders.choose_client_order', 'info');
        }
        $clientOrder = $this->clientOrders->get((int) $request->int('client_order_id'));
        $supplierId = $request->int('supplier_id');
        $supplier = $supplierId !== null ? $this->suppliers->find($supplierId) : null;
        $lines = [];
        foreach ($this->clientOrders->lines((int) $clientOrder['id']) as $line) {
            $remaining = (int) $line['quantity_milli'] - (int) $line['allocated_milli'];
            if ($remaining <= 0) {
                continue;
            }
            $offer = $supplier !== null && $line['rfq_line_id'] !== null ? $this->offers->offerFor((int) $supplier['id'], (int) $line['rfq_line_id']) : null;
            $lines[] = $line + ['remaining_milli' => $remaining, 'offer' => $offer];
        }
        return $this->view('supplier_orders/form', [
            'title' => t('supplier_orders.new_title', ['ref' => $clientOrder['reference']]),
            'clientOrder' => $clientOrder,
            'supplier' => $supplier,
            'suppliers' => $this->suppliers->options(),
            'currencies' => $this->currencies->activeCodes(),
            'lines' => $lines,
            'order' => null,
        ]);
    }

    public function store(Request $request): Response
    {
        $clientOrderId = (int) $request->int('client_order_id', 0);
        $supplierId = (int) $request->int('supplier_id', 0);
        $back = '/supplier-orders/new?' . http_build_query(['client_order_id' => $clientOrderId, 'supplier_id' => $supplierId]);
        [$header, $errors] = $this->header($request);
        $lines = [];
        foreach ($request->rows('lines') as $clientLineId => $row) {
            if (($row['quantity'] ?? '') === '' || ($row['quantity'] ?? '') === '0') {
                continue;
            }
            $v = Validator::make($row, ['quantity' => 'required|qty', 'price' => 'required|money']);
            if (!$v->passes()) {
                $errors['lines'] = t('client_orders.line_error', ['error' => implode(' ', $v->errors())]);
                continue;
            }
            $lines[(int) $clientLineId] = ['quantity_milli' => $v->validated()['quantity'], 'unit_price_minor' => $v->validated()['price']];
        }
        if ($errors !== []) {
            return $this->invalid($back, $request, $errors);
        }
        try {
            $id = $this->service->create($clientOrderId, $supplierId, $header, $lines);
        } catch (RuleViolation $e) {
            return $this->violated($back, $request, $e);
        }
        return $this->redirect('/supplier-orders/' . $id, 'supplier_orders.flash_created');
    }

    public function show(Request $request, string $id): Response
    {
        $order = $this->orders->get((int) $id);
        return $this->view('supplier_orders/show', [
            'title' => (string) $order['reference'],
            'order' => $order,
            'status' => SupplierOrderStatus::from((string) $order['status']),
            'lines' => $this->orders->lines((int) $id),
            'totalUsd' => Fx::toUsd((int) $order['total'], (int) $order['fx_units_per_usd_micro']),
            'supplierInvoices' => $this->supplierInvoices->forSupplierOrder((int) $id),
            'history' => $this->logger->history('supplier_order', (int) $id),
            'followupCards' => $this->followupCards((string) $order['status'], (int) $id),
            'reopenInvoice' => $request->str('invoice') === '1' && can('supplier_invoices.manage')
                ? app(SupplierInvoiceController::class)->formData((int) $id) + ['modal' => true]
                : null,
            'reopenShipment' => $request->str('shipment') === '1' && can('shipments.manage')
                ? ['modal' => true] + app(ShipmentController::class)->formData((int) $order['client_id'], (int) $id, '/supplier-orders/' . $id)
                : null,
        ]);
    }

    /** @return list<array<string, mixed>> encarts de relance : confirmation attendue, puis mise à disposition à vérifier */
    private function followupCards(string $status, int $id): array
    {
        $cards = [];
        foreach (['supplier_confirm' => 'sent', 'supplier_ready' => 'confirmed'] as $kind => $active) {
            $history = $this->followups->history($kind, $id);
            $next = $status === $active ? $this->followups->nextFor($kind, $id) : null;
            if ($next !== null || $history !== []) {
                $cards[] = ['kind' => $kind, 'subjectId' => $id, 'next' => $next, 'history' => $history, 'canManage' => can('supplier_orders.manage')];
            }
        }
        return $cards;
    }

    public function edit(Request $request, string $id): Response
    {
        $order = $this->orders->get((int) $id);
        if (!SupplierOrderStatus::from((string) $order['status'])->isEditable()) {
            return $this->redirect('/supplier-orders/' . $id, 'supplier_orders.error_not_draft', 'error');
        }
        return $this->view('supplier_orders/form', [
            'title' => t('supplier_orders.edit_title', ['ref' => $order['reference']]),
            'clientOrder' => $this->clientOrders->get((int) $order['client_order_id']),
            'supplier' => $this->suppliers->get((int) $order['supplier_id']),
            'suppliers' => [],
            'currencies' => $this->currencies->activeCodes(),
            'lines' => $this->orders->lines((int) $id),
            'order' => $order,
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $this->orders->get((int) $id);
        $back = '/supplier-orders/' . $id . '/edit';
        [$header, $errors] = $this->header($request);
        $lines = [];
        foreach ($request->rows('lines') as $lineId => $row) {
            if (($row['quantity'] ?? '') === '0') {
                $lines[(int) $lineId] = ['quantity_milli' => 0, 'unit_price_minor' => 0];
                continue;
            }
            $v = Validator::make($row, ['quantity' => 'required|qty', 'price' => 'required|money']);
            if (!$v->passes()) {
                $errors['lines'] = t('client_orders.line_error', ['error' => implode(' ', $v->errors())]);
                continue;
            }
            $lines[(int) $lineId] = ['quantity_milli' => $v->validated()['quantity'], 'unit_price_minor' => $v->validated()['price']];
        }
        if ($errors !== []) {
            return $this->invalid($back, $request, $errors);
        }
        try {
            $this->service->update((int) $id, $header, $lines);
        } catch (RuleViolation $e) {
            return $this->violated($back, $request, $e);
        }
        return $this->redirect('/supplier-orders/' . $id, 'common.flash_saved');
    }

    public function transition(Request $request, string $id): Response
    {
        $action = $request->str('action');
        try {
            $message = $this->service->transition(
                (int) $id,
                in_array($action, SupplierOrderService::ACTIONS, true) ? $action : '',
                mb_substr($request->str('note'), 0, 255) ?: null,
            );
        } catch (RuleViolation $e) {
            return $this->refused('/supplier-orders/' . $id, $e);
        }
        return $this->redirect('/supplier-orders/' . $id, $message);
    }

    public function print(Request $request, string $id): Response
    {
        $order = $this->orders->get((int) $id);
        return SecurityHeaders::framableBySelf($this->view('supplier_orders/print', [
            'title' => (string) $order['reference'],
            'order' => $order,
            'supplier' => $this->suppliers->get((int) $order['supplier_id']),
            'lines' => $this->orders->lines((int) $id),
            's' => $this->settings->all(),
            'back' => url('/supplier-orders/' . $id),
            'embed' => $request->str('embed') === '1',
        ], 'print'));
    }

    /** @return array{0: array<string, mixed>, 1: array<string, string>} */
    private function header(Request $request): array
    {
        $validator = Validator::make($request->body(), [
            'currency_code' => 'required|currency',
            'incoterm' => 'nullable|in:' . implode(',', SourcingController::INCOTERMS),
            'expected_ready_on' => 'nullable|date',
            'delivery_address' => 'nullable|max:255',
            'payment_terms_days' => 'nullable|int|between:0,365',
            'notes' => 'nullable|max:2000',
        ]);
        $header = $validator->validated();
        $errors = $validator->errors();
        if ($validator->passes() && $this->currencies->rateFor($header['currency_code']) === null) {
            $errors['currency_code'] = t('sourcing.error_no_rate');
        }
        $header['payment_terms_days'] ??= 30;
        return [$header, $errors];
    }

    /** Total d'une ligne fournisseur (utilisé par les vues). */
    public static function lineTotal(array $line): int
    {
        return Money::lineAmount((int) $line['quantity_milli'], (int) $line['unit_price_minor']);
    }

    /** @param list<array<string, mixed>> $rows */
    private function export(array $rows): Response
    {
        return $this->xlsx(t('nav.supplier_orders'), [
            ['label' => t('export.col.reference')], ['label' => t('export.col.supplier'), 'width' => 28], ['label' => t('export.col.supplier_reference')],
            ['label' => t('export.col.client_order')], ['label' => t('export.col.client'), 'width' => 28], ['label' => t('export.col.status')],
            ['label' => t('export.col.issued_on'), 'type' => Xlsx::DATE], ['label' => t('export.col.ready_on'), 'type' => Xlsx::DATE], ['label' => t('export.col.incoterm')],
            ['label' => t('export.col.currency')], ['label' => t('export.col.amount'), 'type' => Xlsx::MONEY, 'total' => false], ['label' => t('export.col.rate'), 'type' => Xlsx::RATE],
            ['label' => t('export.col.amount_usd'), 'type' => Xlsx::MONEY],
        ], array_map(static fn (array $o): array => [
            $o['reference'], $o['supplier_name'], $o['supplier_reference'], $o['client_order_reference'], $o['client_name'],
            t(SupplierOrderStatus::from($o['status'])->labelKey()), $o['issued_on'], $o['expected_ready_on'], $o['incoterm'], $o['currency_code'],
            $o['total'], $o['fx_units_per_usd_micro'], Fx::toUsd((int) $o['total'], (int) $o['fx_units_per_usd_micro']),
        ], $rows));
    }
}
