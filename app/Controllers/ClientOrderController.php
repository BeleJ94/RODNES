<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Xlsx;
use App\Core\Validator;
use App\Domain\Orders\ClientOrderStatus;
use App\Domain\Sales\QuotationStatus;
use App\Domain\Shared\Fx;
use App\Domain\Shared\RuleViolation;
use App\Repositories\ClientOrderRepository;
use App\Repositories\QuotationRepository;
use App\Repositories\RfqRepository;
use App\Repositories\ShipmentRepository;
use App\Repositories\SupplierOrderRepository;
use App\Services\ActivityLogger;
use App\Services\ClientOrderService;

final class ClientOrderController extends Controller
{
    private const HEADER_RULES = [
        'client_po_number' => 'required|max:64',
        'received_on' => 'required|date',
        'required_by' => 'nullable|date',
        'delivery_site' => 'nullable|max:160',
        'incoterm' => 'nullable|in:EXW,FCA,FOB,CFR,CIF,CPT,CIP,DAP,DPU,DDP',
        'payment_terms_days' => 'required|int|between:0,365',
        'vat_applicable' => 'bool',
        'notes' => 'nullable|max:2000',
    ];

    public function __construct(
        private ClientOrderRepository $orders,
        private ClientOrderService $service,
        private QuotationRepository $quotations,
        private RfqRepository $rfqs,
        private SupplierOrderRepository $supplierOrders,
        private ActivityLogger $logger,
        private ShipmentRepository $shipments,
        private \App\Repositories\InvoiceRepository $invoices,
        private \App\Services\InvoiceService $invoicing,
        private \App\Services\MarginService $margins,
    ) {
    }

    public function index(Request $request): Response
    {
        if ($request->str('format') === 'xlsx') {
            return $this->export($this->orders->list(['status' => $request->str('status')]));
        }
        $status = $request->str('status');
        return $this->view('client_orders/index', [
            'title' => t('client_orders.title'),
            'rows' => $this->orders->list(['status' => $status]),
            'status' => $status,
            'kpis' => $this->orders->kpis(),
        ]);
    }

    public function create(Request $request, string $quotationId): Response
    {
        $quotation = $this->quotations->get((int) $quotationId);
        if (!in_array(QuotationStatus::from((string) $quotation['status']), [QuotationStatus::Sent, QuotationStatus::Accepted], true)) {
            return $this->redirect('/quotations/' . $quotationId, 'client_orders.error_quotation_status', 'error');
        }
        $rfq = $this->rfqs->get((int) $quotation['rfq_id']);
        return $this->view('client_orders/form', [
            'title' => t('client_orders.new_title', ['ref' => $quotation['reference'] . ' v' . $quotation['version']]),
            'order' => [
                'client_po_number' => '', 'received_on' => date('Y-m-d'), 'delivery_site' => $rfq['delivery_site'],
                'incoterm' => $quotation['incoterm'], 'payment_terms_days' => $quotation['payment_terms_days'], 'vat_applicable' => $quotation['vat_applicable'],
            ],
            'quotation' => $quotation,
            'rfq' => $rfq,
            'files' => [],
            'lines' => array_map(static fn (array $l): array => $l + ['include' => true], $this->quotations->lines((int) $quotationId)),
            'creating' => true,
            'action' => url('/quotations/' . $quotationId . '/client-order'),
            'cancel' => url('/quotations/' . $quotationId),
        ]);
    }

    public function store(Request $request, string $quotationId): Response
    {
        $this->quotations->get((int) $quotationId);
        $back = '/quotations/' . $quotationId . '/client-order/new';
        [$header, $lines, $errors] = $this->parse($request, true);
        $uploads = self::uploadedFiles($request, 'po_files');
        if ($uploads === []) {
            $errors['po_files'] = t('client_orders.error_po_file_required');
        }
        if ($errors !== []) {
            return $this->invalid($back, $request, $errors);
        }
        try {
            $id = $this->service->createFromQuotation((int) $quotationId, $header, $lines, $uploads);
        } catch (RuleViolation $e) {
            return $this->violated($back, $request, $e);
        }
        return $this->redirect('/client-orders/' . $id, 'client_orders.flash_created');
    }

    public function show(Request $request, string $id): Response
    {
        $order = $this->orders->get((int) $id);
        $allocations = [];
        foreach ($this->supplierOrders->allocationsForClientOrder((int) $id) as $a) {
            $allocations[(int) $a['client_order_line_id']][] = $a + [
                'unit_usd_minor' => Fx::toUsd((int) $a['unit_price_minor'], (int) $a['fx_units_per_usd_micro']),
            ];
        }
        return $this->view('client_orders/show', [
            'title' => (string) $order['reference'],
            'order' => $order,
            'status' => ClientOrderStatus::from((string) $order['status']),
            'lines' => $this->orders->lines((int) $id),
            'files' => $this->orders->files((int) $id),
            'allocations' => $allocations,
            'supplierOrders' => $this->supplierOrders->forClientOrder((int) $id),
            'shipments' => $this->shipments->forClientOrder((int) $id),
            'invoices' => $this->invoices->forClientOrder((int) $id),
            'margin' => can('reports.view') ? $this->margins->forClientOrder((int) $id) : null,
            'toInvoice' => array_sum(array_column($this->invoicing->invoiceableLines((int) $id), 'invoiceable_milli')) > 0,
            'history' => $this->logger->history('client_order', (int) $id),
            'reopenInvoice' => $request->str('invoice') === '1' && can('invoices.manage')
                ? app(InvoiceController::class)->createData((int) $id) + ['modal' => true]
                : null,
            'reopenShipment' => $request->str('shipment') === '1' && can('shipments.manage')
                ? ['modal' => true] + app(ShipmentController::class)->formData((int) $order['client_id'], null, '/client-orders/' . $id)
                : null,
        ]);
    }

    public function edit(Request $request, string $id): Response
    {
        $order = $this->orders->get((int) $id);
        if (ClientOrderStatus::from((string) $order['status'])->isFinal()) {
            return $this->redirect('/client-orders/' . $id, 'client_orders.error_final', 'error');
        }
        return $this->view('client_orders/form', [
            'title' => t('client_orders.edit_title', ['ref' => $order['reference']]),
            'order' => $order,
            'quotation' => $order['quotation_id'] ? $this->quotations->get((int) $order['quotation_id']) : null,
            'rfq' => $order['rfq_id'] ? $this->rfqs->get((int) $order['rfq_id']) : null,
            'files' => $this->orders->files((int) $id),
            'lines' => $this->orders->lines((int) $id),
            'creating' => false,
            'action' => url('/client-orders/' . $id),
            'cancel' => url('/client-orders/' . $id),
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $this->orders->get((int) $id);
        $back = '/client-orders/' . $id . '/edit';
        [$header, $lines, $errors] = $this->parse($request, false);
        if ($errors !== []) {
            return $this->invalid($back, $request, $errors);
        }
        try {
            $this->service->update((int) $id, $header, $lines, self::uploadedFiles($request, 'po_files'));
        } catch (RuleViolation $e) {
            return $this->violated($back, $request, $e);
        }
        return $this->redirect('/client-orders/' . $id, 'common.flash_saved');
    }

    public function finish(Request $request, string $id): Response
    {
        $to = ClientOrderStatus::tryFrom($request->str('status')) ?? ClientOrderStatus::Received;
        try {
            $this->service->finish((int) $id, $to, mb_substr($request->str('reason'), 0, 255) ?: null);
        } catch (RuleViolation $e) {
            return $this->refused('/client-orders/' . $id, $e);
        }
        return $this->redirect('/client-orders/' . $id, 'client_orders.flash_' . $to->value);
    }

    /**
     * Lignes : à la création, clé = ligne de quotation et case « include » ; en modification, clé = ligne du PO client.
     *
     * @return array{0: array<string, mixed>, 1: array<int, array{quantity_milli: int, unit_price_minor: int, description: string}>, 2: array<string, string>}
     */
    private function parse(Request $request, bool $creating): array
    {
        $validator = Validator::make($request->body(), self::HEADER_RULES);
        $errors = $validator->errors();
        $lines = [];
        foreach ($request->rows('lines') as $key => $row) {
            if ($creating && ($row['include'] ?? '') !== '1') {
                continue;
            }
            $v = Validator::make($row, ['description' => 'required|max:500', 'quantity' => 'required|qty', 'price' => 'required|money']);
            if (!$v->passes()) {
                $errors['lines'] = t('client_orders.line_error', ['error' => implode(' ', $v->errors())]);
                continue;
            }
            $d = $v->validated();
            $lines[(int) $key] = ['description' => $d['description'], 'quantity_milli' => $d['quantity'], 'unit_price_minor' => $d['price']];
        }
        if ($creating && $lines === [] && !isset($errors['lines'])) {
            $errors['lines'] = t('client_orders.error_no_lines');
        }
        return [$validator->validated(), $lines, $errors];
    }

    /** @param list<array<string, mixed>> $rows */
    private function export(array $rows): Response
    {
        return $this->xlsx(t('nav.client_orders'), [
            ['label' => t('export.col.reference')], ['label' => t('export.col.client_po')], ['label' => t('export.col.client'), 'width' => 28],
            ['label' => t('export.col.quotation')], ['label' => t('export.col.status')], ['label' => t('export.col.received_on'), 'type' => Xlsx::DATE],
            ['label' => t('export.col.required_by'), 'type' => Xlsx::DATE], ['label' => t('export.col.site')], ['label' => t('export.col.incoterm')],
            ['label' => t('export.col.subtotal_usd'), 'type' => Xlsx::MONEY], ['label' => t('export.col.coverage'), 'type' => Xlsx::PERCENT],
        ], array_map(static fn (array $o): array => [
            $o['reference'], $o['client_po_number'], $o['client_name'], $o['quotation_reference'], t(ClientOrderStatus::from($o['status'])->labelKey()),
            $o['received_on'], $o['required_by'], $o['delivery_site'], $o['incoterm'], $o['subtotal'],
            (int) $o['ordered_milli'] > 0 ? intdiv((int) $o['covered_milli'] * 10000, (int) $o['ordered_milli']) : null,
        ], $rows));
    }
}
